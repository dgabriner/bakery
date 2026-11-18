<?php
/**
 * One proof classifier. Con foto is a real After photo.
 *
 * Usage: php tests/run_stop_proof_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/stop_proof.php';
require_once $root . '/includes/route_stop_proof.php';
require_once $root . '/includes/route_summary.php';
require_once $root . '/includes/driver_reports.php';
require_once $root . '/includes/daily_status.php';
require_once $root . '/includes/delivery_proof.php';
require_once $root . '/includes/photo_integrity.php';
require_once $root . '/includes/photo_capture.php';
require_once $root . '/includes/hosted_migration_runtime.php';
require_once $root . '/includes/schema_migration_numbers.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Class table ===\n";
$beforeDelivered = [
    'delivery_status' => 'delivered',
    'photos' => [['photo_type' => 'Before']],
];
$assert(bakery_stop_proof_class($beforeDelivered['photos'], 'delivered') === 'arrival_only', 'only Before is arrival_only');
$assert(bakery_stop_proof_mark($beforeDelivered) === 'delivered_no_photo', 'a delivered Before photo is entregadas sin foto');
$assert(bakery_route_stop_photo_mark($beforeDelivered) === 'delivered_no_photo', 'driver reports mark follows the classifier');

$afterStop = [
    'delivery_status' => 'delivered',
    'photos' => [['photo_type' => 'After']],
];
$assert(bakery_stop_proof_class($afterStop['photos'], 'delivered') === 'after', 'After is after');
$assert(bakery_stop_proof_is_delivery_photo('after') === true, 'After is a delivery photo');
$assert(bakery_stop_proof_mark($afterStop) === 'with_photo', 'After is con foto');

$receiptStop = [
    'delivery_status' => 'delivered',
    'photos' => [['photo_type' => 'Receipt']],
];
$assert(BAKERY_STOP_PROOF_RECEIPT_COUNTS_AS_DELIVERY === false, 'a receipt is not con foto until Danny says so');
$assert(bakery_stop_proof_class($receiptStop['photos'], 'delivered') === 'receipt_only', 'receipt only stays receipt_only');
$assert(bakery_stop_proof_is_delivery_photo('receipt_only') === false, 'receipt_only is not a delivery photo');
$assert(bakery_stop_proof_mark($receiptStop) === 'delivered_no_photo', 'a receipt is not con foto');

$noneStop = ['delivery_status' => 'pending', 'photos' => []];
$assert(bakery_stop_proof_class([], 'pending') === 'none', 'no photos is none');
$assert(bakery_stop_proof_mark($noneStop) === 'no_info', 'a stop that was not visited is sin info');

$filenameBefore = [
    'delivery_status' => 'delivered',
    'photos' => [[
        'photo_type' => 'After',
        'filename' => 'driver2_customer9_Before_20261117_100000_aaaaaaaa.jpg',
    ]],
];
$assert(bakery_stop_proof_class($filenameBefore['photos'], 'delivered') === 'arrival_only', 'the filename wins over the photo_type column');

echo "\n=== 11/17 fixture counts ===\n";
$fixture = stop_proof_fixture_1117();
$expected = [
    'Laura' => ['after' => 1, 'arrival_only' => 5, 'none' => 4],
    'Marcos' => ['after' => 3, 'arrival_only' => 0, 'none' => 18],
    'Sergio' => ['after' => 3, 'arrival_only' => 7, 'none' => 0],
];
foreach ($expected as $name => $counts) {
    $direct = bakery_stop_proof_report_counts($fixture[$name]);
    $summary = bakery_route_summary_build_day([
        ['id' => 1, 'name' => $name, 'deliveries' => $fixture[$name]],
    ], stop_proof_photos_by_stop($fixture[$name]), 'all');
    $summaryCounts = $summary['drivers'][0]['proof_counts'] ?? [];
    $eod = bakery_driver_reports_summary_driver(
        ['id' => 1, 'name' => $name, 'stops' => $fixture[$name]],
        [],
        null
    );
    $dailyStops = [];
    foreach ($fixture[$name] as $stop) {
        $class = bakery_stop_proof_class($stop['photos'], (string)$stop['delivery_status']);
        $dailyStops[] = [
            'delivery_status' => $stop['delivery_status'],
            'photos' => $stop['photos'],
            'pod_photo_present' => bakery_stop_proof_is_delivery_photo($class),
            'arrival_only' => $stop['delivery_status'] === 'delivered' && $class === 'arrival_only',
        ];
    }
    $daily = bakery_daily_status_photo_coverage($dailyStops);
    $four = [
        'direct' => $direct,
        'route_summary' => $summaryCounts,
        'eod' => $eod['proof_counts'] ?? [],
        'daily_status' => $daily['proof_counts'] ?? [],
    ];
    $same = true;
    foreach ($four as $source) {
        if ((int)($source['after'] ?? -1) !== $counts['after']
            || (int)($source['arrival_only'] ?? -1) !== $counts['arrival_only']
            || (int)($source['none'] ?? -1) !== $counts['none']) {
            $same = false;
        }
    }
    $assert($same, $name . ' is ' . $counts['after'] . '/' . $counts['arrival_only'] . '/' . $counts['none'] . ' on all four surfaces');
}

echo "\n=== Delivery gate is unchanged ===\n";
$assert(BAKERY_DELIVERY_BEFORE_PHOTO_COUNTS === true, 'a Before photo still counts at the delivery gate');
$assert(bakery_delivery_is_proof_photo_type('Before') === true, 'the gate accepts Before');
$assert(bakery_delivery_is_proof_photo_type('After') === true, 'the gate accepts After');
$assert(bakery_delivery_is_proof_photo_type('Receipt') === true, 'the gate accepts Receipt');
$proofSrc = (string)file_get_contents($root . '/includes/delivery_proof.php');
$assert(substr_count($proofSrc, 'const BAKERY_DELIVERY_BEFORE_PHOTO_COUNTS') === 1, 'whether a Before photo counts is one constant');

echo "\n=== Migration 117 ===\n";
$assert(bakery_schema_next_migration_number(['113_sales_role']) === 116, '114 and 115 stay reserved when 116 is not in the list');
$assert(is_file($root . '/database/schema/117_driver_photo_integrity.sql'), 'the integrity migration is 117');
$assert(!is_file($root . '/database/schema/114_driver_photo_integrity.sql'), '114 is not reused');
$assert(!is_file($root . '/database/schema/116_driver_photo_integrity.sql'), '116 stays free for draft 111');
$assert(bakery_hosted_migration_id_refusal('114_driver_photo_integrity') !== '', '114 is refused');
$assert(bakery_hosted_migration_id_refusal('117_driver_photo_integrity') === '', '117 is a git schema file');
$assert(strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn()) === 'bakerysf_test', 'integrity checks stay on bakerysf_test');
bakery_photo_integrity_ensure_schema($db);
bakery_photo_integrity_ensure_schema($db);
$assert(bakery_photo_integrity_schema_ready($db), 'migration 117 adds sha256 and integrity_flags');
$assert(column_exists($db, 'driver_photos', 'captured_at'), 'capture time from draft 78 is on the photo');
$integritySrc = (string)file_get_contents($root . '/includes/photo_integrity.php');
$assert(stripos($integritySrc, 'DELETE FROM driver_photos') === false, 'a flagged photo is not deleted');

$prefix = 'Proof ' . bin2hex(random_bytes(3));
$created = ['customers' => [], 'drivers' => [], 'orders' => [], 'photos' => []];
try {
    $blankDriver = stop_proof_insert_driver($db, $created, $prefix . ' Blank');
    $blankCustomer = stop_proof_insert_customer($db, $created, $prefix . ' Blank store');
    $oldId = stop_proof_insert_photo($db, $created, $blankDriver, $blankCustomer, '2099-01-01', 'Before', null, null, null);
    $assert(bakery_photo_integrity_apply($db, $oldId) === [], 'a photo with no hash is unflagged');
    $assert(bakery_photo_integrity_flags_of($db, $oldId) === [], 'blank flags are not an error');

    echo "\n=== Saved fixture on bakerysf_test ===\n";
    $saved = stop_proof_seed_fixture($db, $created, $prefix);
    $summary = bakery_driver_reports_load_summary($db, '2099-11-17');
    $route = bakery_route_summary_load($db, '2099-11-17');
    $status = bakery_daily_status_snapshot($db, '2099-11-17');
    foreach (['Laura', 'Marcos', 'Sergio'] as $name) {
        $driverId = (int)$saved['drivers'][$name];
        $want = $expected[$name];
        $fromReports = stop_proof_counts_named($summary['drivers'] ?? [], $prefix . ' ' . $name);
        $fromRoute = stop_proof_counts_named($route['drivers'] ?? [], $prefix . ' ' . $name);
        $fromStatus = stop_proof_counts_from_status($status['stops'] ?? [], $prefix . ' ' . $name);
        $fromCloseout = bakery_stop_proof_report_counts(bakery_route_closeout_stops($db, '2099-11-17', $driverId));
        $same = $fromReports === $want && $fromRoute === $want && $fromStatus === $want
            && (int)$fromCloseout['after'] === $want['after']
            && (int)$fromCloseout['arrival_only'] === $want['arrival_only']
            && (int)$fromCloseout['none'] === $want['none'];
        $assert($same, 'saved ' . $name . ' matches driver reports, route summary, daily status, and closeout');
    }
    $page = (string)file_get_contents($root . '/driver_reports.php');
    $assert(strpos($page, 'driver_reports.proof_tally') !== false, 'the end of day tab shows the shared counts');

    echo "\n=== Integrity flags at upload ===\n";
    $date = '2026-11-18';
    $driverId = stop_proof_insert_driver($db, $created, $prefix . ' Flag');
    $storeId = stop_proof_insert_customer($db, $created, $prefix . ' Store', 37.76000000, -122.42000000);
    $otherId = stop_proof_insert_customer($db, $created, $prefix . ' Other', 37.76000000, -122.42000000);
    $thirdId = stop_proof_insert_customer($db, $created, $prefix . ' Third', 37.76000000, -122.42000000);
    $hash = hash('sha256', 'same-photo-bytes');
    $first = stop_proof_insert_photo($db, $created, $driverId, $storeId, $date, 'After', $hash, 37.76000000, -122.42000000, '2026-11-18 10:00:00');
    $second = stop_proof_insert_photo($db, $created, $driverId, $otherId, $date, 'After', $hash, 37.76000000, -122.42000000, '2026-11-18 10:02:00');
    bakery_photo_integrity_apply($db, $first);
    $secondFlags = bakery_photo_integrity_apply($db, $second);
    $firstFlags = bakery_photo_integrity_flags_of($db, $first);
    $assert(in_array('reused', $firstFlags, true) && in_array('reused', $secondFlags, true), 'the same file on two stops marks both reused');

    $beforeId = stop_proof_insert_photo($db, $created, $driverId, $thirdId, $date, 'Before', $hash, 37.76000000, -122.42000000, '2026-11-18 11:00:00');
    $copyId = stop_proof_insert_photo($db, $created, $driverId, $thirdId, $date, 'After', $hash, 37.76000000, -122.42000000, '2026-11-18 11:05:00');
    bakery_photo_integrity_apply($db, $beforeId);
    $copyFlags = bakery_photo_integrity_apply($db, $copyId);
    $assert(in_array('arrival_equals_after', $copyFlags, true), 'Before equal to After is arrival_equals_after');
    $assert(in_array('arrival_equals_after', bakery_photo_integrity_flags_of($db, $beforeId), true), 'the Before photo is flagged too');
    $doubtful = [[
        'photo_type' => 'After',
        'integrity_flags' => $copyFlags,
    ]];
    $assert(bakery_stop_proof_after_is_doubtful($doubtful) === true, 'a flagged After photo is doubtful');
    $assert(bakery_stop_proof_mark([
        'delivery_status' => 'delivered',
        'photos' => $doubtful,
    ]) === 'doubtful_photo', 'a flagged After photo is not con foto');

    $farCustomer = stop_proof_insert_customer($db, $created, $prefix . ' Far', 37.76000000, -122.42000000);
    $farId = stop_proof_insert_photo($db, $created, $driverId, $farCustomer, $date, 'After', hash('sha256', 'far'), 37.80000000, -122.42000000, '2026-11-18 12:00:00');
    $farFlags = bakery_photo_integrity_apply($db, $farId);
    $assert(in_array('far_from_store', $farFlags, true), 'a photo over 250 m is far from the store');
    $assert(bakery_photo_far_meters() === 250.0, 'the 250 m rule is the one from draft 78');

    $orderDate = '2026-11-19';
    $earlyCustomer = stop_proof_insert_customer($db, $created, $prefix . ' Early');
    $lateCustomer = stop_proof_insert_customer($db, $created, $prefix . ' Late');
    stop_proof_insert_order($db, $created, $earlyCustomer, $driverId, $orderDate, 1, 'delivered');
    stop_proof_insert_order($db, $created, $lateCustomer, $driverId, $orderDate, 2, 'delivered');
    stop_proof_insert_photo($db, $created, $driverId, $earlyCustomer, $orderDate, 'Before', hash('sha256', 'early'), null, null, '2026-11-19 10:30:00');
    $latePhoto = stop_proof_insert_photo($db, $created, $driverId, $lateCustomer, $orderDate, 'After', hash('sha256', 'late'), null, null, '2026-11-19 10:00:00');
    $lateFlags = bakery_photo_integrity_apply($db, $latePhoto);
    $assert(in_array('out_of_order', $lateFlags, true), 'a photo taken before the previous stop is out of order');

    $clusterDate = '2026-11-20';
    $clusterIds = [];
    foreach (['A', 'B', 'C'] as $index => $label) {
        $customerId = stop_proof_insert_customer($db, $created, $prefix . ' Cluster ' . $label);
        $clusterIds[] = stop_proof_insert_photo(
            $db,
            $created,
            $driverId,
            $customerId,
            $clusterDate,
            'After',
            hash('sha256', 'cluster-' . $label),
            null,
            null,
            '2026-11-20 10:0' . $index . ':00'
        );
    }
    $clusterFlags = bakery_photo_integrity_apply($db, $clusterIds[2]);
    $assert(in_array('cluster', $clusterFlags, true), 'photos on 3 stores inside 5 minutes are a cluster');
    $assert(
        in_array('cluster', bakery_photo_integrity_flags_of($db, $clusterIds[0]), true)
        && in_array('cluster', bakery_photo_integrity_flags_of($db, $clusterIds[1]), true),
        'the earlier photos in the cluster are flagged too'
    );
    $assert(bakery_photo_integrity_cluster_seconds() === BAKERY_ROUTE_STOP_BULK_STAMP_MINUTES * 60, 'the cluster window is the bulk-stamp window');

    if (function_exists('imagecreatetruecolor')) {
        $similar = stop_proof_dhash_pair($root);
        $distance = bakery_photo_dhash_distance(
            (string)bakery_photo_dhash($similar['same_a']),
            (string)bakery_photo_dhash($similar['same_b'])
        );
        $different = bakery_photo_dhash_distance(
            (string)bakery_photo_dhash($similar['same_a']),
            (string)bakery_photo_dhash($similar['other'])
        );
        $assert($distance <= bakery_photo_dhash_max_distance(), 'the same picture is within 4 dHash bits');
        $assert($different > bakery_photo_dhash_max_distance(), 'a different picture is farther than 4 dHash bits');
        foreach ($similar as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    } else {
        $assert(bakery_photo_dhash($root . '/includes/stop_proof.php') === null, 'dHash is skipped when GD cannot read the file');
    }

    echo "\n=== Gate on bakerysf_test ===\n";
    $gateCustomer = stop_proof_insert_customer($db, $created, $prefix . ' Gate');
    $gateDriver = stop_proof_insert_driver($db, $created, $prefix . ' Gate driver');
    $gateOrder = stop_proof_insert_order($db, $created, $gateCustomer, $gateDriver, '2099-08-01', 1, 'pending');
    $bareThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $gateOrder, false);
    } catch (Throwable $e) {
        $bareThrew = true;
    }
    $assert($bareThrew, 'a stop with no photo and no logged skip still throws');
    stop_proof_insert_photo($db, $created, $gateDriver, $gateCustomer, '2099-08-01', 'Before', null, null, null);
    $arrivalThrew = false;
    try {
        bakery_delivery_assert_proof_for_confirm($db, $gateOrder, false);
    } catch (Throwable $e) {
        $arrivalThrew = true;
    }
    $assert(!$arrivalThrew, 'a stop with only a Before photo can still be marked delivered');

    $driverPage = (string)file_get_contents($root . '/driver.php');
    $assert(strpos($driverPage, 'photo2-pending-chip') !== false, 'the driver route shows the Photo 2 chip');
    $assert(strpos($driverPage, 'photo2PendingList') !== false, 'the finish screen lists Photo 2 chips');
    $en = require $root . '/lang/en.php';
    $es = require $root . '/lang/es.php';
    $assert(($en['driver.photo2_pending'] ?? '') === 'Photo 2 pending', 'English chip is Photo 2 pending');
    $assert(($es['driver.photo2_pending'] ?? '') === 'Foto 2 pendiente', 'Spanish chip is Foto 2 pendiente');
    $assert(($es['driver_reports.mark_doubtful'] ?? '') === 'Foto dudosa', 'Spanish doubtful mark is Foto dudosa');
} finally {
    stop_proof_cleanup($db, $created);
}

echo "\n" . ($fail === 0 ? "ALL PASS\n" : ($fail . " FAILED\n"));
exit($fail === 0 ? 0 : 1);

/**
 * @return array<string, list<array<string, mixed>>>
 */
