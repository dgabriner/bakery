<?php
/**
 * Smoke: lazy ensure + Confirm Demand hard-gate for "Tomorrow, Confirmed".
 * CLI / local only. Cleans up the synthetic future date it uses.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/demand_confirmation.php';
require_once $root . '/includes/daily_run.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: smoke must run with APP_ENV=local\n");
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

$date = date('Y-m-d', strtotime('+21 days'));
echo "Test date: $date\n";

$cleanup = static function (PDO $db, string $date): void {
    $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date=?)')
        ->execute([$date]);
    $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date=?')->execute([$date]);
    $db->prepare('DELETE FROM daily_orders WHERE order_date=?')->execute([$date]);
    if (table_exists($db, 'demand_confirmations')) {
        $db->prepare('DELETE FROM demand_confirmations WHERE operating_date=?')->execute([$date]);
    }
    if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date=?')->execute([$date]);
    }
};

$cleanup($db, $date);

$e1 = bakery_ensure_daily_orders_for_date($db, $date, ['record_event' => false]);
$assert($e1['ran'] === true || $e1['skipped_reason'] === 'already_generated' || $e1['skipped_reason'] === 'unavailable',
    'ensure first call ran or no standing demand for weekday');

if ($e1['ran']) {
    $assert((int)($e1['result']['orders_created'] ?? 0) >= 0, 'ensure returned generation result');
    $e2 = bakery_ensure_daily_orders_for_date($db, $date, ['record_event' => false]);
    $assert($e2['ran'] === false && $e2['skipped_reason'] === 'already_generated',
        'second ensure is a no-op (already_generated)');
}

$run = bakery_daily_run_build($db, $date);
$stage = null;
foreach ($run['stages'] as $s) {
    if (($s['key'] ?? '') === 'confirm_demand') {
        $stage = $s;
        break;
    }
}
$assert(is_array($stage), 'confirm_demand stage present');

$confirmable = !empty($stage['confirmation']['confirmable']);
$tableReady = !empty($stage['confirmation']['available']);

if ($confirmable && $tableReady) {
    $assert(($stage['ui_state'] ?? '') === 'needs_attention',
        'unconfirmed confirmable demand is needs_attention (hard-gate)');
    $hasUnconfirmed = false;
    foreach ($run['blockers'] as $b) {
        if (($b['type'] ?? '') === 'demand_unconfirmed') {
            $hasUnconfirmed = true;
            break;
        }
    }
    $assert($hasUnconfirmed, 'critical demand_unconfirmed blocker present');
    $assert(empty($run['operational_complete']), 'day not operationally complete while unconfirmed');

    bakery_demand_confirmation_ensure($db);
    bakery_demand_confirmation_confirm($db, $date, null);
    $run2 = bakery_daily_run_build($db, $date);
    $stage2 = null;
    foreach ($run2['stages'] as $s) {
        if (($s['key'] ?? '') === 'confirm_demand') {
            $stage2 = $s;
            break;
        }
    }
    $assert(($stage2['ui_state'] ?? '') === 'complete', 'stage 1 complete after confirm');
} else {
    echo "NOTE  skipped hard-gate asserts (confirmable=" . var_export($confirmable, true)
        . ' available=' . var_export($tableReady, true) . ")\n";
}

// Edit-preservation: change a quantity, re-ensure must not overwrite.
if ($e1['ran'] || ($e1['skipped_reason'] ?? '') === 'already_generated') {
    $item = $db->prepare('
        SELECT doi.id, doi.quantity, doi.product_id, do.id AS order_id
        FROM daily_order_items doi
        JOIN daily_orders do ON do.id = doi.daily_order_id
        WHERE do.order_date = ?
        LIMIT 1
    ');
    $item->execute([$date]);
    $row = $item->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newQty = (int)$row['quantity'] + 17;
        $db->prepare('UPDATE daily_order_items SET quantity = ?, line_total = quantity * unit_price WHERE id = ?')
            ->execute([$newQty, (int)$row['id']]);
        // Force a "needs generation" gap: delete another customer's order if present,
        // otherwise just re-run generate path via ensure after deleting one order item's customer peer.
        // Simpler: call generate with overwrite_changed false directly and check preserve.
        $gen = bakery_generate_daily_orders_from_standing($db, $date, [
            'overwrite_changed' => false,
            'record_event' => false,
            'assign_routes' => false,
        ]);
        $check = $db->prepare('SELECT quantity FROM daily_order_items WHERE id = ?');
        $check->execute([(int)$row['id']]);
        $kept = (int)$check->fetchColumn();
        $assert($kept === $newQty, 'dated quantity edit preserved across generate (got ' . $kept . ')');
        $assert((int)($gen['items_preserved'] ?? 0) >= 1 || $kept === $newQty,
            'generator reported preserve or quantity still edited');
    } else {
        echo "NOTE  no daily_order_items on synthetic date — skip edit-preservation assert\n";
    }
}

$cleanup($db, $date);

echo "=== Missing standing line heal ===\n";
$healDate = date('Y-m-d', strtotime('+33 days'));
$healWeekday = bakery_standing_day_from_date($healDate);
$products = $db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 2"
)->fetchAll(PDO::FETCH_COLUMN);
$productA = (int)($products[0] ?? 0);
$productB = (int)($products[1] ?? 0);
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$healCustomerIds = [];
$healCleanup = static function () use ($db, $healDate, &$healCustomerIds): void {
    if ($healCustomerIds === []) {
        return;
    }
    $ph = implode(',', array_fill(0, count($healCustomerIds), '?'));
    $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date = ?");
    $orderIds->execute(array_merge($healCustomerIds, [$healDate]));
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids) {
        $oph = implode(',', array_fill(0, count($ids), '?'));
        $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
        $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
        $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
    }
    $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($healCustomerIds);
    if (table_exists($db, 'standing_routes')) {
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($healCustomerIds);
    }
    if (table_exists($db, 'standing_order_pauses')) {
        $db->prepare("DELETE FROM standing_order_pauses WHERE customer_id IN ($ph)")->execute($healCustomerIds);
    }
    if (table_exists($db, 'demand_confirmations')) {
        $db->prepare('DELETE FROM demand_confirmations WHERE operating_date = ?')->execute([$healDate]);
    }
    $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($healCustomerIds);
    $healCustomerIds = [];
};
$healCleanup();

if ($productA <= 0 || $productB <= 0 || $productA === $productB) {
    echo "NOTE  need two catalog products with dough/line — skip missing-line heal asserts\n";
} else {
    try {
        $insertCustomer = $db->prepare(
            "INSERT INTO customers (name, address, is_active, sfb_origin) VALUES (?, ?, ?, 'human')"
        );
        $insertCustomer->execute(['Demand Line Heal Cafe', '1 Heal Lane', 1]);
        $activeId = (int)$db->lastInsertId();
        $insertCustomer->execute(['Demand Line Heal Inactive', '2 Heal Lane', 0]);
        $inactiveId = (int)$db->lastInsertId();
        $insertCustomer->execute(['Demand Line Heal Paused', '3 Heal Lane', 1]);
        $pausedId = (int)$db->lastInsertId();
        $insertCustomer->execute(['Demand Line Heal Neighbor', '4 Heal Lane', 1]);
        $neighborId = (int)$db->lastInsertId();
        $healCustomerIds = [$activeId, $inactiveId, $pausedId, $neighborId];
        $assert($activeId > 0 && $inactiveId > 0 && $pausedId > 0 && $neighborId > 0, 'heal fixture customers inserted');

        $standing = $db->prepare(
            'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
             VALUES (?, ?, ?, ?)'
        );
        $standing->execute([$activeId, $productA, $healWeekday, 8]);
        $standing->execute([$inactiveId, $productA, $healWeekday, 4]);
        $standing->execute([$pausedId, $productA, $healWeekday, 6]);
        $standing->execute([$neighborId, $productA, $healWeekday, 5]);
        if (table_exists($db, 'standing_routes') && $driverId > 0) {
            $db->prepare(
                'INSERT INTO standing_routes (customer_id, driver_id, day_of_week, route_order)
                 VALUES (?, ?, ?, 1)'
            )->execute([$activeId, $driverId, $healWeekday]);
        }
        if (table_exists($db, 'standing_order_pauses')) {
            $db->prepare(
                'INSERT INTO standing_order_pauses (customer_id, week_start) VALUES (?, ?)'
            )->execute([$pausedId, bakery_week_start_monday($healDate)]);
        }

        $first = bakery_generate_daily_orders_from_standing($db, $healDate, [
            'overwrite_changed' => false,
            'record_event' => false,
        ]);
        $assert((int)($first['orders_created'] ?? 0) >= 1, 'first generate created the active dated order');

        $orderStmt = $db->prepare(
            'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?'
        );
        $orderStmt->execute([$activeId, $healDate]);
        $orderId = (int)$orderStmt->fetchColumn();
        $assert($orderId > 0, 'active customer has a dated order after first generate');

        $qtyStmt = $db->prepare(
            'SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?'
        );
        $qtyStmt->execute([$orderId, $productA]);
        $assert((int)$qtyStmt->fetchColumn() === 8, 'dated order contains standing product A = 8');
        $qtyStmt->execute([$orderId, $productB]);
        $assert($qtyStmt->fetchColumn() === false, 'dated order does not yet contain product B');

        $inactiveHas = $db->prepare(
            'SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?'
        );
        $inactiveHas->execute([$inactiveId, $healDate]);
        $assert((int)$inactiveHas->fetchColumn() === 0, 'inactive customer did not generate');

        $pausedHas = $db->prepare(
            'SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?'
        );
        $pausedHas->execute([$pausedId, $healDate]);
        $assert((int)$pausedHas->fetchColumn() === 0, 'paused customer did not generate');

        $assignmentId = 0;
        $assignmentOrder = 0;
        $assignmentDriver = 0;
        if ($driverId > 0) {
            $asg = $db->prepare(
                'SELECT id, driver_id, route_order FROM daily_order_assignments
                 WHERE daily_order_id = ? AND delivery_date = ?'
            );
            $asg->execute([$orderId, $healDate]);
            $asgRow = $asg->fetch(PDO::FETCH_ASSOC);
            if ($asgRow) {
                $assignmentId = (int)$asgRow['id'];
                $assignmentDriver = (int)$asgRow['driver_id'];
                $assignmentOrder = (int)$asgRow['route_order'];
            }
        }

        $editedQty = 13;
        $db->prepare(
            'UPDATE daily_order_items SET quantity = ?, line_total = quantity * unit_price
             WHERE daily_order_id = ? AND product_id = ?'
        )->execute([$editedQty, $orderId, $productA]);

        $standing->execute([$activeId, $productB, $healWeekday, 3]);

        $reviewBefore = bakery_demand_review_build($db, $healDate, []);
        $assert(
            (int)($reviewBefore['summary']['missing_standing_lines'] ?? 0) >= 1,
            'review counts the missing standing product B line'
        );
        $assert(
            bakery_demand_is_confirmable($reviewBefore['summary']) === false,
            'Confirm Demand refuses while standing product B is missing from the dated order'
        );

        $noopEnsure = bakery_ensure_daily_orders_for_date($db, $healDate, [
            'record_event' => false,
            'assign_routes' => $driverId > 0,
        ]);
        $assert($noopEnsure['ran'] === true, 'ordinary lazy fill runs to heal the missing standing line (got skipped=' . ($noopEnsure['skipped_reason'] ?? 'null') . ')');
        $assert((int)($noopEnsure['result']['items_created'] ?? 0) >= 1, 'lazy fill created the missing standing line');

        $qtyStmt->execute([$orderId, $productA]);
        $assert((int)$qtyStmt->fetchColumn() === $editedQty, 'manually edited product A quantity is preserved');
        $qtyStmt->execute([$orderId, $productB]);
        $assert((int)$qtyStmt->fetchColumn() === 3, 'newly added standing product B appears at standing quantity 3');

        $lineCount = $db->prepare(
            'SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?'
        );
        $lineCount->execute([$orderId, $productB]);
        $assert((int)$lineCount->fetchColumn() === 1, 'no duplicate dated line for product B');

        $sameOrder = $db->prepare(
            'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?'
        );
        $sameOrder->execute([$activeId, $healDate]);
        $assert((int)$sameOrder->fetchColumn() === $orderId, 'dated order identity is unchanged');

        if ($assignmentId > 0) {
            $asg = $db->prepare(
                'SELECT id, driver_id, route_order FROM daily_order_assignments
                 WHERE daily_order_id = ? AND delivery_date = ?'
            );
            $asg->execute([$orderId, $healDate]);
            $asgRow = $asg->fetch(PDO::FETCH_ASSOC);
            $assert((int)($asgRow['id'] ?? 0) === $assignmentId, 'assignment row identity is unchanged');
            $assert((int)($asgRow['driver_id'] ?? 0) === $assignmentDriver, 'assignment driver is unchanged');
            $assert((int)($asgRow['route_order'] ?? 0) === $assignmentOrder, 'route_order is unchanged');
        }

        $neighborDemand = bakery_operating_demand_customers_for_product($db, $healDate, $productA);
        $neighborRow = null;
        foreach ($neighborDemand as $row) {
            if ((int)$row['id'] === $neighborId) {
                $neighborRow = $row;
                break;
            }
        }
        $assert(is_array($neighborRow), 'neighbor customer still appears in operating demand');
        $assert(
            (string)($neighborRow['source'] ?? '') === 'daily' || (string)($neighborRow['source'] ?? '') === 'standing',
            'neighbor demand source remains a per-customer standing/dated choice'
        );

        $again = bakery_ensure_daily_orders_for_date($db, $healDate, [
            'record_event' => false,
            'assign_routes' => $driverId > 0,
        ]);
        $assert($again['ran'] === false && ($again['skipped_reason'] ?? '') === 'already_generated', 'second lazy fill is idempotent');
        $third = bakery_ensure_daily_orders_for_date($db, $healDate, [
            'record_event' => false,
            'assign_routes' => $driverId > 0,
        ]);
        $assert($third['ran'] === false && ($third['skipped_reason'] ?? '') === 'already_generated', 'third lazy fill is still a no-op');
        $lineCount->execute([$orderId, $productB]);
        $assert((int)$lineCount->fetchColumn() === 1, 're-run does not duplicate product B');
        $qtyStmt->execute([$orderId, $productA]);
        $assert((int)$qtyStmt->fetchColumn() === $editedQty, 're-run still preserves edited product A');

        $inactiveHas->execute([$inactiveId, $healDate]);
        $assert((int)$inactiveHas->fetchColumn() === 0, 'inactive customer still has no dated order after heal');
        $pausedHas->execute([$pausedId, $healDate]);
        $assert((int)$pausedHas->fetchColumn() === 0, 'paused customer still has no dated order after heal');

        if (bakery_demand_confirmation_ready($db)) {
            $confirmed = bakery_demand_confirmation_confirm($db, $healDate, null);
            $assert((int)($confirmed['units_count'] ?? 0) >= ($editedQty + 3), 'Confirm Demand units include healed standing line B');
        }
    } catch (Throwable $e) {
        echo 'FAIL  heal fixture: ' . $e->getMessage() . "\n";
        $fail++;
    } finally {
        $healCleanup();
    }
}

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
