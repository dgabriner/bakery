<?php
/**
 * Delivery review: two weeks of drivers, photos, and standing versus actual.
 *
 * Usage: php tests/run_delivery_manager_review_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$pageFile = $root . '/delivery_manager_review.php';
$includeFile = $root . '/includes/delivery_manager_review.php';
if (!is_file($pageFile) || !is_file($includeFile)) {
    echo "FAIL  delivery review page and include exist\n";
    echo "1 FAILED\n";
    exit(1);
}

require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $includeFile;
require_once $root . '/includes/navigation_catalog.php';
require_once $root . '/includes/admin_workspace_mode.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Standing versus what happened ===\n";
$quiet = ['expected' => 4, 'routed' => 0, 'delivered' => 0, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$quietFlags = bakery_delivery_manager_review_customer_flags($quiet);
$assert(!empty($quietFlags['quiet']) && !empty($quietFlags['short']) && empty($quietFlags['missed']), 'a standing store with no route is quiet and short');
$assert(bakery_delivery_manager_review_customer_reading($quiet)['key'] === 'delivery_review.reading_quiet', 'a quiet store says nothing was put on a driver');

$missed = ['expected' => 4, 'routed' => 4, 'delivered' => 1, 'open' => 2, 'failed' => 1, 'cancelled' => 0, 'off_count' => 0];
$missedFlags = bakery_delivery_manager_review_customer_flags($missed);
$assert(!empty($missedFlags['missed']) && !empty($missedFlags['short']), 'open and failed stops stay visible when the store is also short');
$assert(bakery_delivery_manager_review_customer_reading($missed)['key'] === 'delivery_review.reading_missed', 'a missed store leads with the stops that did not get delivered');

$short = ['expected' => 4, 'routed' => 2, 'delivered' => 2, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($short)['key'] === 'delivery_review.reading_short', 'fewer deliveries than standing is short when nothing is still open');

$cancelled = ['expected' => 2, 'routed' => 2, 'delivered' => 0, 'open' => 0, 'failed' => 0, 'cancelled' => 2, 'off_count' => 0];
$cancelledFlags = bakery_delivery_manager_review_customer_flags($cancelled);
$assert(empty($cancelledFlags['missed']) && !empty($cancelledFlags['short']), 'a cancelled stop is not a miss and is not a delivery');
$assert(bakery_delivery_manager_review_customer_reading($cancelled)['key'] === 'delivery_review.reading_short_cancelled', 'cancelled visits stay in the reading');

$onTrack = ['expected' => 4, 'routed' => 4, 'delivered' => 4, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 1];
$onFlags = bakery_delivery_manager_review_customer_flags($onTrack);
$assert(!empty($onFlags['on_track']) && !empty($onFlags['off_driver']), 'a full delivery by another driver is both on track and a different driver');
$assert(bakery_delivery_manager_review_customer_reading($onTrack)['key'] === 'delivery_review.reading_on_track', 'the delivery count stays the main reading when the driver changed');

$extra = ['expected' => 2, 'routed' => 3, 'delivered' => 3, 'open' => 0, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($extra)['key'] === 'delivery_review.reading_extra', 'more deliveries than standing is extra');
$assert(bakery_delivery_manager_review_customer_matches($extra, 'extra') === true, 'the extra filter matches that store');
$assert(bakery_delivery_manager_review_customer_matches($extra, 'short') === false, 'extra is not fewer than standing');

$noStanding = ['expected' => 0, 'routed' => 2, 'delivered' => 1, 'open' => 1, 'failed' => 0, 'cancelled' => 0, 'off_count' => 0];
$assert(bakery_delivery_manager_review_customer_reading($noStanding)['key'] === 'delivery_review.reading_no_standing_missed', 'deliveries without a standing route still show the open stop');

$pattern = bakery_delivery_manager_review_pattern_label([
    ['dow' => 1, 'drivers' => ['Marcos', 'Marcos']],
    ['dow' => 3, 'drivers' => ['Sergio']],
]);
$dayNames = function_exists('bakery_day_names') ? bakery_day_names(true) : [1 => '1', 3 => '3'];
$assert($pattern === ($dayNames[1] . ' Marcos, ' . $dayNames[3] . ' Sergio'), 'the standing pattern names each day and driver once');
$assert(bakery_delivery_manager_review_pattern_label([]) === bakery_t('delivery_review.no_standing_pattern'), 'no standing rows say there is no standing route');

$range = bakery_delivery_manager_review_parse_range('2099-11-14', '', '');
$assert($range['from'] === '2099-11-01' && $range['to'] === '2099-11-14' && $range['clamped'] === false, 'an empty range is the last 14 days');
$assert(bakery_delivery_manager_review_parse_view('customers') === 'customers', 'stores is a view');
$assert(bakery_delivery_manager_review_parse_view('nope') === 'drivers', 'an unknown view opens on drivers');
$assert(bakery_delivery_manager_review_parse_filter('off_driver') === 'off_driver', 'different driver is a filter');
$assert(bakery_delivery_manager_review_parse_filter('billing') === 'all', 'an unknown filter is all stores');

$href = bakery_delivery_manager_review_href([
    'view' => 'customers',
    'from' => '2099-11-01',
    'to' => '2099-11-14',
    'filter' => 'all',
    'driver' => 4,
    'q' => 'Mission',
]);
$assert(strpos($href, 'view=customers') !== false && strpos($href, 'driver=') === false && strpos($href, 'filter=') === false, 'the store link keeps the search and drops the driver and the all filter');

echo "\n=== Page is read only and gated ===\n";
$pageAccess = [
    'logged out' => [null, 302],
    'driver' => [['role_slug' => 'driver'], 403],
    'cashier' => [['role_slug' => 'cashier'], 403],
    'manager' => [['role_slug' => 'manager'], 200],
    'administrator' => [['role_slug' => 'administrator'], 200],
];
foreach ($pageAccess as $label => $pair) {
    $status = bakery_delivery_manager_review_access_status($pair[0]);
    $assert($status === $pair[1], 'delivery review access for ' . $label . ' is ' . $pair[1]);
}
$roles = bakery_navigation_roles_for_script('delivery_manager_review.php');
$assert(in_array('administrator', $roles, true) && in_array('manager', $roles, true), 'catalog allows administrator and manager');
$assert(!in_array('driver', $roles, true) && !in_array('cashier', $roles, true), 'catalog does not allow driver or cashier');
$assert(bakery_admin_workspace_mode_allows('driver', 'delivery_manager_review.php'), 'driver manager mode keeps the review');
$assert(!bakery_admin_workspace_mode_allows('production', 'delivery_manager_review.php'), 'production manager mode leaves the review with routes');

$nav = (string)file_get_contents($root . '/includes/nav.php');
$catalog = (string)file_get_contents($root . '/includes/navigation_catalog.php');
$manager = (string)file_get_contents($root . '/manager.php');
$phone = (string)file_get_contents($root . '/includes/manager_phone.php');
$pageSrc = (string)file_get_contents($pageFile);
$includeSrc = (string)file_get_contents($includeFile);
$manifest = (string)file_get_contents($root . '/scripts/deploy_manifest.ps1');
$navHealth = strpos($nav, 'route_health.php');
$navReview = strpos($nav, 'delivery_manager_review.php');
$navJourney = strpos($nav, 'route_photo_journey.php');
$assert($navHealth !== false && $navReview !== false && $navJourney !== false && $navHealth < $navReview && $navReview < $navJourney, 'manager nav lists the review with the other route checks');
$assert(strpos($catalog, "'href' => 'delivery_manager_review.php'") !== false, 'catalog lists the review');
$assert(strpos($manager, 'delivery_manager_review.php') !== false, 'manager page links the review');
$assert(strpos($phone, 'delivery_manager_review.php') !== false, 'phone manager page links the review');
$assert(strpos($pageSrc, 'bakery_delivery_manager_review_access_status') !== false, 'page checks the role gate');
$assert(stripos($pageSrc, 'method="post"') === false && stripos($includeSrc, 'method="post"') === false, 'the page does not post');
foreach ([$pageSrc, $includeSrc] as $src) {
    $assert(preg_match('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/i', $src) !== 1, 'delivery review source has no write SQL');
    $assert(stripos($src, 'mail(') === false && stripos($src, 'twilio') === false, 'delivery review source does not send mail or text');
    $assert(strpos($src, "\u{2013}") === false && strpos($src, "\u{2014}") === false, 'delivery review source has no en dash or em dash');
}
$assert(strpos($manifest, "'delivery_manager_review.php'") !== false, 'deploy root list includes the page');
$assert(strpos($pageSrc, 'standing_routes.php') !== false && strpos($pageSrc, 'customer_record.php') !== false, 'a store links to the standing route and the customer record');
$assert(strpos($pageSrc, 'driver_photo.php') === false || strpos($includeSrc, 'driver_photo.php') !== false, 'photo links are built in the include');

echo "\n=== Strings ===\n";
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$prefixes = ['delivery_review.', 'page.delivery_manager_review', 'nav.item.delivery_manager_review', 'nav.item_desc.delivery_manager_review', 'manager_phone.delivery_review'];
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
        preg_match_all('/:[a-z_]+/', (string)$en[$key], $enTokens);
        preg_match_all('/:[a-z_]+/', (string)$es[$key], $esTokens);
        $left = $enTokens[0];
        $right = $esTokens[0];
        sort($left);
        sort($right);
        $assert($left === $right, $key . ' placeholders match');
    }
}

echo "\n=== Database load on bakerysf_test ===\n";
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'queries stay on bakerysf_test');

$d1 = '2099-11-02';
$d2 = '2099-11-09';
$weekday = bakery_standing_day_from_date($d1);
$repeatDates = [];
$cursor = new DateTimeImmutable($d1);
while (count($repeatDates) < 3) {
    $cursor = $cursor->modify('+1 day');
    $day = $cursor->format('Y-m-d');
    if ($day > $d2) {
        break;
    }
    if ((int)bakery_standing_day_from_date($day) === (int)$weekday) {
        continue;
    }
    $repeatDates[] = $day;
}
$assert(count($repeatDates) === 3, 'three non-standing dates exist inside the sample window');

$prefix = 'Review ' . bin2hex(random_bytes(3));
$created = [
    'customers' => [],
    'drivers' => [],
    'orders' => [],
    'photos' => [],
    'users' => [],
    'loads' => [],
];

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, zone, sfb_origin) VALUES (?, '1 Test St', ?, ?, 'human')"
    );
    $makeCustomer = static function (string $name, int $active, string $zone) use ($db, $insertCustomer, $prefix, &$created): int {
        $insertCustomer->execute([$prefix . ' ' . $name, $active, $zone]);
        $id = (int)$db->lastInsertId();
        $created['customers'][] = $id;
        return $id;
    };
    $ids = [
        'Quiet' => $makeCustomer('Quiet', 1, ''),
        'OnTrack' => $makeCustomer('OnTrack', 1, 'Mission'),
        'Short' => $makeCustomer('Short', 1, ''),
        'Missed' => $makeCustomer('Missed', 1, ''),
        'Failed' => $makeCustomer('Failed', 1, ''),
        'Cancel' => $makeCustomer('Cancel', 1, ''),
        'Off' => $makeCustomer('Off', 1, ''),
        'Extra' => $makeCustomer('Extra', 1, ''),
        'Before' => $makeCustomer('Before', 1, ''),
        'Repeat' => $makeCustomer('Repeat', 1, ''),
        'BulkA' => $makeCustomer('BulkA', 1, ''),
        'BulkB' => $makeCustomer('BulkB', 1, ''),
        'BulkC' => $makeCustomer('BulkC', 1, ''),
        'QuietBo' => $makeCustomer('QuietBo', 1, ''),
        'Inactive' => $makeCustomer('Inactive', 0, ''),
    ];

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
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $routeOrders = [];
    $addStop = static function (int $customerId, int $driverId, string $date, string $status, ?string $time, string $note) use ($db, $insertOrder, $insertAssignment, &$created, &$routeOrders): void {
        $key = $driverId . '|' . $date;
        $routeOrders[$key] = ($routeOrders[$key] ?? 0) + 1;
        $confirmed = ($status === 'delivered' && $time !== null) ? $date . ' ' . $time : null;
        $insertOrder->execute([$customerId, $date, $status === 'delivered' ? 'delivered' : 'pending', $confirmed]);
        $orderId = (int)$db->lastInsertId();
        $created['orders'][] = $orderId;
        $insertAssignment->execute([$orderId, $driverId, $date, $routeOrders[$key], $status, $time, $note]);
    };

    $addStop($ids['OnTrack'], $adaId, $d1, 'delivered', '09:00:00', '');
    $addStop($ids['Short'], $adaId, $d1, 'delivered', '09:20:00', '');
    $addStop($ids['Missed'], $adaId, $d1, 'pending', null, '');
    $addStop($ids['Failed'], $adaId, $d1, 'failed', null, 'Door locked');
    $addStop($ids['Cancel'], $adaId, $d1, 'cancelled', null, '');
    $addStop($ids['Extra'], $adaId, $d1, 'delivered', '09:40:00', '');
    $addStop($ids['Before'], $adaId, $d1, 'delivered', '09:50:00', '');
    $addStop($ids['BulkA'], $adaId, $d1, 'delivered', '10:00:00', '');
    $addStop($ids['BulkB'], $adaId, $d1, 'delivered', '10:02:00', '');
    $addStop($ids['BulkC'], $adaId, $d1, 'delivered', '10:04:00', '');
    $addStop($ids['OnTrack'], $adaId, $d2, 'delivered', '09:00:00', '');
    $addStop($ids['Missed'], $adaId, $d2, 'pending', null, '');
    $addStop($ids['Cancel'], $adaId, $d2, 'cancelled', null, '');
    foreach ($repeatDates as $repeatDate) {
        $addStop($ids['Repeat'], $adaId, $repeatDate, 'pending', null, '');
    }
    $addStop($ids['Off'], $calId, $d1, 'delivered', '11:00:00', '');
    $addStop($ids['Off'], $calId, $d2, 'delivered', '11:10:00', '');

    $insertPhoto = $db->prepare(
        'INSERT INTO driver_photos (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type)
         VALUES (?, ?, ?, ?, ?, ?, 20, \'image/jpeg\', ?)'
    );
    $addPhoto = static function (int $driverId, int $customerId, string $date, string $kind) use ($db, $insertPhoto, &$created): void {
        $file = 'driver' . $driverId . '_customer' . $customerId . '_' . $kind . '_' . str_replace('-', '', $date) . '_aaaaaaaa.jpg';
        $insertPhoto->execute([$driverId, $customerId, $date, $file, $file, $file, $kind]);
        $created['photos'][] = (int)$db->lastInsertId();
    };
    $addPhoto($adaId, $ids['OnTrack'], $d1, 'After');
    $addPhoto($adaId, $ids['OnTrack'], $d2, 'After');
    $addPhoto($adaId, $ids['Short'], $d1, 'After');
    $addPhoto($adaId, $ids['Extra'], $d1, 'After');
    $addPhoto($adaId, $ids['Before'], $d1, 'Before');
    $addPhoto($adaId, $ids['BulkA'], $d1, 'After');
    $addPhoto($adaId, $ids['BulkB'], $d1, 'After');
    $addPhoto($adaId, $ids['BulkC'], $d1, 'After');
    $addPhoto($calId, $ids['Off'], $d1, 'After');
    $addPhoto($calId, $ids['Off'], $d2, 'After');

    $insertStanding = $db->prepare(
        'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
    );
    $standingOrder = 1;
    foreach (['Quiet', 'OnTrack', 'Short', 'Missed', 'Failed', 'Cancel', 'Off'] as $name) {
        $insertStanding->execute([$weekday, $adaId, $ids[$name], $standingOrder]);
        $standingOrder++;
    }
    $insertStanding->execute([$weekday, $adaId, $ids['Inactive'], $standingOrder]);
    $insertStanding->execute([$weekday, $boId, $ids['QuietBo'], 1]);

    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'driver' LIMIT 1")->fetchColumn();
    $assert($roleId > 0, 'driver role exists on bakerysf_test');
    $email = 'delivery-review-' . bin2hex(random_bytes(3)) . '@example.test';
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
    }

    $loaded = bakery_delivery_manager_review_load($db, $d1, $d2);
    $assert($loaded['from'] === $d1 && $loaded['to'] === $d2, 'load keeps the requested range');

    $customers = [];
    foreach ($loaded['customers'] as $row) {
        $customers[(int)$row['customer_id']] = $row;
    }
    $drivers = [];
    foreach ($loaded['drivers'] as $row) {
        $drivers[(int)$row['driver_id']] = $row;
    }
    $assert(!isset($customers[$ids['Inactive']]), 'an inactive standing store is not part of the review');

    $quietRow = $customers[$ids['Quiet']];
    $assert((int)$quietRow['expected'] === 2 && (int)$quietRow['routed'] === 0, 'a standing store with no dated stops was called for twice and routed never');
    $assert(!empty($quietRow['flags']['quiet']) && $quietRow['reading']['key'] === 'delivery_review.reading_quiet', 'that store reads as never routed');

    $onRow = $customers[$ids['OnTrack']];
    $assert((string)$onRow['zone'] === 'Mission', 'the store keeps its zone');
    $assert((int)$onRow['expected'] === 2 && (int)$onRow['delivered'] === 2 && (int)$onRow['with_after'] === 2, 'a store delivered on both standing days with After photos is complete');
    $assert(!empty($onRow['flags']['on_track']) && empty($onRow['flags']['short']), 'complete standing deliveries are on track');
    $assert(strpos(bakery_delivery_manager_review_pattern_label($onRow['pattern']), $prefix . ' Ada') !== false, 'the standing pattern names the standing driver');
    $assert(bakery_delivery_manager_review_customer_search_match($onRow, 'mission') === true, 'search matches the zone');
    $assert(bakery_delivery_manager_review_customer_search_match($onRow, 'zzzz-no-store') === false, 'search misses a store that does not match');

    $shortRow = $customers[$ids['Short']];
    $assert((int)$shortRow['expected'] === 2 && (int)$shortRow['routed'] === 1 && (int)$shortRow['delivered'] === 1, 'one delivery against two standing visits is short');
    $assert($shortRow['reading']['key'] === 'delivery_review.reading_short', 'the short store says how many were delivered');
    $assert(empty($shortRow['flags']['quiet']) && empty($shortRow['flags']['missed']), 'a delivered short store is not quiet and not missed');

    $missedRow = $customers[$ids['Missed']];
    $assert((int)$missedRow['open'] === 2 && (int)$missedRow['delivered'] === 0, 'both standing days left open stay open');
    $assert($missedRow['reading']['key'] === 'delivery_review.reading_missed', 'the open store leads with the missed stops');

    $failedRow = $customers[$ids['Failed']];
    $assert((int)$failedRow['failed'] === 1 && (int)$failedRow['expected'] === 2 && (int)$failedRow['routed'] === 1, 'one failed visit does not hide the standing day that was never routed');
    $failedNote = '';
    foreach ($failedRow['visits'] as $visit) {
        if ((string)$visit['status'] === 'failed') {
            $failedNote = (string)$visit['note'];
        }
    }
    $assert($failedNote === 'Door locked', 'the failed stop keeps its note');

    $cancelRow = $customers[$ids['Cancel']];
    $assert((int)$cancelRow['cancelled'] === 2 && (int)$cancelRow['delivered'] === 0 && (int)$cancelRow['open'] === 0, 'cancelled stops are counted on their own');
    $assert(empty($cancelRow['flags']['missed']) && $cancelRow['reading']['key'] === 'delivery_review.reading_short_cancelled', 'cancelled is not treated as a missed delivery');

    $offRow = $customers[$ids['Off']];
    $assert((int)$offRow['delivered'] === 2 && (int)$offRow['off_count'] === 2 && !empty($offRow['flags']['on_track']) && !empty($offRow['flags']['off_driver']), 'deliveries by the other driver still count and stay marked');
    $offVisit = $offRow['visits'][0];
    $assert((int)$offVisit['actual_driver_id'] === $calId && !empty($offVisit['off_driver']), 'the visit names who went and that it was not the standing driver');

    $extraRow = $customers[$ids['Extra']];
    $assert((int)$extraRow['expected'] === 0 && (int)$extraRow['delivered'] === 1 && $extraRow['reading']['key'] === 'delivery_review.reading_no_standing', 'a delivery with no standing route says so');

    $beforeRow = $customers[$ids['Before']];
    $assert((int)$beforeRow['missing_after'] === 1 && (int)$beforeRow['with_after'] === 0, 'a Before photo is not delivery proof');
    $beforeUrl = '';
    foreach ($beforeRow['visits'] as $visit) {
        if ((string)$visit['photo_url'] !== '') {
            $beforeUrl = (string)$visit['photo_url'];
        }
    }
    $assert(strpos($beforeUrl, 'driver_photo.php') !== false && strpos($beforeUrl, 'uploads/driver_photos') === false, 'the photo opens through driver_photo.php');

    $repeatRow = $customers[$ids['Repeat']];
    $assert((int)$repeatRow['open'] === 3 && $repeatRow['reading']['key'] === 'delivery_review.reading_no_standing_missed', 'three open visits with no standing route stay visible');

    $ada = $drivers[$adaId];
    $adaDays = [];
    foreach ($ada['day_rows'] as $day) {
        $adaDays[(string)$day['date']] = $day;
    }
    $assert((int)$adaDays[$d1]['standing_stops'] === 7, 'Ada standing count ignores the inactive store');
    $assert((int)$adaDays[$d1]['stops'] === 10 && (int)$adaDays[$d1]['delivered'] === 7 && (int)$adaDays[$d1]['open'] === 1 && (int)$adaDays[$d1]['failed'] === 1 && (int)$adaDays[$d1]['cancelled'] === 1, 'Ada day one counts each kind of stop');
    $assert((int)$adaDays[$d1]['delivered_with_after'] === 6, 'the Before photo is the one delivered stop without proof');
    $assert((string)$adaDays[$d1]['last_close'] === $d1 . ' 10:04:00', 'the day closes at the last delivered stop');
    $assert($adaDays[$d1]['clocked_in'] === true && $adaDays[$d1]['route_closed'] === true, 'clock-in and a reconciled load show on that day');
    $assert((int)$adaDays[$d2]['standing_stops'] === 7 && (int)$adaDays[$d2]['stops'] === 3 && (int)$adaDays[$d2]['delivered'] === 1, 'the second standing day shows the stops that were actually routed');
    $assert($adaDays[$d2]['clocked_in'] === false && $adaDays[$d2]['route_closed'] === false, 'a day without a punch or a reconciled load is not closed');
    $bulk = $adaDays[$d1]['bulk_stores'];
    sort($bulk);
    $expectedBulk = [$prefix . ' BulkA', $prefix . ' BulkB', $prefix . ' BulkC'];
    sort($expectedBulk);
    $assert($bulk === $expectedBulk, 'three closes inside a few minutes are named and the earlier stops are not');
    $repeatNames = array_map(static fn(array $row): string => (string)$row['customer_name'], $ada['repeat']);
    $assert($repeatNames === [$prefix . ' Repeat'], 'a store left open three times is a repeat for that driver');
    $assert((int)$ada['missing_after'] === 1 && (int)$ada['bulk_days'] === 1, 'Ada has one missing After photo and one bulk-stamp day');

    $bo = $drivers[$boId];
    $assert((int)$bo['stops'] === 0 && (int)$bo['standing_stops'] === 2 && (int)$bo['quiet_days'] === 2, 'a standing driver with no routed stops is listed at zero');
    $cal = $drivers[$calId];
    $assert((int)$cal['standing_stops'] === 0 && (int)$cal['delivered'] === 2 && (int)$cal['delivered_with_after'] === 2, 'the driver who covered the other route is credited for those deliveries');

    $adaNeeds = array_values(array_filter($loaded['needs'], static fn(array $row): bool => (int)$row['driver_id'] === $adaId));
    $needByCustomer = [];
    foreach ($adaNeeds as $need) {
        $needByCustomer[(int)$need['customer_id']][] = (string)$need['reason'];
    }
    $assert(in_array('missing_after', $needByCustomer[$ids['Before']] ?? [], true), 'a delivered Before photo needs a look');
    $assert(in_array('failed', $needByCustomer[$ids['Failed']] ?? [], true), 'a failed stop needs a look');
    $assert(!isset($needByCustomer[$ids['OnTrack']]), 'a delivered After photo does not need a look');
    $assert(!isset($needByCustomer[$ids['Cancel']]), 'a cancelled stop is not listed as open or failed');
} finally {
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