function stop_proof_fixture_1117(): array
{
    $build = static function (int $after, int $arrival, int $none): array {
        $stops = [];
        $plan = 1;
        for ($i = 0; $i < $after; $i++) {
            $stops[] = [
                'customer_id' => $plan,
                'customer_name' => 'After ' . $plan,
                'route_order' => $plan,
                'delivery_status' => 'delivered',
                'amount_collected' => 0,
                'photos' => [['photo_type' => 'After', 'customer_id' => $plan]],
            ];
            $plan++;
        }
        for ($i = 0; $i < $arrival; $i++) {
            $stops[] = [
                'customer_id' => $plan,
                'customer_name' => 'Arrival ' . $plan,
                'route_order' => $plan,
                'delivery_status' => 'delivered',
                'amount_collected' => 0,
                'photos' => [['photo_type' => 'Before', 'customer_id' => $plan]],
            ];
            $plan++;
        }
        for ($i = 0; $i < $none; $i++) {
            $stops[] = [
                'customer_id' => $plan,
                'customer_name' => 'None ' . $plan,
                'route_order' => $plan,
                'delivery_status' => 'pending',
                'amount_collected' => 0,
                'photos' => [],
            ];
            $plan++;
        }
        return $stops;
    };
    return [
        'Laura' => $build(1, 5, 4),
        'Marcos' => $build(3, 0, 18),
        'Sergio' => $build(3, 7, 0),
    ];
}

