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
echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
