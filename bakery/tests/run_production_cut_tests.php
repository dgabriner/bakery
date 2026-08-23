<?php
/**
 * Production cuts: when the bake is below demand, dated orders shrink to
 * what exists. Locked customers keep everything and their units leave the
 * pool first; unlocked shares are proportional and never above the current
 * order; applying writes dated lines only — standing never changes.
 *
 * Usage: php tests/run_production_cut_tests.php
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
require_once $root . '/includes/production_assign.php';

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

$doughTypeId = (int)$db->query('SELECT id FROM dough_types ORDER BY id LIMIT 1')->fetchColumn();
if ($doughTypeId <= 0) {
    fwrite(STDERR, "Need at least one dough type on bakerysf_test\n");
    exit(1);
}
$db->prepare(
    'INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, 1.00, 100)'
)->execute(['Cut Test Concha', $doughTypeId]);
$productId = (int)$db->lastInsertId();
if ($productId <= 0) {
    fwrite(STDERR, "Could not insert synthetic product\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+43 days'));
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date (weekday $weekday) product $productId\n";

$customerIds = [];
$cleanupCustomers = static function () use ($db, $date, &$customerIds): void {
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
    if (table_exists($db, 'operational_events')) {
        $db->prepare("DELETE FROM operational_events WHERE customer_id IN ($ph)")->execute($customerIds);
    }
    if (table_exists($db, 'customer_notifications')) {
        $db->prepare("DELETE FROM customer_notifications WHERE customer_id IN ($ph)")->execute($customerIds);
    }
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
    $customerIds = [];
};
$cleanup = static function () use ($db, $productId, $cleanupCustomers): void {
    $cleanupCustomers();
    if (table_exists($db, 'operational_events')) {
        $db->prepare('DELETE FROM operational_events WHERE product_id = ?')->execute([$productId]);
    }
    $db->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
};

try {
    $insertCustomer = $db->prepare(
        "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, 1, 'human')"
    );
    $insertCustomer->execute(['Cut Cafe Big', '10 Bake Lane']);
    $bigId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Cut Cafe Mid', '11 Bake Lane']);
    $midId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Cut Cafe Small', '12 Bake Lane']);
    $smallId = (int)$db->lastInsertId();
    $customerIds = [$bigId, $midId, $smallId];
    $assert($bigId > 0 && $midId > 0 && $smallId > 0, 'synthetic cut customers inserted');

    $standing = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    );
    $standing->execute([$bigId, $productId, $weekday, 30]);
    $standing->execute([$midId, $productId, $weekday, 20]);
    $standing->execute([$smallId, $productId, $weekday, 10]);

    $ensure = static function (PDO $db, int $customerId, string $orderDate, int $productId, int $qty): void {
        $db->prepare("INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)")
            ->execute([$customerId, $orderDate]);
        $orderId = (int)$db->lastInsertId();
        $product = bakery_customer_product_row($db, $productId);
        $customer = bakery_production_assign_customer_row($db, $customerId);
        $unit = $product && $customer ? bakery_resolve_customer_price($db, $customer, $product) : 1;
        $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$orderId, $productId, $qty, $unit, round($qty * $unit, 2)]);
    };

    $demandTotal = bakery_operating_demand_by_product($db, $date, ['product_id' => $productId]);
    $assert((int)($demandTotal['by_product'][$productId] ?? 0) === 60, 'demand starts at standing total of 60');

    $preview = bakery_production_cut_preview($db, $date, $productId, 42);
    $byId = [];
    foreach ($preview as $row) {
        $byId[(int)$row['id']] = $row;
    }
    $assert(count($preview) === 3, 'cut preview lists the three demanders');
    $assert((int)$byId[$bigId]['recommended'] === 21, 'cut recommends 21 for the large cafe');
    $assert((int)$byId[$midId]['recommended'] === 14, 'cut recommends 14 for the mid cafe');
    $assert((int)$byId[$smallId]['recommended'] === 7, 'cut recommends 7 for the small cafe');
    $recSum = (int)$byId[$bigId]['recommended'] + (int)$byId[$midId]['recommended'] + (int)$byId[$smallId]['recommended'];
    $assert($recSum === 42, 'cut recommendations sum to the pool');
    foreach ([$bigId, $midId, $smallId] as $cid) {
        $assert(
            (int)$byId[$cid]['recommended'] <= (int)$byId[$cid]['quantity'],
            'a cut never raises a customer'
        );
    }

    $applied = bakery_production_cut_apply($db, $date, $productId, [
        ['customer_id' => $bigId, 'quantity' => 21],
        ['customer_id' => $midId, 'quantity' => 14],
        ['customer_id' => $smallId, 'quantity' => 7],
    ], null);
    $assert((int)$applied['updated'] === 3, 'cut apply updates three customers');
    $assert((int)$applied['cut_units'] === 18, 'cut apply reports 18 units removed');

    $assert(bakery_customer_standing_qty($db, $bigId, $productId, $weekday) === 30, 'standing template stays 30');
    $assert(bakery_customer_standing_qty($db, $midId, $productId, $weekday) === 20, 'standing template stays 20');
    $assert(bakery_customer_standing_qty($db, $smallId, $productId, $weekday) === 10, 'standing template stays 10');

    $afterDemand = bakery_operating_demand_by_product($db, $date, ['product_id' => $productId]);
    $assert((int)($afterDemand['by_product'][$productId] ?? 0) === 42, 'dated demand lands on the pool of 42');

    $raiseRejected = false;
    try {
        bakery_production_cut_apply($db, $date, $productId, [
            ['customer_id' => $smallId, 'quantity' => 50],
        ], null);
    } catch (InvalidArgumentException $e) {
        $raiseRejected = true;
    }
    $assert($raiseRejected, 'raising a customer through a cut is rejected');

    $zeroed = bakery_production_cut_apply($db, $date, $productId, [
        ['customer_id' => $smallId, 'quantity' => 0],
    ], null);
    $assert((int)$zeroed['updated'] === 1 && (int)$zeroed['cut_units'] === 7, 'cutting to zero removes the dated override');
    $smallNow = null;
    foreach (bakery_operating_demand_customers_for_product($db, $date, $productId) as $line) {
        if ((int)$line['id'] === $smallId) {
            $smallNow = (int)$line['quantity'];
        }
    }
    $assert($smallNow === 10, 'zero-cut falls back to standing 10 (empty_daily semantics)');
    $assert(bakery_customer_standing_qty($db, $smallId, $productId, $weekday) === 10, 'standing template still intact after zero-cut');

    $cleanupCustomers();
    $insertCustomer->execute(['Cut Locked Cafe', '13 Bake Lane']);
    $lockedId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Cut Free Cafe', '14 Bake Lane']);
    $freeId = (int)$db->lastInsertId();
    $customerIds = [$lockedId, $freeId];
    $standing->execute([$lockedId, $productId, $weekday, 22]);
    $standing->execute([$freeId, $productId, $weekday, 18]);
    $ensure($db, $lockedId, $date, $productId, 22);
    $ensure($db, $freeId, $date, $productId, 18);
    $db->prepare("UPDATE daily_orders SET status = 'out_for_delivery' WHERE customer_id = ? AND order_date = ?")
        ->execute([$lockedId, $date]);

    $lockedPreview = bakery_production_cut_preview($db, $date, $productId, 30);
    $lockedRow = null;
    $freeRow = null;
    foreach ($lockedPreview as $row) {
        if ((int)$row['id'] === $lockedId) {
            $lockedRow = $row;
        }
        if ((int)$row['id'] === $freeId) {
            $freeRow = $row;
        }
    }
    $assert($lockedRow !== null && (int)$lockedRow['recommended'] === 22, 'locked cafe keeps its full 22');
    $assert($freeRow !== null && (int)$freeRow['recommended'] === 8, 'free cafe absorbs the rest with pool minus locked (8)');

    $lockedApply = bakery_production_cut_apply($db, $date, $productId, [
        ['customer_id' => $lockedId, 'quantity' => 5],
        ['customer_id' => $freeId, 'quantity' => 8],
    ], null);
    $assert((int)$lockedApply['updated'] === 1 && (int)$lockedApply['skipped'] === 1, 'van-locked order is skipped by apply');
    $assert(bakery_customer_standing_qty($db, $lockedId, $productId, $weekday) === 22, 'locked standing unchanged');
} catch (Throwable $e) {
    echo 'FAIL  exception: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
