<?php
/**
 * Daily Run closeout: a day produced, loaded, and delivered exactly to demand
 * must close. A day that is still short of demand must keep the finished-goods
 * blocker and refuse closeout.
 *
 * CLI / local bakerysf_test only. Inserts far-future rows and deletes them.
 * Does not send email, SMS, or call Square.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/demand_confirmation.php';
require_once $root . '/includes/production_plan.php';
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/cron_run.php';
require_once $root . '/complete_delivery.php';

function cex_nth_sunday(int $n): string
{
    $d = new DateTimeImmutable('2098-01-01');
    $delta = (7 - (int)$d->format('N') + 7) % 7;
    $d = $d->modify('+' . $delta . ' days');
    if ($n > 1) {
        $d = $d->modify('+' . (7 * ($n - 1)) . ' days');
    }
    return $d->format('Y-m-d');
}

function cex_metric(array $stage, string $label)
{
    foreach ($stage['metrics'] ?? [] as $metric) {
        if (($metric['label'] ?? '') === $label) {
            return $metric['value'];
        }
    }
    return null;
}

function cex_stage(array $run, string $key): ?array
{
    foreach ($run['stages'] as $stage) {
        if (($stage['key'] ?? '') === $key) {
            return $stage;
        }
    }
    return null;
}

function cex_count_type(array $rows, string $type): int
{
    $count = 0;
    foreach ($rows as $row) {
        if ((string)($row['type'] ?? '') === $type) {
            $count++;
        }
    }
    return $count;
}

function cex_dump_run(array $run): void
{
    echo "NOTE  date={$run['date']} operational_complete=" . (!empty($run['operational_complete']) ? 'yes' : 'no')
        . ' closed=' . (!empty($run['is_closed']) ? 'yes' : 'no') . "\n";
    foreach ($run['stages'] as $stage) {
        echo 'NOTE  stage ' . ($stage['key'] ?? '') . ' ' . ($stage['ui_state'] ?? '')
            . ' — ' . ($stage['summary'] ?? '') . "\n";
    }
    foreach ($run['blockers'] as $blocker) {
        echo 'NOTE  blocker ' . ($blocker['severity'] ?? '') . ' ' . ($blocker['type'] ?? '')
            . ' ' . ($blocker['title'] ?? '') . "\n";
    }
}

function cex_wipe_date(PDO $db, string $date): void
{
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    if ($ids !== [] && table_exists($db, 'inventory_movements') && column_exists($db, 'inventory_movements', 'daily_order_id')) {
        $in = implode(',', $ids);
        $db->exec("DELETE FROM inventory_movements WHERE daily_order_id IN ({$in})");
    }
    if (table_exists($db, 'inventory_movements')) {
        $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'driver_loads')) {
        $loads = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
        $loads->execute([$date]);
        foreach ($loads->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
            $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
        }
        $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'product_inventory_days')) {
        $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_commit_items')) {
        $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_commits')) {
        $db->prepare('DELETE FROM production_plan_commits WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_items')) {
        $db->prepare('DELETE FROM production_plan_items WHERE delivery_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'demand_confirmations')) {
        $db->prepare('DELETE FROM demand_confirmations WHERE operating_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'operating_day_closeouts')) {
        $db->prepare('DELETE FROM operating_day_closeouts WHERE operating_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'operational_events')) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date = ?')->execute([$date]);
    }
    if (table_exists($db, 'pack_progress')) {
        $db->prepare('DELETE FROM pack_progress WHERE pack_date = ?')->execute([$date]);
    }
    if ($ids !== []) {
        $in = implode(',', $ids);
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
        $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
    }
}

/**
 * Custody still on the books, plus units already removed by delivery movements.
 *
 * @return array{available:int,loaded:int,delivered_out:int,demand:int}
 */
function cex_product_coverage(PDO $db, string $date, int $productId): array
{
    $demand = bakery_daily_run_required_products($db, $date, bakery_standing_day_from_date($date));
    $inv = $db->prepare(
        'SELECT COALESCE(available_quantity, 0), COALESCE(loaded_quantity, 0)
         FROM product_inventory_days
         WHERE delivery_date = ? AND product_id = ?'
    );
    $inv->execute([$date, $productId]);
    $row = $inv->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $moved = $db->prepare(
        "SELECT COALESCE(SUM(-quantity_delta), 0)
         FROM inventory_movements
         WHERE delivery_date = ? AND product_id = ? AND movement_type = 'delivery'"
    );
    $moved->execute([$date, $productId]);
    return [
        'available' => (int)$row[0],
        'loaded' => (int)$row[1],
        'delivered_out' => max(0, (int)$moved->fetchColumn()),
        'demand' => (int)($demand['by_product'][$productId] ?? 0),
    ];
}

