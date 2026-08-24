<?php
/**
 * Visit-compare: yesterday assigned/actual vs standing vs dated for the next day.
 * Usage: php tests/run_demand_visit_compare_tests.php
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
require_once $root . '/includes/demand_review.php';
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/operational_timeline.php';

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

$expected = bakery_demand_last_expected_standing_date([1, 3], '2026-08-25');
$assert(is_array($expected) && $expected['date'] === '2026-08-24', 'last expected standing before Tue 8/25 is Mon 8/24');
$weekly = bakery_demand_last_expected_standing_date([2], '2026-08-25');
$assert(is_array($weekly) && $weekly['date'] === '2026-08-18', 'Tuesday-only standing last expected is previous Tuesday');
$assert(bakery_demand_last_expected_standing_date([], '2026-08-25') === null, 'no standing days means no expected visit');

$page = (string)file_get_contents($root . DIRECTORY_SEPARATOR . 'daily_orders.php');
$assert(strpos($page, "viewMode === 'visit'") !== false, 'Daily Orders has visit compare view');
$assert(strpos($page, 'bakery_demand_visit_compare_build') !== false, 'Daily Orders loads visit compare helper');
$assert(strpos($page, 'copy_prior_to_dated') !== false, 'Daily Orders can copy yesterday onto dated');
$assert(strpos($page, 'visit-driver-board') !== false, 'Daily Orders visit view is a driver route board');
$assert(strpos($page, 'visit-route-scan') !== false, 'Daily Orders visit view shows standing vs yesterday stop lists');
$assert(strpos($page, "'deviations'") !== false, 'Daily Orders visit view defaults to deviations');

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
if ($productId <= 0 || $driverId <= 0) {
    fwrite(STDERR, "Need a product and a driver on bakerysf_test\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+52 days'));
$prior = date('Y-m-d', strtotime($date . ' -1 day'));
$quietActual = date('Y-m-d', strtotime($date . ' -7 days'));
$dateDow = bakery_standing_day_from_date($date);
$priorDow = bakery_standing_day_from_date($prior);
echo "Test dates: prior $prior (dow $priorDow) → $date (dow $dateDow) product $productId driver $driverId\n";

$names = ['Visit Compare Cafe', 'Visit Overdue Market', 'Visit Quiet Cafe'];
$cleanup = static function () use ($db, $date, $prior, $quietActual, $names): void {
    $stale = $db->query(
        "SELECT id FROM customers WHERE name IN ('Visit Compare Cafe', 'Visit Overdue Market', 'Visit Quiet Cafe')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stale as $cid) {
        $cid = (int)$cid;
        $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date IN (?, ?, ?)');
        $orderIds->execute([$cid, $date, $prior, $quietActual]);
        $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $oph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
        }
        $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$cid]);
        if (table_exists($db, 'standing_routes')) {
            $db->prepare('DELETE FROM standing_routes WHERE customer_id = ?')->execute([$cid]);
        }
        if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
            $db->prepare('DELETE FROM operational_events WHERE customer_id = ?')->execute([$cid]);
        }
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
    }
};
$cleanup();

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertCustomer->execute(['Visit Compare Cafe', '1 Visit Lane']);
    $cafeId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Visit Overdue Market', '2 Gap Lane']);
    $overdueId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Visit Quiet Cafe', '3 Plan Lane']);
    $quietId = (int)$db->lastInsertId();
    $assert($cafeId > 0 && $overdueId > 0 && $quietId > 0, 'synthetic visit-compare customers inserted');

    $standing = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );
    $standing->execute([$cafeId, $productId, $priorDow, 4]);
    $standing->execute([$cafeId, $productId, $dateDow, 6]);
    $standing->execute([$overdueId, $productId, $dateDow, 5]);
    $standing->execute([$quietId, $productId, $dateDow, 2]);

    if (table_exists($db, 'standing_routes')) {
        $route = $db->prepare(
            'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order)
             VALUES (?, ?, ?, ?)'
        );
        $route->execute([$priorDow, $driverId, $cafeId, 1]);
        $route->execute([$dateDow, $driverId, $cafeId, 1]);
        $route->execute([$dateDow, $driverId, $overdueId, 2]);
        $route->execute([$dateDow, $driverId, $quietId, 3]);
    }

    $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, 'delivered', 0)"
    )->execute([$cafeId, $prior]);
    $priorOrderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
         VALUES (?, ?, 4, 3, 0, 0)'
    )->execute([$priorOrderId, $productId]);
    $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, 91, '08:00:00', 'delivered')"
    )->execute([$priorOrderId, $driverId, $prior]);

    $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, 'delivered', 0)"
    )->execute([$quietId, $quietActual]);
    $quietHistId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
         VALUES (?, ?, 2, 2, 0, 0)'
    )->execute([$quietHistId, $productId]);
    $db->prepare(
        "INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, 5, '08:00:00', 'delivered')"
    )->execute([$quietHistId, $driverId, $quietActual]);

    $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, 'pending', 0)"
    )->execute([$quietId, $date]);
    $quietTodayId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, 2, 0, 0)'
    )->execute([$quietTodayId, $productId]);

    $compare = bakery_demand_visit_compare_build($db, $date, ['visit' => 'all']);
    $byId = [];
    foreach ($compare['customers'] as $row) {
        $byId[(int)$row['customer_id']] = $row;
    }
    $assert(isset($byId[$cafeId], $byId[$overdueId], $byId[$quietId]), 'visit compare includes standing-today and yesterday-visited stores');
    $cafe = $byId[$cafeId];
    $assert(($cafe['prior']['visit_kind'] ?? '') === 'delivered', 'cafe was delivered yesterday');
    $assert(!empty($cafe['flags']['consecutive']), 'cafe is consecutive: delivered yesterday and standing today');
    $assert(($cafe['route_call'] ?? '') === 'back_to_back', 'cafe route call is back-to-back');
    $assert(($cafe['last_actual']['date'] ?? '') === $prior, 'last actual visit is yesterday');
    $assert(($cafe['last_expected']['date'] ?? '') === $prior, 'last expected standing day is yesterday');
    $assert((int)$cafe['today']['standing_units'] === 6, 'today standing units are 6');

    $overdue = $byId[$overdueId];
    $assert(!empty($overdue['flags']['overdue']), 'store with standing today and no recent delivery is overdue');
    $assert(($overdue['prior']['visit_kind'] ?? '') === 'none', 'overdue store had no yesterday stop');
    $assert(bakery_demand_visit_is_deviation($overdue['route_call'] ?? ''), 'overdue is a deviation');

    $quiet = $byId[$quietId];
    $assert(($quiet['route_call'] ?? '') === 'due', 'quiet cafe with standing, dated, and last-week delivery is on plan');
    $assert(empty($quiet['flags']['is_deviation']), 'on-plan stop is not a deviation');

    $driverGroups = $compare['drivers'] ?? [];
    $assert($driverGroups !== [], 'visit compare groups stops by driver');
    $board = null;
    foreach ($driverGroups as $group) {
        if ((int)$group['driver_id'] === $driverId) {
            $board = $group;
            break;
        }
    }
    $assert($board !== null, 'cafe driver board exists');
    $standingNames = array_column($board['standing_names'] ?? [], 'customer_name');
    $assert(in_array('Visit Compare Cafe', $standingNames, true), 'standing list includes cafe');
    $assert(in_array('Visit Quiet Cafe', $standingNames, true), 'standing list includes on-plan cafe');
    $priorNames = array_column($board['prior_names'] ?? [], 'customer_name');
    $assert(in_array('Visit Compare Cafe', $priorNames, true), 'yesterday list includes delivered cafe');

    $devs = bakery_demand_visit_compare_build($db, $date, ['visit' => 'deviations']);
    $devIds = array_map('intval', array_column($devs['customers'], 'customer_id'));
    $assert(in_array($cafeId, $devIds, true), 'deviations include back-to-back cafe');
    $assert(in_array($overdueId, $devIds, true), 'deviations include overdue market');
    $assert(!in_array($quietId, $devIds, true), 'deviations hide on-plan quiet cafe');
    $devBoard = null;
    foreach ($devs['drivers'] as $group) {
        if ((int)$group['driver_id'] === $driverId) {
            $devBoard = $group;
            break;
        }
    }
    $assert($devBoard !== null, 'deviations still keep the driver board');
    $listedDevIds = array_map(static function ($stop) {
        return (int)$stop['customer_id'];
    }, $devBoard['stops']);
    $assert(!in_array($quietId, $listedDevIds, true), 'look table omits on-plan stop');
    $assert(in_array('Visit Quiet Cafe', array_column($devBoard['standing_names'], 'customer_name'), true), 'standing pills still show on-plan stop');

    $set = bakery_demand_set_dated_quantity($db, $cafeId, $date, $productId, 8);
    $assert(!empty($set['changed']) && (int)$set['quantity'] === 8, 'set dated quantity writes 8 for this date');
    $dated = bakery_customer_daily_order_row($db, $cafeId, $date);
    $assert($dated !== null, 'setting a quantity created the dated order');
    $items = bakery_customer_daily_items($db, (int)$dated['id']);
    $assert((int)$items[0]['quantity'] === 8, 'dated line is 8 after inline set');

    $copy = bakery_demand_copy_prior_quantities_to_dated($db, $cafeId, $date);
    $assert((int)$copy['copied'] >= 1, 'copy yesterday overlays at least one product');
    $assert($copy['source'] === 'prior_actual', 'copy prefers delivered quantity when present');
    $items = bakery_customer_daily_items($db, (int)$copy['daily_order_id']);
    $byPid = [];
    foreach ($items as $item) {
        $byPid[(int)$item['product_id']] = (int)$item['quantity'];
    }
    $assert(($byPid[$productId] ?? 0) === 3, 'copy yesterday used delivered 3, not ordered 4 or standing 6');

    $standingRow = $db->prepare(
        'SELECT quantity FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ?'
    );
    $standingRow->execute([$cafeId, $productId, $dateDow]);
    $assert((int)$standingRow->fetchColumn() === 6, 'copy yesterday did not write standing');

    $applied = bakery_demand_apply_standing_to_dated($db, $overdueId, $date);
    $assert(!empty($applied['created']) && (int)$applied['item_count'] === 1, 'apply standing creates dated from weekday template');
    $overdueDaily = bakery_customer_daily_items($db, (int)$applied['daily_order_id']);
    $assert((int)$overdueDaily[0]['quantity'] === 5, 'applied standing quantity is 5');
} finally {
    $cleanup();
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
