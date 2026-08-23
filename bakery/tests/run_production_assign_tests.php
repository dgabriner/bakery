<?php
/**
 * Production → order assignment: recommend proportional cuts; standing
 * writes the template and today's dated line; daily writes dated only;
 * later same-weekday dated copies of the old standing follow; van-locked
 * orders are skipped.
 *
 * Usage: php tests/run_production_assign_tests.php
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

$share = bakery_production_assign_recommend([
    ['id' => 1, 'quantity' => 1500],
    ['id' => 2, 'quantity' => 1000],
    ['id' => 3, 'quantity' => 500],
], 1400);
$assert(($share[0]['recommended'] ?? null) === 700, 'recommend 1500/3000 of 1400 is 700');
$assert(($share[1]['recommended'] ?? null) === 467, 'recommend 1000/3000 of 1400 is 467');
$assert(($share[2]['recommended'] ?? null) === 233, 'recommend 500/3000 of 1400 is 233');
$assert(
    (int)$share[0]['recommended'] + (int)$share[1]['recommended'] + (int)$share[2]['recommended'] === 1400,
    'recommend totals the pool'
);

$even = bakery_production_assign_recommend([
    ['quantity' => 10],
    ['quantity' => 10],
    ['quantity' => 10],
], 100);
$assert(
    (int)$even[0]['recommended'] + (int)$even[1]['recommended'] + (int)$even[2]['recommended'] === 100,
    'equal customers split the pool'
);

$zero = bakery_production_assign_recommend([['quantity' => 0], ['quantity' => 0]], 50);
$assert(($zero[0]['recommended'] ?? null) === 0 && ($zero[1]['recommended'] ?? null) === 0, 'zero demand stays zero');

$assert(
    bakery_production_assign_order_is_locked('out_for_delivery', 'pending') === true,
    'van lock is locked'
);
$assert(
    bakery_production_assign_order_is_locked('in_production', 'pending') === false,
    'in production can still be assigned'
);
$assert(
    bakery_production_assign_order_is_locked('ready', null) === false,
    'ready can still be assigned'
);

$poolRow = bakery_production_assign_pool_from_row(['hasPlan' => true, 'planned' => 1400, 'onHand' => 80, 'confirmed' => 80]);
$assert($poolRow['pool'] === 1400 && $poolRow['source'] === 'planned', 'pool prefers planned when saved');
$onHandPool = bakery_production_assign_pool_from_row(['hasPlan' => false, 'planned' => 0, 'onHand' => 80, 'confirmed' => 10]);
$assert($onHandPool['pool'] === 80 && $onHandPool['source'] === 'on_hand', 'pool falls back to on-hand');

$doughTypeId = (int)$db->query('SELECT id FROM dough_types ORDER BY id LIMIT 1')->fetchColumn();
if ($doughTypeId <= 0) {
    fwrite(STDERR, "Need at least one dough type on bakerysf_test\n");
    exit(1);
}
$db->prepare(
    'INSERT INTO products (name, dough_type_id, price, weight_grams) VALUES (?, ?, 1.00, 100)'
)->execute(['Assign Test Concha', $doughTypeId]);
$productId = (int)$db->lastInsertId();
if ($productId <= 0) {
    fwrite(STDERR, "Could not insert synthetic product\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+42 days'));
$nextWeek = date('Y-m-d', strtotime($date . ' +7 days'));
$weekday = bakery_standing_day_from_date($date);
echo "Test dates: $date and $nextWeek (weekday $weekday) product $productId\n";

$customerIds = [];
$cleanupCustomers = static function () use ($db, $date, $nextWeek, &$customerIds): void {
    if ($customerIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($customerIds), '?'));
    $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date IN (?, ?)");
    $orderIds->execute(array_merge($customerIds, [$date, $nextWeek]));
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
    $insertCustomer->execute(['Assign Cafe Big', '1 Bake Lane']);
    $bigId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Assign Cafe Mid', '2 Bake Lane']);
    $midId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Assign Cafe Small', '3 Bake Lane']);
    $smallId = (int)$db->lastInsertId();
    $customerIds = [$bigId, $midId, $smallId];
    $assert($bigId > 0 && $midId > 0 && $smallId > 0, 'synthetic customers inserted');

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
    foreach ([[$bigId, 30], [$midId, 20], [$smallId, 10]] as $pair) {
        $ensure($db, $pair[0], $date, $productId, $pair[1]);
        $ensure($db, $pair[0], $nextWeek, $productId, $pair[1]);
    }

    $preview = bakery_production_assign_preview($db, $date, $productId, 30);
    $assert(count($preview) === 3, 'preview lists the three demanders');
    $previewById = [];
    foreach ($preview as $row) {
        $previewById[(int)$row['id']] = $row;
    }
    $assert((int)$previewById[$bigId]['recommended'] === 15, 'preview recommends 15 for the large cafe');
    $assert((int)$previewById[$midId]['recommended'] === 10, 'preview recommends 10 for the mid cafe');
    $assert((int)$previewById[$smallId]['recommended'] === 5, 'preview recommends 5 for the small cafe');

    $applied = bakery_production_assign_apply($db, $date, $productId, [
        ['customer_id' => $bigId, 'quantity' => 15],
        ['customer_id' => $midId, 'quantity' => 10],
        ['customer_id' => $smallId, 'quantity' => 5],
    ], 'standing', null);
    $assert((int)$applied['updated'] === 3, 'standing apply updates three customers');
    $assert((int)$applied['follow_on'] === 3, 'standing apply follows next week copies');

    $assert(bakery_customer_standing_qty($db, $bigId, $productId, $weekday) === 15, 'standing template is 15');
    $assert(bakery_customer_standing_qty($db, $midId, $productId, $weekday) === 10, 'standing template is 10');
    $assert(bakery_customer_standing_qty($db, $smallId, $productId, $weekday) === 5, 'standing template is 5');

    $demandToday = bakery_operating_demand_by_product($db, $date, ['product_id' => $productId]);
    $assert((int)($demandToday['by_product'][$productId] ?? 0) === 30, 'today demand is the assigned 30');
    $demandNext = bakery_operating_demand_by_product($db, $nextWeek, ['product_id' => $productId]);
    $assert((int)($demandNext['by_product'][$productId] ?? 0) === 30, 'next week unedited copies followed standing');

    $cleanupCustomers();
    $insertCustomer->execute(['Assign One-off Cafe', '4 Bake Lane']);
    $oneOffId = (int)$db->lastInsertId();
    $insertCustomer->execute(['Assign Exception Cafe', '5 Bake Lane']);
    $exId = (int)$db->lastInsertId();
    $customerIds = [$oneOffId, $exId];
    $standing->execute([$oneOffId, $productId, $weekday, 40]);
    $standing->execute([$exId, $productId, $weekday, 40]);
    $ensure($db, $oneOffId, $date, $productId, 40);
    $ensure($db, $exId, $date, $productId, 40);
    $ensure($db, $oneOffId, $nextWeek, $productId, 40);
    $ensure($db, $exId, $nextWeek, $productId, 99);

    $dailyApply = bakery_production_assign_apply($db, $date, $productId, [
        ['customer_id' => $oneOffId, 'quantity' => 12],
    ], 'daily', null);
    $assert((int)$dailyApply['updated'] === 1, 'daily apply updates one customer');
    $assert((int)$dailyApply['standing'] === 0, 'daily apply does not write standing counts');
    $assert(bakery_customer_standing_qty($db, $oneOffId, $productId, $weekday) === 40, 'one-off leaves standing at 40');
    $todayLines = bakery_operating_demand_customers_for_product($db, $date, $productId);
    $todayQty = 0;
    $nextQtyEx = 0;
    $nextQtyOne = 0;
    foreach ($todayLines as $line) {
        if ((int)$line['id'] === $oneOffId) {
            $todayQty = (int)$line['quantity'];
        }
    }
    foreach (bakery_operating_demand_customers_for_product($db, $nextWeek, $productId) as $line) {
        if ((int)$line['id'] === $oneOffId) {
            $nextQtyOne = (int)$line['quantity'];
        }
        if ((int)$line['id'] === $exId) {
            $nextQtyEx = (int)$line['quantity'];
        }
    }
    $assert($todayQty === 12, 'one-off dated quantity is 12 today');
    $assert($nextQtyOne === 40, 'one-off does not rewrite next week');

    $standingApply = bakery_production_assign_apply($db, $date, $productId, [
        ['customer_id' => $exId, 'quantity' => 8],
    ], 'standing', null);
    $assert((int)$standingApply['follow_on'] === 0, 'dated exception next week is not rewritten');
    $assert(bakery_customer_standing_qty($db, $exId, $productId, $weekday) === 8, 'exception cafe standing is 8');
    foreach (bakery_operating_demand_customers_for_product($db, $nextWeek, $productId) as $line) {
        if ((int)$line['id'] === $exId) {
            $nextQtyEx = (int)$line['quantity'];
        }
    }
    $assert($nextQtyEx === 99, 'next week exception stays 99');

    $cleanupCustomers();
    $insertCustomer->execute(['Assign Locked Cafe', '6 Bake Lane']);
    $lockedId = (int)$db->lastInsertId();
    $customerIds = [$lockedId];
    $standing->execute([$lockedId, $productId, $weekday, 22]);
    $ensure($db, $lockedId, $date, $productId, 22);
    $db->prepare("UPDATE daily_orders SET status = 'out_for_delivery' WHERE customer_id = ? AND order_date = ?")
        ->execute([$lockedId, $date]);
    $lockedApply = bakery_production_assign_apply($db, $date, $productId, [
        ['customer_id' => $lockedId, 'quantity' => 1],
    ], 'standing', null);
    $assert((int)$lockedApply['updated'] === 0 && (int)$lockedApply['skipped'] === 1, 'van-locked order is skipped');
    $assert(bakery_customer_standing_qty($db, $lockedId, $productId, $weekday) === 22, 'locked standing is unchanged');
} catch (Throwable $e) {
    echo 'FAIL  exception: ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
