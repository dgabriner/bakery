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

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
