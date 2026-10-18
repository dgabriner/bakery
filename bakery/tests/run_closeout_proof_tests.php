<?php
/**
 * Closeout proof: negative stop totals, restore hiding, photo flags, Spanish copy.
 *
 * Usage: php tests/run_closeout_proof_tests.php
 * Targets bakerysf_test on loopback only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/photo_capture.php';
require_once $root . '/includes/route_summary.php';
require_once $root . '/includes/driver_day_sms.php';
require_once $root . '/includes/driver_assignments.php';

echo "\n=== Negative totals stay on the booked snapshot ===\n";
assert_eq(
    -8.0,
    route_manager_estimate_amount([
        'payment_collection' => 'signature',
        'delivery_status' => 'delivered',
        'delivery_order_total' => -8,
        'total_amount' => -8,
        'order_total_estimate' => -42,
    ]),
    'Lucky Pork keeps the booked -$8 instead of the line sum'
);
assert_eq(
    -42.0,
    route_manager_estimate_amount([
        'payment_collection' => 'signature',
        'delivery_status' => 'delivered',
        'delivery_order_total' => -42,
        'total_amount' => -42,
        'order_total_estimate' => -80,
    ]),
    'Casa Lucas keeps the booked -$42'
);
assert_eq(
    -8.0,
    route_manager_estimate_amount([
        'payment_collection' => 'signature',
        'delivery_status' => 'delivered',
        'delivery_order_total' => null,
        'total_amount' => -8,
        'order_total_estimate' => -42,
    ]),
    'a negative header is used when the snapshot was never written'
);
assert_eq(
    15.0,
    route_manager_estimate_amount([
        'payment_collection' => 'signature',
        'delivery_status' => 'pending',
        'delivery_order_total' => null,
        'total_amount' => 0,
        'order_total_estimate' => 15,
    ]),
    'a missing snapshot and a zero header still fall through to the line estimate'
);

echo "\n=== Photo distance and capture time ===\n";
assert_true(!bakery_photo_is_far_from_store(37.76, -122.42, 37.7605, -122.42), 'a short walk from the store is not far');
assert_true(bakery_photo_is_far_from_store(37.76, -122.42, 37.77, -122.42), 'about a kilometer from the store is far');
assert_true(!bakery_photo_is_far_from_store(null, null, 37.76, -122.42), 'a photo with no GPS is not flagged far');
assert_true(!bakery_photo_is_far_from_store(0, 0, 37.76, -122.42), 'a 0,0 photo is treated as missing GPS');
assert_eq(250.0, bakery_photo_far_meters(), 'far means more than 250 meters');
$north = bakery_photo_exif_coord(['37/1', '45/1', '0/1'], 'N');
$west = bakery_photo_exif_coord(['122/1', '25/1', '30/1'], 'W');
assert_true($north !== null && abs($north - 37.75) < 0.0001, 'EXIF north latitude becomes decimal degrees');
assert_true($west !== null && $west < 0 && abs($west - (-122.425)) < 0.0001, 'EXIF west longitude is negative');
assert_eq('2026-10-17 15:04:05', bakery_photo_normalize_captured_at('2026-10-17 15:04:05'), 'browser capture time is stored as a datetime');
assert_eq(null, bakery_photo_normalize_captured_at(''), 'a blank capture time stays blank');
assert_eq(null, bakery_photo_normalize_captured_at('not-a-date'), 'an unreadable capture time stays blank');

echo "\n=== Route summary flags and closeout buckets ===\n";
$within = [
    [
        'route_order' => 1,
        'delivery_status' => 'delivered',
        'sold_amount' => 4,
        'booked_amount' => 4,
        'latitude' => 37.76,
        'longitude' => -122.42,
        'photos' => [['photo_type' => 'After', 'captured_at' => '2026-10-17 10:00:00', 'latitude' => 37.76, 'longitude' => -122.42]],
    ],
    [
        'route_order' => 2,
        'delivery_status' => 'delivered',
        'sold_amount' => 4,
        'booked_amount' => 4,
        'latitude' => 37.76,
        'longitude' => -122.42,
        'photos' => [['photo_type' => 'After', 'captured_at' => '2026-10-17 09:59:30', 'latitude' => 37.76, 'longitude' => -122.42]],
    ],
];
bakery_route_summary_mark_proof_flags($within);
assert_true(empty($within[1]['photo_out_of_order']), 'a photo 30 seconds before the previous stop stays in order');

$photos = [
    '7:3' => [[
        'photo_type' => 'Before',
        'captured_at' => '2026-10-17 10:00:00',
        'latitude' => 37.7602,
        'longitude' => -122.4202,
    ]],
    '7:4' => [[
        'photo_type' => 'Before',
        'captured_at' => '2026-10-17 09:00:00',
        'latitude' => 37.7602,
        'longitude' => -122.4202,
    ]],
    '7:5' => [[
        'photo_type' => 'After',
        'captured_at' => '2026-10-17 11:00:00',
        'latitude' => 37.80,
        'longitude' => -122.42,
    ]],
    '7:6' => [[
        'photo_type' => 'After',
        'created_at' => '2026-10-17 12:00:00',
        'latitude' => 37.7601,
        'longitude' => -122.4201,
    ]],
    '7:7' => [[
        'photo_type' => 'Receipt',
        'captured_at' => '2026-10-17 13:00:00',
        'latitude' => 37.7601,
        'longitude' => -122.4201,
    ]],
];
$day = bakery_route_summary_build_day([
    [
        'id' => 7,
        'name' => 'Ana',
        'deliveries' => [
            [
                'customer_id' => 1,
                'customer_name' => 'Lucky Pork',
                'route_order' => 1,
                'delivery_status' => 'delivered',
                'payment_collection' => 'signature',
                'delivery_order_total' => -8,
                'total_amount' => -8,
                'order_total_estimate' => -42,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 2,
                'customer_name' => 'Casa Lucas',
                'route_order' => 2,
                'delivery_status' => 'delivered',
                'payment_collection' => 'signature',
                'delivery_order_total' => -42,
                'total_amount' => -42,
                'order_total_estimate' => -80,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 3,
                'customer_name' => 'Open Shelf',
                'route_order' => 3,
                'delivery_status' => 'pending',
                'payment_collection' => 'signature',
                'delivery_order_total' => 10,
                'total_amount' => 10,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 4,
                'customer_name' => 'Arrival Only',
                'route_order' => 4,
                'delivery_status' => 'delivered',
                'payment_collection' => 'signature',
                'delivery_order_total' => 5,
                'total_amount' => 5,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 5,
                'customer_name' => 'Far After',
                'route_order' => 5,
                'delivery_status' => 'delivered',
                'payment_collection' => 'signature',
                'delivery_order_total' => 20,
                'total_amount' => 20,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 6,
                'customer_name' => 'Failed With Photo',
                'route_order' => 6,
                'delivery_status' => 'failed',
                'payment_collection' => 'signature',
                'delivery_order_total' => 9,
                'total_amount' => 9,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 7,
                'customer_name' => 'Cancelled With Photo',
                'route_order' => 7,
                'delivery_status' => 'cancelled',
                'payment_collection' => 'signature',
                'delivery_order_total' => 3,
                'total_amount' => 3,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
            [
                'customer_id' => 8,
                'customer_name' => 'Cash And Credit',
                'route_order' => 8,
                'delivery_status' => 'delivered',
                'payment_collection' => 'cod',
                'amount_collected' => 12,
                'delivery_order_total' => -8,
                'total_amount' => -8,
                'order_total_estimate' => -8,
                'latitude' => 37.76,
                'longitude' => -122.42,
            ],
        ],
    ],
], $photos);

$byName = [];
foreach ($day['drivers'][0]['stops'] as $stop) {
    $byName[(string)$stop['customer_name']] = $stop;
}
assert_eq(-8.0, (float)$byName['Lucky Pork']['sold_amount'], 'the card shows the booked -$8');
assert_eq(-42.0, (float)$byName['Casa Lucas']['sold_amount'], 'the card shows the booked -$42');
assert_true(!empty($byName['Lucky Pork']['negative_total']), 'a negative booked total is flagged');
assert_true(!empty($byName['Casa Lucas']['negative_total']), 'Casa Lucas is flagged');
assert_true(!empty($byName['Lucky Pork']['closeout_without_photo']), 'a delivered stop with no photo is flagged');
assert_true(!empty($byName['Open Shelf']['photo_without_closeout']), 'a photo on an open stop is flagged');
assert_true(empty($byName['Open Shelf']['closeout_without_photo']), 'an open stop is not a closeout missing a photo');
assert_true(!empty($byName['Arrival Only']['photo_out_of_order']), 'a photo taken before the previous stop is out of order');
assert_true(!empty($byName['Arrival Only']['arrival_only']), 'Before without After is arrival only');
assert_true(!empty($byName['Far After']['photo_far']), 'a photo far from the store is flagged');
assert_true(!empty($byName['Far After']['has_pod_photo']), 'After counts as proof');
assert_true(!empty($byName['Failed With Photo']['photo_without_closeout']), 'a failed stop with a photo is still open');
assert_true(empty($byName['Cancelled With Photo']['photo_without_closeout']), 'a cancelled stop is not a missing closeout');
assert_eq(12.0, (float)$byName['Cash And Credit']['sold_amount'], 'recorded COD cash still wins the displayed dollars');
assert_true(!empty($byName['Cash And Credit']['negative_total']), 'a negative snapshot is flagged even when cash was collected');
assert_eq(3, (int)$day['stats']['negative_stops'], 'the day counts every negative stop');
assert_eq(2, (int)$day['stats']['photo_without_closeout'], 'open and failed photo stops are counted');
assert_eq(3, (int)$day['stats']['closeout_without_photo'], 'delivered stops with no photo are counted');
assert_eq(1, (int)$day['stats']['photo_far'], 'one far photo is counted');
assert_eq(1, (int)$day['stats']['photo_out_of_order'], 'one out-of-order photo is counted');

$counts = bakery_route_closeout_counts($day['drivers'][0]['stops']);
assert_eq(
    ['after' => 1, 'arrival_only' => 1, 'no_photo' => 3, 'open' => 2],
    $counts,
    'closeout splits after, arrival only, no photo, and still open'
);

echo "\n=== Spanish end-of-day text is copy only ===\n";
$body = bakery_driver_closeout_sms_body([
    'driver_name' => 'Ana',
    'date' => '2026-10-17',
    'counts' => ['after' => 2, 'arrival_only' => 1, 'no_photo' => 0, 'open' => 3],
]);
assert_true(strpos($body, 'Foto después de la entrega: 2') !== false, 'the note counts after-delivery photos in Spanish');
assert_true(strpos($body, 'Solo foto de llegada: 1') !== false, 'the note counts arrival-only stops in Spanish');
assert_true(strpos($body, 'Sin foto: 0') !== false, 'the note counts stops with no photo');
assert_true(strpos($body, 'Aún abiertas: 3') !== false, 'the note counts stops still open');
assert_true(strpos($body, 'sábado 17 de octubre') !== false, 'the note dates the day in Spanish');
assert_true(stripos($body, 'twilio') === false && stripos($body, 'http') === false, 'the note has no send target');
$smsSource = (string)file_get_contents($root . '/includes/driver_day_sms.php');
assert_true(strpos($smsSource, 'This function does not send it') !== false, 'the closeout note documents that it does not send');
assert_true(strpos($smsSource, 'bakery_load_lang_catalog') !== false, 'clipboard text comes from the Spanish catalog');

$dailyRun = (string)file_get_contents($root . '/daily_run.php');
assert_true(strpos($dailyRun, 'bakery_route_closeout_driver_splits') !== false, 'Daily Run loads the per-driver split');
assert_true(strpos($dailyRun, 'navigator.clipboard.writeText') !== false, 'the Spanish note copies to the clipboard');
assert_true(strpos($dailyRun, 'fetch(') === false, 'Daily Run does not send the note');
assert_true(strpos($dailyRun, 'type="button" class="dr-btn dr-btn-secondary dr-closeout-sms-copy"') !== false, 'the copy control is a button, not a submit');
$summarySource = (string)file_get_contents($root . '/includes/route_summary.php');
assert_true(strpos($summarySource, 'INSERT ') === false && strpos($summarySource, 'UPDATE ') === false, 'route summary reads do not write');

echo "\n=== After photo is camera only, with a shelf guide ===\n";
$js = (string)file_get_contents($root . '/includes/driver_delivery.js');
$upload = (string)file_get_contents($root . '/upload_driver_photo.php');
$driverPage = (string)file_get_contents($root . '/driver.php');
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
assert_eq('Show the shelf with the bread.', $en['driver.departure_guidance'], 'the After step tells the driver to show the shelf');
assert_eq('Muestra el anaquel con el pan.', $es['driver.departure_guidance'], 'the After step has the same guide in Spanish');
assert_true(strpos($driverPage, "bakery_t('driver.departure_guidance')") !== false, 'the driver page passes the shelf guide to the modal');
assert_true(strpos($js, 'galleryBtn.hidden = lock') !== false, 'the gallery button hides on the After step');
assert_true(strpos($js, 'captured_at') !== false && strpos($js, '0x9003') !== false, 'the phone reads capture time from EXIF when it is there');
assert_true(strpos($upload, "\$photoType === 'After' && \$captureMethod === 'gallery'") !== false, 'the server rejects a gallery After photo');
assert_true(strpos($upload, "\$capturedAt = \$postedCapturedAt ?? \$exif['captured_at'];") !== false, 'capture time comes from the phone or EXIF');
assert_true(is_file($root . '/database/schema/107_driver_photo_capture.sql'), 'capture columns ship in migration 107');
assert_true(!is_file($root . '/database/schema/108_driver_photo_capture.sql'), '108 stays unused');
assert_true(
    column_exists($db, 'driver_photos', 'captured_at') && column_exists($db, 'driver_photos', 'capture_source'),
    'bakerysf_test has captured_at and capture_source'
);

echo "\n=== Restore hides stores another driver already delivered ===\n";
assert_true(
    bakery_route_customer_delivered_by_other(1, 2, 'delivered', 'pending', null),
    'another driver delivered status hides the store'
);
assert_true(
    bakery_route_customer_delivered_by_other(1, 2, 'pending', 'invoiced', null),
    'an invoiced order on another driver hides the store'
);
assert_true(
    bakery_route_customer_delivered_by_other(1, 2, 'pending', 'pending', '2026-10-17 09:00:00'),
    'a confirmed delivery on another driver hides the store'
);
assert_true(
    !bakery_route_customer_delivered_by_other(1, 2, 'pending', 'pending', null),
    'a pending stop on another driver can still be restored'
);
assert_true(
    !bakery_route_customer_delivered_by_other(1, 1, 'delivered', 'delivered', '2026-10-17 09:00:00'),
    'the standing driver own delivery is not treated as someone else'
);

$prefix = 'CP ' . bin2hex(random_bytes(3));
$date = new DateTimeImmutable('2099-12-01');
while ($date->format('N') !== '1') {
    $date = $date->modify('+1 day');
}
$routeDate = $date->format('Y-m-d');
$dayOfWeek = (int)$date->format('N');

$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$insertDriver->execute([$prefix . ' Standing']);
$standingId = (int)$db->lastInsertId();
$insertDriver->execute([$prefix . ' Other']);
$otherId = (int)$db->lastInsertId();

$insertCustomer = $db->prepare('INSERT INTO customers (name, address, is_active) VALUES (?, ?, 1)');
$customerIds = [];
foreach ([
    'Delivered' => '1 Proof Way',
    'Pending' => '2 Proof Way',
    'Invoiced' => '3 Proof Way',
    'Confirmed' => '4 Proof Way',
    'Missing' => '5 Proof Way',
    'Mine' => '6 Proof Way',
] as $label => $address) {
    $insertCustomer->execute([$prefix . ' ' . $label, $address]);
    $customerIds[$label] = (int)$db->lastInsertId();
}

$cleanup = static function () use ($db, $customerIds, $standingId, $otherId): void {
    $ids = array_values($customerIds);
    if ($ids === []) {
        return;
    }
    $in = implode(',', array_map('intval', $ids));
    $db->exec(
        "DELETE dp FROM driver_photos dp
         WHERE dp.customer_id IN ($in)"
    );
    $db->exec(
        "DELETE doa FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id IN ($in)"
    );
    $db->exec("DELETE FROM daily_orders WHERE customer_id IN ($in)");
    $db->exec("DELETE FROM standing_routes WHERE customer_id IN ($in)");
    $db->exec("DELETE FROM customers WHERE id IN ($in)");
    $db->exec('DELETE FROM drivers WHERE id IN (' . (int)$standingId . ',' . (int)$otherId . ')');
};
register_shutdown_function($cleanup);

$insertOrder = $db->prepare(
    'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at)
     VALUES (?, ?, ?, 10, ?)'
);
$insertAssignment = $db->prepare(
    'INSERT INTO daily_order_assignments
     (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
     VALUES (?, ?, ?, ?, ?)'
);
$cases = [
    'Delivered' => ['other', 'delivered', 'delivered', null, 1],
    'Pending' => ['other', 'pending', 'pending', null, 2],
    'Invoiced' => ['other', 'pending', 'invoiced', null, 3],
    'Confirmed' => ['other', 'pending', 'pending', $routeDate . ' 09:15:00', 4],
    'Mine' => ['standing', 'delivered', 'delivered', $routeDate . ' 08:00:00', 1],
];
foreach ($cases as $label => $spec) {
    [$who, $assignmentStatus, $orderStatus, $confirmedAt, $routeOrder] = $spec;
    $insertOrder->execute([
        $customerIds[$label],
        $routeDate,
        $orderStatus,
        $confirmedAt,
    ]);
    $orderId = (int)$db->lastInsertId();
    $insertAssignment->execute([
        $orderId,
        $who === 'standing' ? $standingId : $otherId,
        $routeDate,
        $routeOrder,
        $assignmentStatus,
    ]);
}

$insertStanding = $db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
);
$order = 1;
foreach (['Delivered', 'Pending', 'Invoiced', 'Confirmed', 'Missing', 'Mine'] as $label) {
    $insertStanding->execute([$dayOfWeek, $standingId, $customerIds[$label], $order]);
    $order++;
}

$elsewhere = bakery_route_customers_delivered_elsewhere($db, $routeDate, $standingId);
assert_true(!empty($elsewhere[$customerIds['Delivered']]), 'a delivered assignment is delivered elsewhere');
assert_true(!empty($elsewhere[$customerIds['Invoiced']]), 'an invoiced order is delivered elsewhere');
assert_true(!empty($elsewhere[$customerIds['Confirmed']]), 'a confirmed timestamp is delivered elsewhere');
assert_true(empty($elsewhere[$customerIds['Pending']]), 'a pending assignment is not delivered elsewhere');
assert_true(empty($elsewhere[$customerIds['Mine']]), 'the standing driver own stop is not delivered elsewhere');

$groups = bakery_route_missing_standing_by_driver($db, $routeDate);
$group = null;
foreach ($groups as $row) {
    if ((int)$row['driver_id'] === $standingId) {
        $group = $row;
        break;
    }
}
assert_true(is_array($group), 'the standing driver still has a restore group');
$suggested = [];
if (is_array($group)) {
    foreach ($group['stops'] as $stop) {
        $suggested[] = (int)$stop['customer_id'];
    }
}
sort($suggested);
$expected = [$customerIds['Pending'], $customerIds['Missing']];
sort($expected);
assert_eq($expected, $suggested, 'restore suggests the pending and missing stores only');

$sync = (string)file_get_contents($root . '/includes/driver_assignment_actions.php');
assert_true(strpos($sync, 'bakery_route_customers_delivered_elsewhere') !== false, 'restore sync skips stores delivered by another driver');
assert_true(strpos($sync, 'restore_done_none') !== false, 'an empty restore does not assign those stores');

$summaryPage = (string)file_get_contents($root . '/route_summary.php');
assert_true(strpos($summaryPage, 'route_summary.negative_total') !== false, 'Route Summary prints the negative-total flag');
assert_true(strpos($summaryPage, 'route_summary.negative_help') !== false, 'Route Summary warns when any stop total is negative');
assert_true(strpos($summaryPage, '$_POST') === false && strpos($summaryPage, 'INSERT ') === false, 'Route Summary GET does not write');

echo "\n" . $GLOBALS['TEST_PASS'] . " passed, " . $GLOBALS['TEST_FAIL'] . " failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
