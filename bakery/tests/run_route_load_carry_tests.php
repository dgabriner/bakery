<?php
/**
 * Route overload math and missed pan dulce carry-forward.
 *
 * Usage: php tests/run_route_load_carry_tests.php
 * Database: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/route_load.php';
require_once $root . '/includes/pan_carry.php';
require_once $root . '/includes/i18n.php';

$fail = 0;
$assert = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

echo "=== Time math ===\n";
$trimmed = bakery_route_load_service_samples([10, 12, 11, 13, 14, 15, 90, 1], 2, 10);
$assert($trimmed === [11.0, 12.0, 13.0, 14.0, 15.0], 'bulk closes under 2 minutes drop, then one outlier from each end');
$assert(bakery_route_load_median($trimmed) === 13.0, 'trimmed median is 13');
$assert(bakery_route_load_median([18, 22]) === 20.0, 'even median averages the two middle values');

$override = bakery_route_load_service_choice([20, 22, 24], 12, 20, 1, 10, 2);
$assert($override['minutes'] === 12 && $override['source'] === 'override' && $override['samples'] === 3, 'a manager override wins and still reports the sample count');
$history = bakery_route_load_service_choice([18, 22, 1], null, 20, 1, 0, 2);
$assert($history['minutes'] === 20 && $history['source'] === 'history' && $history['samples'] === 2, 'service history is the median after the bulk filter');
$fallback = bakery_route_load_service_choice([1], null, 20, 1, 0, 2);
$assert($fallback['minutes'] === 20 && $fallback['source'] === 'default' && $fallback['samples'] === 0, 'service falls back to the default when history is empty');

$driveShort = bakery_route_load_drive_choice([10, 14], 3, 0, 2, 6, 15);
$assert($driveShort['source'] === 'distance' && $driveShort['minutes'] === 6 && $driveShort['samples'] === 2, 'two drive samples stay under the minimum and use distance');
$driveDefault = bakery_route_load_drive_choice([10, 14], 3, 0, 2, null, 15);
$assert($driveDefault['source'] === 'default' && $driveDefault['minutes'] === 15, 'drive falls back to 15 minutes without history or coordinates');
$driveReady = bakery_route_load_drive_choice([10, 12, 14], 3, 0, 2, 6, 15);
$assert($driveReady['source'] === 'history' && $driveReady['minutes'] === 12 && $driveReady['samples'] === 3, 'three drive samples use the learned median');

$miles = bakery_route_load_haversine_minutes(37.78, -122.41, 37.76, -122.42, 15);
$assert($miles === 6, 'straight-line minutes use stored coordinates and the speed factor');
$assert(bakery_route_load_haversine_minutes(null, null, 37.76, -122.42, 15) === null, 'missing coordinates do not invent a drive time');

$stops = [];
for ($i = 0; $i < 24; $i++) {
    $stops[] = ['service' => 20, 'drive' => $i === 0 ? 0 : 15];
}
$projected = bakery_route_load_project('08:00:00', $stops, '17:00:00', 15);
$assert($projected['finish_label'] === '9:45 PM' && $projected['stop_count'] === 24, '24 default stops from 8:00 finish at 9:45 PM');
$assert($projected['over_stops'] && $projected['over_time'] && $projected['warn'], '24 stops after 5 PM is an overload');
$example = bakery_route_load_project('08:00:00', [['service' => 870, 'drive' => 0]], '17:00:00', 15);
$assert($example['finish_label'] === '10:30 PM', '8:00 plus 870 minutes is 10:30 PM');
$onTime = bakery_route_load_project('08:00:00', [['service' => 540, 'drive' => 0]], '17:00:00', 15);
$assert($onTime['finish_label'] === '5:00 PM' && $onTime['over_time'] === false, 'a finish at exactly 5 PM is not after the limit');
$exactStops = [];
for ($i = 0; $i < 15; $i++) {
    $exactStops[] = ['service' => 1, 'drive' => 0];
}
$exact = bakery_route_load_project('08:00:00', $exactStops, '17:00:00', 15);
$assert($exact['over_stops'] === false && $exact['warn'] === false, 'exactly 15 stops is not over the stop limit');
$oneMore = $exactStops;
$oneMore[] = ['service' => 1, 'drive' => 0];
$overStops = bakery_route_load_project('08:00:00', $oneMore, '23:59:00', 15);
$assert($overStops['over_stops'] === true && $overStops['warn'] === true, '16 stops is over the stop limit');

foreach (['driver' => false, 'driver_assistant' => false, 'baker' => false, 'cashier' => false, '' => false, 'administrator' => true, 'manager' => true] as $role => $allowed) {
    $assert(bakery_route_load_role_may_see_warning($role === '' ? null : $role) === $allowed, 'role ' . ($role === '' ? 'empty' : $role) . ($allowed ? ' sees' : ' does not see') . ' the warning');
}

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
$assert(
    bakery_route_load_warning_line('Marcos', 24, '10:30 PM') === 'Marcos: 24 paradas, termina ~10:30 PM',
    'Spanish warning line matches the example'
);
$assert(bakery_t('pan_carry.label') === 'Pendiente de ayer', 'Spanish carry label is Pendiente de ayer');
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);

echo "\n=== Hooks ===\n";
$closeSrc = (string)file_get_contents($root . '/includes/daily_run.php');
$schedSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$deliverSrc = (string)file_get_contents($root . '/complete_delivery.php');
$assert(strpos($closeSrc, 'bakery_pan_carry_forward_day') !== false, 'close of day carries missed pan dulce');
$assert(strpos($schedSrc, 'bakery_pan_carry_forward_recent') !== false, 'demand_scheduler carries recent misses');
$assert(strpos($schedSrc, 'bakery_route_load_rebuild') !== false, 'demand_scheduler rebuilds route time history');
$assert(strpos($deliverSrc, 'bakery_route_load_refresh_order') !== false, 'a delivery close refreshes that stop');
$assert(strpos($schedSrc, 'bakery_ensure_daily_orders_horizon') !== false, 'the overnight job still materializes dated orders');

echo "\n=== History and carry on bakerysf_test ===\n";
$assert(bakery_route_load_ready($db), 'migration 112 route load settings are installed');
$assert(bakery_pan_carry_ready($db), 'migration 112 carry table is installed');

$panId = (int)$db->query(
    "SELECT p.id FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     WHERE LOWER(pl.name) = 'pan dulce' ORDER BY p.id LIMIT 1"
)->fetchColumn();
$loafId = (int)$db->query(
    "SELECT p.id FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     WHERE LOWER(pl.name) = 'sour flour' ORDER BY p.id LIMIT 1"
)->fetchColumn();
$assert($panId > 0 && $loafId > 0 && $panId !== $loafId, 'fixtures have a pan dulce product and a sour flour product');

$prefix = 'RouteLoad ';
$customerIds = [];
$driverIds = [];
$savedSettings = bakery_route_load_settings($db);
$savedService = $db->query('SELECT customer_id, median_minutes, sample_count FROM route_service_stats')->fetchAll(PDO::FETCH_ASSOC);
$savedDrive = $db->query('SELECT from_customer_id, to_customer_id, median_minutes, sample_count FROM route_drive_stats')->fetchAll(PDO::FETCH_ASSOC);

$cleanup = static function () use ($db, &$customerIds, &$driverIds, $prefix, $savedSettings, $savedService, $savedDrive): void {
    $names = $db->prepare('SELECT id FROM customers WHERE name LIKE ?');
    $names->execute([$prefix . '%']);
    foreach ($names->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $customerIds[] = (int)$id;
    }
    $customerIds = array_values(array_unique(array_filter($customerIds)));
    $driverNames = $db->prepare('SELECT id FROM drivers WHERE name LIKE ?');
    $driverNames->execute([$prefix . '%']);
    foreach ($driverNames->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $driverIds[] = (int)$id;
    }
    $driverIds = array_values(array_unique(array_filter($driverIds)));
    if ($customerIds !== []) {
        $in = implode(',', $customerIds);
        if (table_exists($db, 'special_orders')) {
            $specialIds = $db->query("SELECT id FROM special_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
            if ($specialIds) {
                $specialIn = implode(',', array_map('intval', $specialIds));
                $db->exec("DELETE FROM special_order_lines WHERE special_order_id IN ($specialIn)");
            }
            $db->exec("DELETE FROM special_orders WHERE customer_id IN ($in)");
        }
        $orderIds = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($orderIds) {
            $orderIn = implode(',', array_map('intval', $orderIds));
            $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ($orderIn)");
            $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($orderIn)");
            if (table_exists($db, 'operational_events')) {
                $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($orderIn) OR customer_id IN ($in)");
            }
            $db->exec("DELETE FROM daily_orders WHERE id IN ($orderIn)");
        }
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
        if (table_exists($db, 'standing_routes')) {
            $db->exec("DELETE FROM standing_routes WHERE customer_id IN ($in)");
        }
        if (table_exists($db, 'customer_delivery_skips')) {
            $db->exec("DELETE FROM customer_delivery_skips WHERE customer_id IN ($in)");
        }
        if (table_exists($db, 'dated_route_removals')) {
            $db->exec("DELETE FROM dated_route_removals WHERE customer_id IN ($in)");
        }
        if (table_exists($db, 'driver_photos')) {
            $db->exec("DELETE FROM driver_photos WHERE customer_id IN ($in)");
        }
        if (table_exists($db, 'route_service_overrides')) {
            $db->exec("DELETE FROM route_service_overrides WHERE customer_id IN ($in)");
        }
        if (table_exists($db, 'route_carry_forwards')) {
            if (table_exists($db, 'field_audit')) {
                $carryIds = $db->query("SELECT id FROM route_carry_forwards WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
                if ($carryIds) {
                    $carryIn = implode(',', array_map('intval', $carryIds));
                    $db->exec("DELETE FROM field_audit WHERE entity_type = 'route_carry' AND entity_id IN ($carryIn)");
                }
                $db->exec("DELETE FROM field_audit WHERE entity_type = 'route_service_override' AND entity_id IN ($in)");
            }
            $db->exec("DELETE FROM route_carry_forwards WHERE customer_id IN ($in)");
        }
        $db->exec("DELETE FROM customers WHERE id IN ($in)");
    }
    if ($driverIds !== []) {
        $driverIn = implode(',', $driverIds);
        $db->exec("DELETE FROM daily_order_assignments WHERE driver_id IN ($driverIn)");
        $db->exec("DELETE FROM drivers WHERE id IN ($driverIn)");
    }
    if (table_exists($db, 'field_audit')) {
        $db->exec("DELETE FROM field_audit WHERE entity_type = 'route_load_settings' AND entity_id = 1");
    }
    bakery_route_load_save_settings($db, $savedSettings);
    $db->exec('DELETE FROM route_service_stats');
    $db->exec('DELETE FROM route_drive_stats');
    $serviceStmt = $db->prepare('INSERT INTO route_service_stats (customer_id, median_minutes, sample_count) VALUES (?, ?, ?)');
    foreach ($savedService as $row) {
        $serviceStmt->execute([(int)$row['customer_id'], $row['median_minutes'], (int)$row['sample_count']]);
    }
    $driveStmt = $db->prepare('INSERT INTO route_drive_stats (from_customer_id, to_customer_id, median_minutes, sample_count) VALUES (?, ?, ?, ?)');
    foreach ($savedDrive as $row) {
        $driveStmt->execute([(int)$row['from_customer_id'], (int)$row['to_customer_id'], $row['median_minutes'], (int)$row['sample_count']]);
    }
};
register_shutdown_function($cleanup);
$cleanup();
$customerIds = [];
$driverIds = [];

$insertCustomer = static function (string $name, ?float $lat = null, ?float $lng = null) use ($db, &$customerIds, $prefix): int {
    $db->prepare('INSERT INTO customers (name, zone, is_active, latitude, longitude) VALUES (?, ?, 1, ?, ?)')
        ->execute([$prefix . $name, 'North Zone', $lat, $lng]);
    $id = (int)$db->lastInsertId();
    $customerIds[] = $id;
    return $id;
};
$insertDriver = static function (string $name) use ($db, &$driverIds, $prefix): int {
    $db->prepare('INSERT INTO drivers (name) VALUES (?)')->execute([$prefix . $name]);
    $id = (int)$db->lastInsertId();
    $driverIds[] = $id;
    return $id;
};
$insertStop = static function (
    int $customerId,
    string $date,
    int $driverId,
    int $routeOrder,
    int $productId,
    int $qty,
    float $price,
    string $status = 'pending',
    ?string $notes = null
) use ($db): array {
    $db->prepare('INSERT INTO daily_orders (customer_id, order_date, driver_id, status, total_amount) VALUES (?, ?, ?, ?, ?)')
        ->execute([$customerId, $date, $driverId, 'pending', round($qty * $price, 2)]);
    $orderId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, $productId, $qty, $price, round($qty * $price, 2)]);
    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status, notes)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$orderId, $driverId, $date, $routeOrder, $status, $notes]);
    return ['order_id' => $orderId, 'assignment_id' => (int)$db->lastInsertId()];
};
$panQty = static function (int $customerId, string $date) use ($db, $panId): int {
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(doi.quantity), 0)
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doi.product_id = ?'
    );
    $stmt->execute([$customerId, $date, $panId]);
    return (int)$stmt->fetchColumn();
};
$assignmentCount = static function (int $customerId, string $date) use ($db): int {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND doa.delivery_date = ?'
    );
    $stmt->execute([$customerId, $date]);
    return (int)$stmt->fetchColumn();
};
$photo = static function (int $driverId, int $customerId, string $date, string $type, string $at) use ($db): void {
    $db->prepare(
        'INSERT INTO driver_photos
            (driver_id, customer_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, photo_type, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 10, ?, ?, ?)'
    )->execute([
        $driverId,
        $customerId,
        $date,
        'routeload_' . $customerId . '_' . $type . '_' . str_replace([' ', ':', '-'], '', $at) . '.jpg',
        'routeload.jpg',
        'test/routeload.jpg',
        'image/jpeg',
        $type,
        $at,
    ]);
};

$driver = $insertDriver('Marcos');
$histA = $insertCustomer('Hist A', 37.78, -122.41);
$histB = $insertCustomer('Hist B', 37.76, -122.42);
$serviceDays = [
    '2026-09-01' => 1,
    '2026-09-02' => 20,
    '2026-09-03' => 30,
];
$dayIndex = 0;
foreach ($serviceDays as $date => $serviceMinutes) {
    $dayIndex++;
    $photo($driver, $histA, $date, 'Before', $date . ' 09:00:00');
    $photo($driver, $histA, $date, 'After', $date . ' 09:' . str_pad((string)$serviceMinutes, 2, '0', STR_PAD_LEFT) . ':00');
    $gap = [8, 10, 12][$dayIndex - 1];
    $arriveMin = $serviceMinutes + $gap;
    $photo($driver, $histB, $date, 'Before', $date . ' 09:' . str_pad((string)$arriveMin, 2, '0', STR_PAD_LEFT) . ':00');
    $photo($driver, $histB, $date, 'After', $date . ' 10:00:00');
}
$built = bakery_route_load_rebuild($db);
$assert($built['service'] >= 2 && $built['drive'] >= 1, 'rebuild writes service and drive stats from photo times');
$serviceStats = bakery_route_load_service_stat_map($db);
$assert((int)($serviceStats[$histA]['samples'] ?? 0) === 2, 'the 1 minute bulk close is not a service sample');
$assert((int)round((float)($serviceStats[$histA]['median'] ?? 0)) === 25, 'service median uses the remaining visits');
$driveStats = bakery_route_load_drive_stat_map($db);
$driveKey = $histA . ':' . $histB;
$assert((int)($driveStats[$driveKey]['samples'] ?? 0) === 3, 'three drive gaps clear the sample minimum');
$assert((int)round((float)($driveStats[$driveKey]['median'] ?? 0)) === 10, 'drive median is the middle gap');
$again = bakery_route_load_rebuild($db);
$assert($again === $built, 'rebuild is idempotent');

$fourth = $insertStop($histA, '2026-09-04', $driver, 1, $panId, 4, 2.25, 'delivered');
$photo($driver, $histA, '2026-09-04', 'Before', '2026-09-04 09:00:00');
$photo($driver, $histA, '2026-09-04', 'After', '2026-09-04 09:40:00');
$photo($driver, $histB, '2026-09-04', 'Before', '2026-09-04 10:20:00');
$photo($driver, $histB, '2026-09-04', 'After', '2026-09-04 10:40:00');
$fourthB = $insertStop($histB, '2026-09-04', $driver, 2, $panId, 4, 2.25, 'delivered');
bakery_route_load_refresh_order($db, $fourth['order_id']);
bakery_route_load_refresh_order($db, $fourthB['order_id']);
$serviceStats = bakery_route_load_service_stat_map($db);
$driveStats = bakery_route_load_drive_stat_map($db);
$assert((int)($serviceStats[$histA]['samples'] ?? 0) === 3, 'close refresh keeps the older service samples');
$assert((int)round((float)($serviceStats[$histA]['median'] ?? 0)) === 30, 'close refresh median includes the new visit with the old ones');
$assert((int)($driveStats[$driveKey]['samples'] ?? 0) === 4, 'close refresh keeps the older drive samples');
$assert((int)round((float)($driveStats[$driveKey]['median'] ?? 0)) === 11, 'close refresh median is the full pair history, not the latest gap');

bakery_route_load_save_override($db, $histA, 9);
$stored = bakery_route_load_stored_service($serviceStats[$histA], 9, 20, 1);
$assert($stored['minutes'] === 9 && $stored['source'] === 'override' && $stored['samples'] === 3, 'stored projection prefers the override over history');

$marcosStops = [];
for ($n = 1; $n <= 24; $n++) {
    $store = $insertCustomer('Stop ' . $n, 37.70 + ($n * 0.001), -122.40);
    $marcosStops[] = $insertStop($store, '2026-10-23', $driver, $n, $panId, 2, 2.25);
}
$warning = bakery_route_load_warning_html($db, '2026-10-23', ['role_slug' => 'manager', 'id' => 1]);
$assert(strpos($warning, 'Marcos') !== false && strpos($warning, '24') !== false, 'the manager warning names the 24-stop route');
$assert(bakery_route_load_warning_html($db, '2026-10-23', ['role_slug' => 'driver', 'id' => 2]) === '', 'a driver does not see the overload warning');
$assert(bakery_route_load_warning_html($db, '2026-10-23', ['role_slug' => 'administrator', 'id' => 3]) !== '', 'an administrator sees the overload warning');
bakery_route_load_save_settings($db, ['max_stops' => 30, 'finish_after' => '23:59:00']);
$cleared = bakery_route_load_report($db, '2026-10-23', $driver);
$assert(($cleared['warnings'] ?? []) === [], 'raising both limits clears the flag and does not edit the route');
$assert($assignmentCount($marcosStops[0]['order_id'] > 0 ? (int)$db->query('SELECT customer_id FROM daily_orders WHERE id = ' . (int)$marcosStops[0]['order_id'])->fetchColumn() : 0, '2026-10-23') === 1, 'the warning leaves the first stop in place');
bakery_route_load_save_settings($db, $savedSettings);

$openThu = $insertCustomer('Open Thu');
$insertStop($openThu, '2026-10-22', $driver, 3, $panId, 4, 2.25, 'pending');
$counts = bakery_pan_carry_forward_day($db, '2026-10-22');
$assert($counts['carried'] >= 1, 'an open Thursday pan stop carries forward');
$assert(bakery_pan_carry_next_service_date($db, '2026-10-22') === '2026-10-23', 'Thursday rolls to Friday');
$assert($panQty($openThu, '2026-10-23') === 4, 'the missed quantity is on Friday');
$assert($assignmentCount($openThu, '2026-10-23') === 1, 'Friday has one stop');
$pos = $db->prepare(
    'SELECT doa.route_order, doa.driver_id FROM daily_order_assignments doa
     JOIN daily_orders do ON do.id = doa.daily_order_id
     WHERE do.customer_id = ? AND doa.delivery_date = ?'
);
$pos->execute([$openThu, '2026-10-23']);
$placed = $pos->fetch(PDO::FETCH_ASSOC);
$assert((int)($placed['driver_id'] ?? 0) === $driver && (int)($placed['route_order'] ?? 0) === 3, 'the carry keeps the same driver and stop position');
$label = bakery_pan_carry_labels_for_date($db, '2026-10-23')[$openThu] ?? null;
$assert(is_array($label) && (string)$label['label'] !== '', 'the carried stop has a label');
$second = bakery_pan_carry_forward_day($db, '2026-10-22');
$assert($second['carried'] === 0 && $assignmentCount($openThu, '2026-10-23') === 1, 'running the carry again does not add a second stop');

$dedupeStore = $insertCustomer('Already Fri');
$insertStop($dedupeStore, '2026-10-22', $driver, 4, $panId, 4, 2.25, 'in_transit');
$insertStop($dedupeStore, '2026-10-23', $driver, 50, $panId, 6, 2.25, 'pending');
bakery_pan_carry_forward_day($db, '2026-10-22');
$assert($panQty($dedupeStore, '2026-10-23') === 6, 'an existing Friday delivery is not doubled');
$assert($assignmentCount($dedupeStore, '2026-10-23') === 1, 'an existing Friday delivery does not gain a stop');
$dedupeRow = bakery_pan_carry_find($db, '2026-10-22', $dedupeStore);
$assert((string)($dedupeRow['outcome'] ?? '') === 'deduped', 'the existing delivery is logged as a dedupe');

$friOnly = $insertCustomer('Open Fri');
$insertStop($friOnly, '2026-10-23', $driver, 30, $panId, 5, 2.25, 'pending');
bakery_pan_carry_forward_day($db, '2026-10-23');
$assert(bakery_pan_carry_next_service_date($db, '2026-10-23') === '2026-10-24', 'Friday rolls to Saturday');
$assert($assignmentCount($friOnly, '2026-10-24') === 1 && $panQty($friOnly, '2026-10-24') === 5, 'a Friday miss is on Saturday');

$sunday = $insertCustomer('Open Sun');
$insertStop($sunday, '2026-10-25', $driver, 2, $panId, 7, 2.25, 'rescheduled');
$assert(bakery_pan_carry_next_service_date($db, '2026-10-25') === '2026-10-26', 'Sunday rolls to Monday');
bakery_pan_carry_forward_day($db, '2026-10-25');
$assert($assignmentCount($sunday, '2026-10-26') === 1 && $panQty($sunday, '2026-10-26') === 7, 'a Sunday miss is on Monday');

$restoreStore = $insertCustomer('Zero Fri');
$db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 5, 8)')
    ->execute([$restoreStore, $panId]);
$insertStop($restoreStore, '2026-10-22', $driver, 5, $panId, 3, 2.25, 'pending');
$zero = $insertStop($restoreStore, '2026-10-23', $driver, 60, $panId, 0, 2.25, 'pending');
$db->prepare('UPDATE daily_orders SET standing_fill_blocked = 1 WHERE id = ?')->execute([$zero['order_id']]);
$db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([$zero['assignment_id']]);
bakery_pan_carry_forward_day($db, '2026-10-22');
$assert($panQty($restoreStore, '2026-10-23') === 8, 'a zeroed Friday order is restored from standing, not copied on top');
$restoreRow = bakery_pan_carry_find($db, '2026-10-22', $restoreStore);
$assert((string)($restoreRow['outcome'] ?? '') === 'restored' && (int)($restoreRow['restored_standing'] ?? 0) === 1, 'the standing restore is logged');
$restoreAudits = $db->prepare("SELECT action FROM field_audit WHERE entity_type = 'route_carry' AND entity_id = ? ORDER BY id");
$restoreAudits->execute([(int)($restoreRow['id'] ?? 0)]);
$restoreActions = $restoreAudits->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('carry', $restoreActions, true) && in_array('restore', $restoreActions, true), 'carry and restore each write an audit row');

$removeId = (int)($label['id'] ?? 0);
$assert(bakery_pan_carry_remove($db, $removeId, null) === true, 'one tap removes the carried stop');
$assert($assignmentCount($openThu, '2026-10-23') === 0, 'the removed stop is off Friday');
$removed = bakery_pan_carry_find($db, '2026-10-22', $openThu);
$assert((string)($removed['status'] ?? '') === 'removed', 'the removal stays on the carry row');
bakery_pan_carry_forward_day($db, '2026-10-22');
$assert($assignmentCount($openThu, '2026-10-23') === 0, 'a later carry does not put a removed stop back');
$removeAudit = $db->prepare("SELECT COUNT(*) FROM field_audit WHERE entity_type = 'route_carry' AND entity_id = ? AND action = 'remove'");
$removeAudit->execute([$removeId]);
$assert((int)$removeAudit->fetchColumn() === 1, 'removal writes an audit row');
$keptOff = $db->prepare('SELECT COUNT(*) FROM dated_route_removals WHERE customer_id = ? AND delivery_date = ?');
$keptOff->execute([$openThu, '2026-10-23']);
$assert((int)$keptOff->fetchColumn() === 1, 'removal records the existing one-day route removal');

$delivered = $insertCustomer('Delivered');
$insertStop($delivered, '2026-10-22', $driver, 11, $panId, 4, 2.25, 'delivered');
$cancelled = $insertCustomer('Cancelled');
$insertStop($cancelled, '2026-10-22', $driver, 12, $panId, 4, 2.25, 'cancelled');
$failed = $insertCustomer('Failed');
$insertStop($failed, '2026-10-22', $driver, 13, $panId, 4, 2.25, 'failed');
$skipped = $insertCustomer('Skipped');
$insertStop($skipped, '2026-10-22', $driver, 14, $panId, 4, 2.25, 'pending', "Skipped: no entregar\n");
$skipRow = $insertCustomer('Skip Row');
$insertStop($skipRow, '2026-10-22', $driver, 15, $panId, 4, 2.25, 'pending');
$db->prepare('INSERT INTO customer_delivery_skips (customer_id, skip_date) VALUES (?, ?)')->execute([$skipRow, '2026-10-22']);
$removedDay = $insertCustomer('Removed Day');
$insertStop($removedDay, '2026-10-22', $driver, 16, $panId, 4, 2.25, 'pending');
bakery_dated_route_removal_record($db, '2026-10-22', $removedDay, $driver);
$loafOnly = $insertCustomer('Loaf Only');
$insertStop($loafOnly, '2026-10-22', $driver, 17, $loafId, 4, 6.50, 'pending');
$zeroSpecial = $insertCustomer('Zero Special');
$zeroStop = $insertStop($zeroSpecial, '2026-10-22', $driver, 18, $panId, 4, 0.00, 'pending');
$db->prepare('INSERT INTO special_orders (customer_id, delivery_date, daily_order_id, note, status) VALUES (?, ?, ?, ?, ?)')
    ->execute([$zeroSpecial, '2026-10-22', $zeroStop['order_id'], 'side door', 'open']);
$specialId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO special_order_lines (special_order_id, line_kind, description, quantity, unit_price, line_total, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, 1)'
)->execute([$specialId, 'text', 'Tray', 2, 0, 0]);
$pricedSpecial = $insertCustomer('Priced Special');
$pricedStop = $insertStop($pricedSpecial, '2026-10-22', $driver, 19, $panId, 4, 2.25, 'pending');
$db->prepare('INSERT INTO special_orders (customer_id, delivery_date, daily_order_id, note, status) VALUES (?, ?, ?, ?, ?)')
    ->execute([$pricedSpecial, '2026-10-22', $pricedStop['order_id'], 'with pan', 'open']);
$pricedSpecialId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO special_order_lines (special_order_id, line_kind, description, quantity, unit_price, line_total, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, 1)'
)->execute([$pricedSpecialId, 'text', 'Cake', 1, 20, 20]);

bakery_pan_carry_forward_day($db, '2026-10-22');
foreach ([
    $delivered => 'delivered',
    $cancelled => 'cancelled',
    $failed => 'failed',
    $skipped => 'skipped note',
    $skipRow => 'customer skip',
    $removedDay => 'dated removal',
    $loafOnly => 'sour flour only',
    $zeroSpecial => 'zero-price special',
] as $customerId => $reason) {
    $assert(bakery_pan_carry_find($db, '2026-10-22', $customerId) === null, $reason . ' is not carried');
    $assert($assignmentCount($customerId, '2026-10-23') === 0, $reason . ' has no Friday stop');
}
$assert((string)(bakery_pan_carry_find($db, '2026-10-22', $pricedSpecial)['outcome'] ?? '') === 'carried', 'priced pan dulce with a special order still carries');

$todayOpen = $insertCustomer('Still Today');
$insertStop($todayOpen, '2026-10-24', $driver, 40, $panId, 4, 2.25, 'pending');
bakery_pan_carry_forward_recent($db, '2026-10-24');
$assert(bakery_pan_carry_find($db, '2026-10-24', $todayOpen) === null, 'the overnight window does not carry an open route dated today');

$board = bakery_pan_carry_board_html($db, '2026-10-26', ['role_slug' => 'manager', 'id' => 1]);
$assert(strpos($board, 'Pendiente de ayer') !== false || strpos($board, 'Left from yesterday') !== false, 'the board shows the carry label');
$assert(strpos($board, 'remove_pan_carry') !== false, 'a manager gets one remove control');
$assert(bakery_pan_carry_board_html($db, '2026-10-26', ['role_slug' => 'driver', 'id' => 2]) === '', 'a driver does not get the manager carry board');

echo "\n=== " . ($fail === 0 ? 'OK' : 'FAILED') . " failures=$fail ===\n";
exit($fail === 0 ? 0 : 1);