/**
 * Materialize standing demand, confirm it, commit a matching bake, stock and
 * load every driver, and confirm deliveries.
 *
 * $shortUnits keeps that many units of the largest product out of stock, off
 * the van, and undelivered. Route closeout then leaves available+loaded at 0
 * while demand is still short by those units.
 *
 * @return array{run:array,order_ids:list<int>,short_product_id:int,short_units:int}
 */
function cex_prepare_operating_day(PDO $db, string $date, int $stockMultiplier, int $shortUnits, bool $reconcile): array
{
    cex_wipe_date($db, $date);
    $ensured = bakery_ensure_daily_orders_for_date($db, $date, [
        'record_event' => false,
        'assign_routes' => true,
    ]);
    if (empty($ensured['ran']) && ($ensured['skipped_reason'] ?? '') !== 'already_generated') {
        throw new RuntimeException('Could not materialize dated orders: ' . (string)($ensured['skipped_reason'] ?? 'unknown'));
    }
    bakery_demand_confirmation_confirm($db, $date, null);

    $required = bakery_daily_run_required_products($db, $date, bakery_standing_day_from_date($date));
    $byProduct = $required['by_product'];
    if ($byProduct === []) {
        throw new RuntimeException('No required products for ' . $date);
    }

    $shortProductId = 0;
    if ($shortUnits > 0) {
        $largest = 0;
        foreach ($byProduct as $productId => $qty) {
            if ((int)$qty > $largest) {
                $largest = (int)$qty;
                $shortProductId = (int)$productId;
            }
        }
        if ($largest <= $shortUnits) {
            throw new RuntimeException('Largest product demand is too small to withhold ' . $shortUnits . ' units');
        }
    }

    $planInsert = $db->prepare(
        'INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity) VALUES (?, ?, ?)'
    );
    foreach ($byProduct as $productId => $qty) {
        $planInsert->execute([$date, (int)$productId, (int)$qty]);
    }
    bakery_production_plan_commit($db, $date, null);

    foreach ($byProduct as $productId => $qty) {
        $onHand = (int)$qty * $stockMultiplier;
        if ((int)$productId === $shortProductId) {
            $onHand = (int)$qty - $shortUnits;
        }
        bakery_inventory_set_finished_on_hand(
            $db,
            $date,
            (int)$productId,
            $onHand,
            'daily run exact-close regression stock'
        );
    }

    $loadStmt = $db->prepare(
        'SELECT doa.driver_id, doi.product_id, SUM(doi.quantity) AS qty
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN daily_order_items doi ON doi.daily_order_id = do.id
         WHERE doa.delivery_date = ? AND do.order_date = ? AND doi.quantity > 0
         GROUP BY doa.driver_id, doi.product_id'
    );
    $loadStmt->execute([$date, $date]);
    $byDriver = [];
    $remainingShort = $shortUnits;
    foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)$row['product_id'];
        $qty = (int)$row['qty'];
        if ($productId === $shortProductId && $remainingShort > 0) {
            $cut = min($qty, $remainingShort);
            $qty -= $cut;
            $remainingShort -= $cut;
        }
        if ($qty > 0) {
            $byDriver[(int)$row['driver_id']][$productId] = $qty;
        }
    }
    if ($remainingShort > 0) {
        throw new RuntimeException('Could not withhold the short units from the pickup load');
    }
    if ($byDriver === []) {
        throw new RuntimeException('Standing generation did not assign a driver for ' . $date);
    }
    foreach ($byDriver as $driverId => $quantities) {
        bakery_inventory_save_driver_load($db, $date, $driverId, $quantities, 'daily run exact-close regression load');
    }

    if ($shortProductId > 0) {
        $lineStmt = $db->prepare(
            'SELECT doi.id, doi.product_id, doi.quantity
             FROM daily_order_items doi
             JOIN daily_orders do ON do.id = doi.daily_order_id
             WHERE do.order_date = ? AND doi.quantity > 0
             ORDER BY doi.id'
        );
        $lineStmt->execute([$date]);
        $setDelivered = $db->prepare('UPDATE daily_order_items SET delivered_quantity = ? WHERE id = ?');
        $remainingShort = $shortUnits;
        foreach ($lineStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $qty = (int)$line['quantity'];
            if ((int)$line['product_id'] === $shortProductId && $remainingShort > 0) {
                $cut = min($qty, $remainingShort);
                $qty -= $cut;
                $remainingShort -= $cut;
            }
            $setDelivered->execute([$qty, (int)$line['id']]);
        }
    }

    $orders = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ? ORDER BY id');
    $orders->execute([$date]);
    $orderIds = array_map('intval', $orders->fetchAll(PDO::FETCH_COLUMN));
    foreach ($orderIds as $orderId) {
        $preview = bakery_delivery_invoice($db, $orderId);
        $pieces = (int)$preview['ordered_pieces'];
        if ($shortProductId > 0) {
            $pieceStmt = $db->prepare(
                'SELECT COALESCE(SUM(delivered_quantity), 0) FROM daily_order_items WHERE daily_order_id = ?'
            );
            $pieceStmt->execute([$orderId]);
            $pieces = (int)$pieceStmt->fetchColumn();
        }
        $options = ['amount_collected' => (float)$preview['order_total']];
        try {
            bakery_confirm_delivery($db, $orderId, $pieces, 0, []);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'cash') === false && stripos($e->getMessage(), 'COD') === false) {
                throw $e;
            }
            bakery_confirm_delivery($db, $orderId, $pieces, 0, $options);
        }
        bakery_billing_mark_invoiced($db, $orderId, null);
    }

    if ($reconcile) {
        foreach (array_keys($byDriver) as $driverId) {
            bakery_inventory_reconcile_driver_load($db, $date, (int)$driverId, [], 'daily run exact-close regression closeout');
        }
    }

    return [
        'run' => bakery_daily_run_build($db, $date),
        'order_ids' => $orderIds,
        'short_product_id' => $shortProductId,
        'short_units' => $shortUnits,
    ];
}