/**
 * @param list<array<string, mixed>> $stops
 * @return array<string, list<array<string, mixed>>>
 */
function stop_proof_photos_by_stop(array $stops): array
{
    $photos = [];
    foreach ($stops as $stop) {
        $photos['1:' . (int)$stop['customer_id']] = $stop['photos'];
    }
    return $photos;
}

/**
 * @param list<array<string, mixed>> $drivers
 * @return array{after:int,arrival_only:int,none:int}
 */
function stop_proof_counts_named(array $drivers, string $name): array
{
    foreach ($drivers as $driver) {
        if (!is_array($driver) || (string)($driver['name'] ?? '') !== $name) {
            continue;
        }
        $counts = $driver['proof_counts'] ?? [];
        return [
            'after' => (int)($counts['after'] ?? 0),
            'arrival_only' => (int)($counts['arrival_only'] ?? 0),
            'none' => (int)($counts['none'] ?? 0),
        ];
    }
    return ['after' => -1, 'arrival_only' => -1, 'none' => -1];
}

/**
 * @param list<array<string, mixed>> $stops
 * @return array{after:int,arrival_only:int,none:int}
 */
function stop_proof_counts_from_status(array $stops, string $driverName): array
{
    $rows = [];
    foreach ($stops as $stop) {
        if (!is_array($stop) || (string)($stop['driver_name'] ?? '') !== $driverName) {
            continue;
        }
        $class = (string)($stop['proof_class'] ?? 'none');
        $photos = [];
        if ($class === 'after') {
            $photo = ['photo_type' => 'After'];
            if (!empty($stop['proof_doubtful'])) {
                $photo['integrity_flags'] = ['reused'];
            }
            $photos[] = $photo;
        } elseif ($class === 'arrival_only') {
            $photos[] = ['photo_type' => 'Before'];
        } elseif ($class === 'receipt_only') {
            $photos[] = ['photo_type' => 'Receipt'];
        }
        $rows[] = [
            'delivery_status' => (string)($stop['delivery_status'] ?? ''),
            'photos' => $photos,
        ];
    }
    $counts = bakery_stop_proof_report_counts($rows);
    return [
        'after' => (int)$counts['after'],
        'arrival_only' => (int)$counts['arrival_only'],
        'none' => (int)$counts['none'],
    ];
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 * @return array{drivers:array<string,int>}
 */
function stop_proof_seed_fixture(PDO $db, array &$created, string $prefix): array
{
    $drivers = [];
    $plan = [
        'Laura' => ['after' => 1, 'arrival' => 5, 'none' => 4],
        'Marcos' => ['after' => 3, 'arrival' => 0, 'none' => 18],
        'Sergio' => ['after' => 3, 'arrival' => 7, 'none' => 0],
    ];
    foreach ($plan as $name => $counts) {
        $driverId = stop_proof_insert_driver($db, $created, $prefix . ' ' . $name);
        $drivers[$name] = $driverId;
        $order = 1;
        $add = static function (string $status, ?string $type) use ($db, &$created, $driverId, $prefix, $name, &$order): void {
            $customerId = stop_proof_insert_customer($db, $created, $prefix . ' ' . $name . ' ' . $order);
            stop_proof_insert_order($db, $created, $customerId, $driverId, '2099-11-17', $order, $status);
            if ($type !== null) {
                stop_proof_insert_photo($db, $created, $driverId, $customerId, '2099-11-17', $type, null, null, null);
            }
            $order++;
        };
        for ($i = 0; $i < $counts['after']; $i++) {
            $add('delivered', 'After');
        }
        for ($i = 0; $i < $counts['arrival']; $i++) {
            $add('delivered', 'Before');
        }
        for ($i = 0; $i < $counts['none']; $i++) {
            $add('pending', null);
        }
    }
    return ['drivers' => $drivers];
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 */
function stop_proof_insert_driver(PDO $db, array &$created, string $name): int
{
    $db->prepare('INSERT INTO drivers (name) VALUES (?)')->execute([$name]);
    $id = (int)$db->lastInsertId();
    $created['drivers'][] = $id;
    return $id;
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 */
function stop_proof_insert_customer(PDO $db, array &$created, string $name, ?float $lat = null, ?float $lng = null): int
{
    $hasOrigin = function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin');
    if ($hasOrigin) {
        $db->prepare('INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, ?)')
            ->execute([$name, '1 Test St', 'human']);
    } else {
        $db->prepare('INSERT INTO customers (name, address, is_active) VALUES (?, ?, 1)')
            ->execute([$name, '1 Test St']);
    }
    $id = (int)$db->lastInsertId();
    $created['customers'][] = $id;
    if ($lat !== null && $lng !== null && column_exists($db, 'customers', 'latitude')) {
        $db->prepare('UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?')->execute([$lat, $lng, $id]);
    }
    return $id;
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 */
function stop_proof_insert_order(PDO $db, array &$created, int $customerId, int $driverId, string $date, int $routeOrder, string $status): int
{
    $confirmed = $status === 'delivered' ? $date . ' 12:00:00' : null;
    $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_confirmed_at) VALUES (?, ?, ?, 0, ?)')
        ->execute([$customerId, $date, $status === 'delivered' ? 'delivered' : 'pending', $confirmed]);
    $orderId = (int)$db->lastInsertId();
    $created['orders'][] = $orderId;
    $deliveredAt = $status === 'delivered' ? '12:00:00' : null;
    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, actual_delivery_time)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, $driverId, $date, $routeOrder, $status, $deliveredAt]);
    return $orderId;
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 */
function stop_proof_insert_photo(
    PDO $db,
    array &$created,
    int $driverId,
    int $customerId,
    string $date,
    string $type,
    ?string $sha,
    ?float $lat,
    ?float $lng,
    ?string $capturedAt = null
): int {
    $name = 'proof-' . bin2hex(random_bytes(4)) . '.jpg';
    $columns = 'driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type, latitude, longitude';
    $values = '?, ?, ?, ?, ?, ?, 10, ?, ?, ?, ?';
    $params = [$driverId, $customerId > 0 ? $customerId : null, $date, $name, $name, $name, 'image/jpeg', $type, $lat, $lng];
    if (column_exists($db, 'driver_photos', 'sha256')) {
        $columns .= ', sha256, captured_at, capture_source';
        $values .= ', ?, ?, ?';
        $params[] = $sha;
        $params[] = $capturedAt;
        $params[] = $capturedAt !== null ? 'browser' : null;
    }
    $db->prepare("INSERT INTO driver_photos ({$columns}) VALUES ({$values})")->execute($params);
    $id = (int)$db->lastInsertId();
    $created['photos'][] = $id;
    return $id;
}

/**
 * @return array{same_a:string,same_b:string,other:string}
 */
function stop_proof_dhash_pair(string $root): array
{
    $dir = $root . '/uploads/driver_photos/stop-proof-test';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $paint = static function (string $path, bool $flip): void {
        $image = imagecreatetruecolor(32, 32);
        $black = imagecolorallocate($image, 0, 0, 0);
        $white = imagecolorallocate($image, 255, 255, 255);
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $dark = $flip ? $x >= 16 : $x < 16;
                imagesetpixel($image, $x, $y, $dark ? $black : $white);
            }
        }
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    };
    $paths = [
        'same_a' => $dir . '/same-a.jpg',
        'same_b' => $dir . '/same-b.jpg',
        'other' => $dir . '/other.jpg',
    ];
    $paint($paths['same_a'], false);
    $paint($paths['same_b'], false);
    $paint($paths['other'], true);
    return $paths;
}

/**
 * @param array{customers:list<int>,drivers:list<int>,orders:list<int>,photos:list<int>} $created
 */
function stop_proof_cleanup(PDO $db, array $created): void
{
    if ($created['photos'] !== []) {
        $ids = implode(',', array_map('intval', $created['photos']));
        $db->exec('DELETE FROM driver_photos WHERE id IN (' . $ids . ')');
    }
    if ($created['orders'] !== []) {
        $ids = implode(',', array_map('intval', $created['orders']));
        $db->exec('DELETE FROM daily_order_assignments WHERE daily_order_id IN (' . $ids . ')');
        $db->exec('DELETE FROM daily_orders WHERE id IN (' . $ids . ')');
    }
    if ($created['customers'] !== []) {
        $ids = implode(',', array_map('intval', $created['customers']));
        $db->exec('DELETE FROM customers WHERE id IN (' . $ids . ')');
    }
    if ($created['drivers'] !== []) {
        $ids = implode(',', array_map('intval', $created['drivers']));
        $db->exec('DELETE FROM drivers WHERE id IN (' . $ids . ')');
    }
}
