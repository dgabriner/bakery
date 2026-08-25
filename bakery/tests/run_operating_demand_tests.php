<?php
/**
 * Dated beats standing per customer — never all-or-nothing per date.
 * Usage: php tests/run_operating_demand_tests.php
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
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/customer_order_mutations.php';
require_once $root . '/includes/ingredient_requirements.php';

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

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if ($productId <= 0) {
    fwrite(STDERR, "Need at least one product on bakerysf_test\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+45 days'));
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date (weekday $weekday) product $productId\n";

// An interrupted earlier run leaves the named fixture customers behind and
// every rerun then double-counts them. Clear any orphans before inserting.
$orphanCleanup = static function () use ($db): void {
    $stale = $db->query(
        "SELECT id FROM customers WHERE name IN ('Demand Flip Cafe', 'Demand Flip Market')"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stale as $cid) {
        $cid = (int)$cid;
        $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ?');
        $orderIds->execute([$cid]);
        $ids = $orderIds->fetchAll(PDO::FETCH_COLUMN);
        if ($ids) {
            $oph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
        }
        $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$cid]);
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$cid]);
    }
};
$orphanCleanup();

// The snapshot may already carry real standing demand for this product on
// this weekday. Assert our fixtures' contribution as a delta, never absolute.
$baselineQty = (int)(bakery_operating_demand_by_product($db, $date)['by_product'][$productId] ?? 0);

$customerIds = [];
$cleanup = static function () use ($db, $date, &$customerIds): void {
    if ($customerIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($customerIds), '?'));
    $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date = ?");
    $orderIds->execute(array_merge($customerIds, [$date]));
    $ids = $orderIds->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $oph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
    }
    $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($customerIds);
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    $customerIds = [];
};

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertCustomer->execute(['Demand Flip Cafe', '1 Merge Lane']);
    $datedCustomer = (int)$db->lastInsertId();
    $insertCustomer->execute(['Demand Flip Market', '2 Standing Lane']);
    $standingCustomer = (int)$db->lastInsertId();
    $customerIds = [$datedCustomer, $standingCustomer];
    $assert($datedCustomer > 0 && $standingCustomer > 0, 'synthetic customers inserted');

    $standing = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    );
    $standing->execute([$datedCustomer, $productId, $weekday, 10]);
    $standing->execute([$standingCustomer, $productId, $weekday, 7]);

    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, ?, 0)'
    )->execute([$datedCustomer, $date, 'pending']);
    $orderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, 0, 0)'
    )->execute([$orderId, $productId, 3]);

    $demand = bakery_operating_demand_by_product($db, $date);
    $got = (int)($demand['by_product'][$productId] ?? 0);
    $assert($got === $baselineQty + 10, 'mixed date adds dated 3 + standing 7 on top of baseline (got ' . $got . ', baseline ' . $baselineQty . ')');
    $assert(($demand['mix']['mode'] ?? '') === 'merged', 'mix mode is merged when both sources exist');
    $assert(!empty($demand['has_daily']), 'has_daily is true when any dated line exists');
    $assert(($demand['sources'][$productId] ?? '') === 'mixed', 'product source is mixed, not last-write daily');

    $customers = bakery_operating_demand_customers_for_product($db, $date, $productId);
    $byId = [];
    foreach ($customers as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $assert(isset($byId[$datedCustomer], $byId[$standingCustomer]), 'explorer lists both customers');
    $assert((int)$byId[$datedCustomer]['quantity'] === 3 && $byId[$datedCustomer]['source'] === 'daily', 'dated customer contributes 3 as daily');
    $assert((int)$byId[$standingCustomer]['quantity'] === 7 && $byId[$standingCustomer]['source'] === 'standing', 'standing-only customer still contributes 7');

    $page = file_get_contents($root . DIRECTORY_SEPARATOR . 'product_distribution.php');
    $assert(
        is_string($page) && strpos($page, 'bakery_operating_demand_by_product') !== false,
        'product_distribution uses operating demand helper'
    );
    $assert(
        is_string($page) && strpos($page, '$demand[\'has_daily\']') === false,
        'product_distribution chip is not the old all-or-nothing has_daily flag'
    );

    $loaded = bakery_ingredient_requirements_load_products($db, $date, 'demand');
    $ingQty = null;
    foreach ($loaded['products'] as $row) {
        if ((int)$row['product_id'] === $productId) {
            $ingQty = (int)$row['demand_quantity'];
            break;
        }
    }
    $assert($ingQty === $baselineQty + 10, 'ingredient demand source uses merged operating demand (got ' . var_export($ingQty, true) . ', baseline ' . $baselineQty . ')');
    $assert(
        ($loaded['demand_mode'] ?? '') === 'merged',
        'ingredient demand_mode is merged, not daily_orders-only (got ' . (string)($loaded['demand_mode'] ?? '') . ')'
    );
} catch (Throwable $e) {
    echo 'FAIL  ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

$lifeDate = date('Y-m-d', strtotime('+52 days'));
$lifeWeekday = bakery_standing_day_from_date($lifeDate);
$pastDate = date('Y-m-d', strtotime('-12 days'));
$lifeIds = [];
$lifeCleanup = static function () use ($db, $lifeDate, $pastDate, &$lifeIds): void {
    if ($lifeIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($lifeIds), '?'));
    $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date IN (?, ?)");
    $orderIds->execute(array_merge($lifeIds, [$lifeDate, $pastDate]));
    $ids = $orderIds->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) {
        $oph = implode(',', array_fill(0, count($ids), '?'));
        if (table_exists($db, 'daily_order_assignments')) {
            $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
        }
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
    }
    if (table_exists($db, 'standing_order_pauses')) {
        $db->prepare("DELETE FROM standing_order_pauses WHERE customer_id IN ($ph)")->execute($lifeIds);
    }
    $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($lifeIds);
    if (table_exists($db, 'standing_routes')) {
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($lifeIds);
    }
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($lifeIds);
    $lifeIds = [];
};
$lifeCleanup();

try {
    $insertLife = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertLife->execute(['Inactive Future Cafe', '10 Close Lane']);
    $genId = (int)$db->lastInsertId();
    $insertLife->execute(['Inactive History Cafe', '11 History Lane']);
    $histId = (int)$db->lastInsertId();
    $insertLife->execute(['Inactive Edited Cafe', '12 Edit Lane']);
    $editId = (int)$db->lastInsertId();
    $insertLife->execute(['Inactive Pause Cafe', '13 Pause Lane']);
    $pauseId = (int)$db->lastInsertId();
    $lifeIds = [$genId, $histId, $editId, $pauseId];
    $assert($genId > 0 && $histId > 0 && $editId > 0 && $pauseId > 0, 'inactive-lifecycle fixture customers inserted');

    $standingLife = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    );
    $standingLife->execute([$genId, $productId, $lifeWeekday, 9]);
    $standingLife->execute([$editId, $productId, $lifeWeekday, 9]);
    $standingLife->execute([$pauseId, $productId, $lifeWeekday, 4]);

    if (table_exists($db, 'standing_order_pauses')) {
        $db->prepare('INSERT INTO standing_order_pauses (customer_id, week_start) VALUES (?, ?)')
            ->execute([$pauseId, bakery_week_start_monday($lifeDate)]);
    }

    $driverId = 0;
    if (table_exists($db, 'drivers')) {
        $driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
    }

    bakery_generate_daily_orders_from_standing($db, $lifeDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => $driverId > 0,
    ]);

    $orderFor = static function (int $cid) use ($db, $lifeDate): int {
        $st = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?');
        $st->execute([$cid, $lifeDate]);
        return (int)$st->fetchColumn();
    };
    $genOrderId = $orderFor($genId);
    $editOrderId = $orderFor($editId);
    $assert($genOrderId > 0, 'scenario A generated a future dated order while active');
    $assert($editOrderId > 0, 'scenario C generated a future dated order to edit');

    $db->prepare('UPDATE daily_order_items SET quantity = 15, line_total = 0 WHERE daily_order_id = ? AND product_id = ?')
        ->execute([$editOrderId, $productId]);

    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, 0)'
    )->execute([$histId, $pastDate, 'delivered']);
    $histOrderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 0, 0)'
    )->execute([$histOrderId, $productId, 6]);

    $retA = bakery_customer_apply_active_status($db, $genId, false, 'closed for season');
    $assert((int)$retA['retired']['orders_retired'] >= 1, 'scenario A retired the generated future order');
    $itemCount = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ?');
    $itemCount->execute([$genOrderId]);
    $assert((int)$itemCount->fetchColumn() === 0, 'scenario A cleared future items, kept the dated shell');
    $shell = $db->prepare('SELECT id FROM daily_orders WHERE id = ?');
    $shell->execute([$genOrderId]);
    $assert((int)$shell->fetchColumn() === $genOrderId, 'scenario A did not delete the dated order row');

    $lifeById = [];
    foreach (bakery_operating_demand_customers_for_product($db, $lifeDate, $productId) as $row) {
        $lifeById[(int)$row['id']] = $row;
    }
    $assert(!isset($lifeById[$genId]), 'scenario A inactive generated demand is out of operating demand');

    bakery_generate_daily_orders_from_standing($db, $lifeDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $itemCount->execute([$genOrderId]);
    $assert((int)$itemCount->fetchColumn() === 0, 'scenario A regeneration does not recreate inactive demand');

    $retB = bakery_customer_apply_active_status($db, $histId, false, 'closed');
    $histItems = $db->prepare('SELECT quantity FROM daily_order_items WHERE daily_order_id = ?');
    $histItems->execute([$histOrderId]);
    $assert((int)$histItems->fetchColumn() === 6, 'scenario B historical delivered items unchanged');
    $histById = [];
    foreach (bakery_operating_demand_customers_for_product($db, $pastDate, $productId) as $row) {
        $histById[(int)$row['id']] = $row;
    }
    $assert(isset($histById[$histId]) && (int)$histById[$histId]['quantity'] === 6, 'scenario B historical demand still visible on its date');
    $assert((int)($retB['retired']['orders_retired'] ?? 0) === 0, 'scenario B retired no historical orders');

    $retC = bakery_customer_apply_active_status($db, $editId, false, 'moved away');
    $assert((int)$retC['retired']['orders_retired'] >= 1, 'scenario C retires future manually edited demand');
    $itemCount->execute([$editOrderId]);
    $assert((int)$itemCount->fetchColumn() === 0, 'scenario C cleared edited future items');
    $editCust = [];
    foreach (bakery_operating_demand_customers_for_product($db, $lifeDate, $productId) as $row) {
        $editCust[(int)$row['id']] = true;
    }
    $assert(!isset($editCust[$editId]), 'scenario C edited future demand is out of operating demand');

    $pauseSeen = false;
    foreach (bakery_operating_demand_customers_for_product($db, $lifeDate, $productId) as $row) {
        if ((int)$row['id'] === $pauseId) {
            $pauseSeen = true;
        }
    }
    $assert(!$pauseSeen, 'scenario D paused active customer has no operating demand');
    $pauseActive = (int)$db->query('SELECT is_active FROM customers WHERE id = ' . (int)$pauseId)->fetchColumn();
    $assert($pauseActive === 1, 'scenario D pause is not inactivity');
    $pauseHasDaily = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $pauseHasDaily->execute([$pauseId, $lifeDate]);
    $assert((int)$pauseHasDaily->fetchColumn() === 0, 'scenario D pause never materialized a dated order');

    if ($driverId > 0 && table_exists($db, 'daily_order_assignments') && $genOrderId > 0) {
        $assignCount = $db->prepare('SELECT COUNT(*) FROM daily_order_assignments WHERE daily_order_id = ?');
        $assignCount->execute([$genOrderId]);
        $assert((int)$assignCount->fetchColumn() === 0, 'scenario E pending future assignment removed on deactivate');
    } else {
        echo "NOTE  no driver/assignments table — skip scenario E assignment assert\n";
    }

    $again = bakery_customer_apply_active_status($db, $genId, false, 'closed for season');
    $assert((int)$again['retired']['orders_retired'] === 0, 'scenario F second deactivate is a no-op');
    $itemCount->execute([$genOrderId]);
    $assert((int)$itemCount->fetchColumn() === 0, 'scenario F remains retired');

    bakery_customer_apply_active_status($db, $genId, true, '');
    $activeAgain = (int)$db->query('SELECT is_active FROM customers WHERE id = ' . (int)$genId)->fetchColumn();
    $assert($activeAgain === 1, 'scenario G customer is active again');
    $itemCount->execute([$genOrderId]);
    $assert((int)$itemCount->fetchColumn() === 0, 'scenario G does not auto-revive retired items');
    bakery_generate_daily_orders_from_standing($db, $lifeDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => false,
    ]);
    $itemCount->execute([$genOrderId]);
    $assert((int)$itemCount->fetchColumn() === 1, 'scenario G generation recreates standing-derived items on the existing shell');
    $qtyStmt = $db->prepare('SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?');
    $qtyStmt->execute([$genOrderId, $productId]);
    $assert((int)$qtyStmt->fetchColumn() === 9, 'scenario G regenerated quantity matches standing');

    bakery_customer_apply_active_status($db, $editId, true, '');
    $db->prepare('UPDATE daily_orders SET status = ? WHERE id = ?')->execute(['out_for_delivery', $editOrderId]);
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 0, 0)'
    )->execute([$editOrderId, $productId, 2]);
    $prot = bakery_customer_apply_active_status($db, $editId, false, 'closed while rolling');
    $assert((int)$prot['retired']['orders_protected'] >= 1, 'in-progress future order is protected');
    $qtyStmt->execute([$editOrderId, $productId]);
    $assert((int)$qtyStmt->fetchColumn() === 2, 'protected in-progress items are not cleared');
} catch (Throwable $e) {
    echo 'FAIL  inactive lifecycle: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $lifeCleanup();
}

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
