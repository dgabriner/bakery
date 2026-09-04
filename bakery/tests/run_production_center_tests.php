<?php
/**
 * Characterization: Production Center helpers (save / commit / drift / assign).
 *
 * §4 invariants asserted by name:
 *   - Standing = template/forecast; dated = commercial commitment
 *   - Post-commit demand drift is loud; bake sheet stays on committed snapshot
 *
 * Broader CAS / horizon coverage lives in run_production_plan_commit_tests and
 * run_production_assign_tests — this suite is the page-lane smoke for Wave 3.
 *
 * CLI / local bakerysf_test only. Cleans up synthetic customers/dates.
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
require_once $root . '/includes/production_plan.php';
require_once $root . '/includes/production_assign.php';
require_once $root . '/includes/demand_review.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/operational_timeline.php';
require_once $root . '/includes/customer_order_mutations.php';

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

$date = date('Y-m-d', strtotime('+51 days'));
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date (standing day $weekday)\n";
echo "INVARIANT  Standing = template/forecast; dated = commercial commitment\n";
echo "INVARIANT  Post-commit demand drift is loud; bake sheet stays on committed snapshot\n";

$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();
if ($productId <= 0) {
    fwrite(STDERR, "Need a catalog product with dough/line on bakerysf_test\n");
    exit(1);
}

$customerIds = [];
$cleanup = static function () use ($db, $date, $productId, &$customerIds): void {
    if (table_exists($db, 'production_plan_commit_items')) {
        $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_commits')) {
        $db->prepare('DELETE FROM production_plan_commits WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_items')) {
        $db->prepare('DELETE FROM production_plan_items WHERE delivery_date=?')->execute([$date]);
    }
    if ($customerIds !== []) {
        $ph = implode(',', array_fill(0, count($customerIds), '?'));
        $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date = ?");
        $orderIds->execute(array_merge($customerIds, [$date]));
        $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids) {
            $oph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
        }
        $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($customerIds);
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($customerIds);
        $customerIds = [];
    } else {
        $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date=?)')
            ->execute([$date]);
        $db->prepare('DELETE FROM daily_orders WHERE order_date=?')->execute([$date]);
    }
    if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date=?')->execute([$date]);
    }
};

$cleanup();
bakery_production_plan_commits_ensure($db);
$assert(bakery_production_plan_commits_ready($db), 'production_plan_commits available');

$insertCustomer = $db->prepare(
    "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, ?, 'human')"
);
$insertCustomer->execute(['Char Prod Cafe', '20 Bake St', 1]);
$customerId = (int)$db->lastInsertId();
$customerIds[] = $customerId;

$demandQty = 7;
$db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', ?)"
)->execute([$customerId, $date, $demandQty * 1.00]);
$orderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, ?, 1.00, ?)'
)->execute([$orderId, $productId, $demandQty, $demandQty * 1.00]);

$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$customerId, $productId, $weekday, $demandQty]);

echo "\n=== Save draft then commit snapshot ===\n";
$planQty = $demandQty + 11;
$allowed = [$productId => true];
$saved = bakery_production_plan_save_targets($db, [$date => [$productId => $planQty]], $allowed, null);
$assert($saved === 1, 'save_targets writes one draft quantity');
$draft = bakery_production_plan_draft_quantities($db, $date);
$assert((int)($draft[$productId] ?? 0) === $planQty, 'draft matches saved target');

$bakeBefore = bakery_production_bake_list($db, $date);
$assert(empty($bakeBefore['committed']), 'save alone does not commit');

bakery_production_plan_commit($db, $date, null);
$bakeAfter = bakery_production_bake_list($db, $date);
$assert(!empty($bakeAfter['committed']), 'commit marks the delivery date committed');
$committedItem = null;
foreach ($bakeAfter['items'] as $item) {
    if ((int)$item['product_id'] === $productId) {
        $committedItem = $item;
        break;
    }
}
$assert($committedItem !== null, 'committed bake list includes the product');
$assert((int)$committedItem['bake_quantity'] === $planQty, 'baker quantity equals committed snapshot');

echo "\n=== Post-commit demand change raises drift ===\n";
sleep(1);
$newDemand = $demandQty + 5;
$db->prepare('UPDATE daily_order_items SET quantity=?, line_total=quantity*unit_price WHERE daily_order_id=? AND product_id=?')
    ->execute([$newDemand, $orderId, $productId]);
if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED')) {
    bakery_record_operational_event(
        $db,
        BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
        'Char: dated demand changed after plan commit',
        ['operational_date' => $date, 'daily_order_id' => $orderId, 'product_id' => $productId]
    );
}

$demandAfter = bakery_operating_demand_by_product($db, $date);
$demandQtyAfter = (int)($demandAfter['by_product'][$productId] ?? 0);
$assert($demandQtyAfter !== $demandQty, 'dated demand moved after commit');

$bakeDrift = bakery_production_bake_list($db, $date);
$driftItem = null;
foreach ($bakeDrift['items'] as $item) {
    if ((int)$item['product_id'] === $productId) {
        $driftItem = $item;
        break;
    }
}
$assert($driftItem !== null, 'bake list still lists the product after demand change');
$assert((int)$driftItem['bake_quantity'] === $planQty,
    'bake sheet stays on committed snapshot after demand change (§4)');
$assert((int)$driftItem['demand_quantity'] === $demandQtyAfter,
    'new operating demand is visible beside committed bake qty');
$assert((int)($bakeDrift['changed_since']['count'] ?? 0) > 0, 'changed_since count is non-zero after demand event');

$runDrift = bakery_daily_run_build($db, $date);
$hasDrift = false;
foreach ($runDrift['blockers'] as $blocker) {
    if (($blocker['type'] ?? '') === 'production_plan_drift') {
        $hasDrift = true;
        break;
    }
}
$assert($hasDrift, 'post-commit demand raises production_plan_drift (§4 drift is loud)');

echo "\n=== Assign standing vs dated ===\n";
$insertCustomer->execute(['Char Assign Standing Cafe', '21 Bake St', 1]);
$standingCust = (int)$db->lastInsertId();
$customerIds[] = $standingCust;
$insertCustomer->execute(['Char Assign Dated Cafe', '22 Bake St', 1]);
$datedCust = (int)$db->lastInsertId();
$customerIds[] = $datedCust;

$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$standingCust, $productId, $weekday, 20]);
$db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
)->execute([$datedCust, $productId, $weekday, 20]);

foreach ([$standingCust, $datedCust] as $cid) {
    $db->prepare(
        "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 20)"
    )->execute([$cid, $date]);
    $oid = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, 20, 1.00, 20.00)'
    )->execute([$oid, $productId]);
}

$standingApply = bakery_production_assign_apply($db, $date, $productId, [
    ['customer_id' => $standingCust, 'quantity' => 9],
], 'standing', null);
$assert((int)$standingApply['standing'] === 1, 'standing scope writes the template');
$assert(bakery_customer_standing_qty($db, $standingCust, $productId, $weekday) === 9,
    'standing assign updates standing template (§4 standing = template)');

$datedApply = bakery_production_assign_apply($db, $date, $productId, [
    ['customer_id' => $datedCust, 'quantity' => 4],
], 'daily', null);
$assert((int)$datedApply['standing'] === 0, 'daily scope does not write standing');
$assert(bakery_customer_standing_qty($db, $datedCust, $productId, $weekday) === 20,
    'dated assign leaves standing template alone (§4 dated = commercial commitment)');

$datedQty = 0;
foreach (bakery_operating_demand_customers_for_product($db, $date, $productId) as $line) {
    if ((int)$line['id'] === $datedCust) {
        $datedQty = (int)$line['quantity'];
        break;
    }
}
$assert($datedQty === 4, 'dated assign updates only the dated order quantity');

$cleanup();

echo "\n=== production_center characterization: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