function cex_close_refused(PDO $db, string $date): bool
{
    try {
        bakery_daily_run_close_day($db, $date, null, 'should refuse');
    } catch (Throwable $e) {
        return stripos($e->getMessage(), 'Cannot close') !== false;
    }
    return false;
}

$dates = [
    'exact' => cex_nth_sunday(1),
    'short' => cex_nth_sunday(2),
    'loaded_short' => cex_nth_sunday(3),
];
$cronPath = bakery_cron_storage_dir() . '/demand_scheduler.json';
$cronBackup = is_readable($cronPath) ? (string)file_get_contents($cronPath) : null;
$cronEventBefore = 0;

try {
    foreach ($dates as $date) {
        cex_wipe_date($db, $date);
    }
    if (table_exists($db, 'operational_events')) {
        $cronEventBefore = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM operational_events')->fetchColumn();
    }
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', ['source' => 'daily-run-exact-close']);

    echo "Exact-stock date: {$dates['exact']}\n";
    $exact = cex_prepare_operating_day($db, $dates['exact'], 1, 0, true);
    $exactRun = $exact['run'];
    $exactCovered = true;
    $exactOnHandShort = false;
    $required = bakery_daily_run_required_products($db, $dates['exact'], bakery_standing_day_from_date($dates['exact']));
    foreach ($required['by_product'] as $productId => $qty) {
        if ((int)$qty <= 0) {
            continue;
        }
        $coverage = cex_product_coverage($db, $dates['exact'], (int)$productId);
        if ($coverage['demand'] > ($coverage['available'] + $coverage['loaded'])) {
            $exactOnHandShort = true;
        }
        if ($coverage['demand'] > ($coverage['available'] + $coverage['loaded'] + $coverage['delivered_out'])) {
            $exactCovered = false;
        }
    }
    assert_true($exactOnHandShort, 'exact fulfillment leaves available+loaded below demand after route closeout');
    assert_true($exactCovered, 'exact fulfillment is covered once delivered units are counted');
    $exactCc = bakery_dashboard_command_center($db, $dates['exact']);
    if (empty($exactRun['operational_complete']) || cex_count_type($exactRun['blockers'], 'production_fg_shortfall') > 0) {
        cex_dump_run($exactRun);
    }
    assert_eq(0, cex_count_type($exactRun['blockers'], 'production_fg_shortfall'), 'exact fulfillment does not emit a Daily Run finished-goods shortfall');
    assert_eq(0, cex_count_type($exactCc['exceptions'] ?? [], 'production_fg_shortfall'), 'exact fulfillment does not emit a command-center finished-goods shortfall');
    $pack = cex_stage($exactRun, 'pack');
    assert_eq(0, (int)cex_metric($pack ?? [], 'Stock shortfall'), 'exact fulfillment pack stock shortfall is 0');
    assert_true(!empty($exactRun['operational_complete']), 'exact produce/load/deliver day is operationally complete');
    if (!empty($exactRun['operational_complete'])) {
        bakery_daily_run_close_day($db, $dates['exact'], null, 'exact close');
    }
    $exactClosed = bakery_daily_run_build($db, $dates['exact']);
    assert_true(!empty($exactClosed['is_closed']), 'exact fulfillment day closes');
    assert_true(!empty($exactClosed['operational_complete']), 'exact fulfillment day stays complete after closeout');
    assert_eq(0, cex_count_type($exactClosed['blockers'], 'production_fg_shortfall'), 'closing the exact day does not bring the shortfall back');

    echo "Genuine-shortfall date: {$dates['short']}\n";
    $shortUnits = 4;
    $short = cex_prepare_operating_day($db, $dates['short'], 1, $shortUnits, true);
    $shortCoverage = cex_product_coverage($db, $dates['short'], $short['short_product_id']);
    assert_true($shortCoverage['demand'] > $shortUnits, 'shortfall product has demand above the withheld units');
    assert_eq(0, $shortCoverage['available'], 'shortfall product has no warehouse stock after closeout');
    assert_eq(0, $shortCoverage['loaded'], 'shortfall product has no van stock after closeout');
    assert_eq(
        $shortCoverage['demand'] - $shortUnits,
        $shortCoverage['delivered_out'],
        'shortfall product delivered only the units that were produced'
    );
    assert_true(
        $shortCoverage['demand'] > ($shortCoverage['available'] + $shortCoverage['loaded'] + $shortCoverage['delivered_out']),
        'delivered units do not cover the missing production'
    );
    $shortRun = $short['run'];
    $shortCc = bakery_dashboard_command_center($db, $dates['short']);
    if (cex_count_type($shortRun['blockers'], 'production_fg_shortfall') === 0) {
        cex_dump_run($shortRun);
    }
    assert_true(
        cex_count_type($shortRun['blockers'], 'production_fg_shortfall') >= 1,
        'a real missing-unit day still emits a Daily Run finished-goods shortfall'
    );
    assert_true(
        cex_count_type($shortCc['exceptions'] ?? [], 'production_fg_shortfall') >= 1,
        'a real missing-unit day still emits a command-center finished-goods shortfall'
    );
    assert_true(empty($shortRun['operational_complete']), 'a real missing-unit day is not operationally complete');
    assert_true(cex_close_refused($db, $dates['short']), 'closeout refuses a day that is still short of demand');

    echo "Loaded-but-undelivered-out date: {$dates['loaded_short']}\n";
    $loadedShort = cex_prepare_operating_day($db, $dates['loaded_short'], 1, $shortUnits, false);
    $loadedCoverage = cex_product_coverage($db, $dates['loaded_short'], $loadedShort['short_product_id']);
    assert_eq(0, $loadedCoverage['delivered_out'], 'unreconciled route has not removed delivered units from the van');
    assert_true(
        $loadedCoverage['loaded'] > 0 && $loadedCoverage['loaded'] < $loadedCoverage['demand'],
        'the van still holds the produced units, which are fewer than demand'
    );
    $loadedRun = $loadedShort['run'];
    $loadedCc = bakery_dashboard_command_center($db, $dates['loaded_short']);
    if (cex_count_type($loadedRun['blockers'], 'production_fg_shortfall') === 0) {
        cex_dump_run($loadedRun);
    }
    assert_true(
        cex_count_type($loadedRun['blockers'], 'production_fg_shortfall') >= 1,
        'units still on the van are not counted twice against a short bake'
    );
    assert_true(
        cex_count_type($loadedCc['exceptions'] ?? [], 'production_fg_shortfall') >= 1,
        'command center does not treat van stock plus order lines as double coverage'
    );
    assert_true(cex_close_refused($db, $dates['loaded_short']), 'closeout refuses the short bake before route closeout');
} catch (Throwable $e) {
    echo 'FAIL  uncaught ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    $GLOBALS['TEST_FAIL']++;
} finally {
    foreach ($dates as $date) {
        try {
            cex_wipe_date($db, $date);
        } catch (Throwable $e) {
            fwrite(STDERR, 'cleanup date failed: ' . $e->getMessage() . "\n");
        }
    }
    if ($cronBackup === null) {
        @unlink($cronPath);
    } else {
        @file_put_contents($cronPath, $cronBackup);
    }
    if (table_exists($db, 'operational_events')) {
        $db->prepare(
            "DELETE FROM operational_events
             WHERE id > ? AND event_type = 'cron_run' AND summary LIKE 'Cron demand_scheduler %'"
        )->execute([$cronEventBefore]);
    }
}

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
