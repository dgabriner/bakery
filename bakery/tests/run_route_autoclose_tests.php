<?php
/**
 * Past routes with every stop terminal close themselves.
 * An implicit load does not reserve finished goods.
 * CLI / bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require $root . '/tests/harness.php';
bakery_assert_local_test_target($db);
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'bakerysf_test') {
    fwrite(STDERR, "Refusing: database is not bakerysf_test\n");
    exit(1);
}

require_once $root . '/includes/route_autoclose.php';
require_once $root . '/includes/pan_carry.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/production_plan.php';
require_once $root . '/includes/demand_confirmation.php';
require_once $root . '/includes/cron_run.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/i18n.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$fail = 0;
$pass = 0;
$assert = static function (bool $ok, string $label) use (&$fail, &$pass): void {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
};

$pt = new DateTimeZone('America/Los_Angeles');
$customerId = (int)$db->query('SELECT id FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetchColumn();
$driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$userId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$panId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     WHERE LOWER(pl.name) = 'pan dulce'
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();

$assert($customerId > 0 && $driverId > 0 && $productId > 0, 'fixtures have a customer, a driver, and a product');
$assert(bakery_route_autoclose_has_source_columns($db), 'migration 116 added load_source and closeout_source');

$dates = [
    'auto' => '2096-04-04',
    'open' => '2096-04-05',
    'manager' => '2096-04-06',
    'finish' => '2096-04-07',
    'sealed' => '2096-04-08',
    'get' => '2096-04-09',
    'post' => '2096-04-11',
    'today' => '2096-04-12',
    'balance' => '2096-04-02',
    'run' => '2096-01-03',
];
$now = new DateTimeImmutable('2096-04-12 11:00:00', $pt);

$wipe = static function (PDO $db, string $date): void {
    $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
    $orderIds->execute([$date]);
    $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
    $byAssign = $db->prepare('SELECT daily_order_id FROM daily_order_assignments WHERE delivery_date = ?');
    $byAssign->execute([$date]);
    foreach ($byAssign->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int)$id;
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids !== []) {
        $in = implode(',', $ids);
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($in)");
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ($in)");
        $db->exec("DELETE FROM daily_orders WHERE id IN ($in)");
    }
    if (table_exists($db, 'driver_loads')) {
        $loads = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
        $loads->execute([$date]);
        foreach ($loads->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
            $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
        }
        $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$date]);
    }
    foreach ([
        'inventory_movements' => 'delivery_date',
        'product_inventory_days' => 'delivery_date',
        'production_plan_items' => 'delivery_date',
        'production_plan_commits' => 'delivery_date',
        'production_plan_commit_items' => 'delivery_date',
        'demand_confirmations' => 'operating_date',
        'operating_day_closeouts' => 'operating_date',
        'route_carry_forwards' => 'source_date',
    ] as $table => $column) {
        if (table_exists($db, $table) && function_exists('column_exists') && column_exists($db, $table, $column)) {
            $db->prepare("DELETE FROM {$table} WHERE {$column} = ?")->execute([$date]);
        }
    }
};

$stock = static function (PDO $db, string $date): array {
    $rows = $db->prepare(
        'SELECT product_id, available_quantity, loaded_quantity, produced_quantity
         FROM product_inventory_days WHERE delivery_date = ? ORDER BY product_id'
    );
    $rows->execute([$date]);
    $moves = $db->prepare('SELECT COUNT(*) FROM inventory_movements WHERE delivery_date = ?');
    $moves->execute([$date]);
    return [
        'rows' => $rows->fetchAll(PDO::FETCH_ASSOC),
        'movements' => (int)$moves->fetchColumn(),
    ];
};

$load = static function (PDO $db, string $date, int $driverId): ?array {
    $stmt = $db->prepare(
        'SELECT id, status, load_source, closeout_source, notes FROM driver_loads
         WHERE driver_id = ? AND delivery_date = ? LIMIT 1'
    );
    $stmt->execute([$driverId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$place = static function (
    PDO $db,
    string $date,
    int $customerId,
    int $driverId,
    int $productId,
    int $qty,
    string $deliveryStatus,
    string $orderStatus
) use ($wipe): void {
    $wipe($db, $date);
    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, 0)'
    )->execute([$customerId, $date, $orderStatus]);
    $orderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, delivered_quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, 1, ?)'
    )->execute([$orderId, $productId, $qty, $deliveryStatus === 'delivered' ? $qty : 0, $qty]);
    $db->prepare(
        'INSERT INTO daily_order_assignments
         (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
         VALUES (?, ?, ?, 1, \'08:00:00\', ?)'
    )->execute([$orderId, $driverId, $date, $deliveryStatus]);
};

$cronPath = bakery_cron_storage_dir() . '/demand_scheduler.json';
$cronBackup = is_file($cronPath) ? (string)file_get_contents($cronPath) : null;
$maxEventId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM operational_events')->fetchColumn();

try {
    foreach ($dates as $date) {
        $wipe($db, $date);
    }

    $place($db, $dates['auto'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    $before = $stock($db, $dates['auto']);
    $first = bakery_route_autoclose_past($db, $now, $dates['auto']);
    $row = $load($db, $dates['auto'], $driverId);
    $items = $db->prepare('SELECT loaded_quantity, returned_quantity, wasted_quantity FROM driver_load_items WHERE driver_load_id = ?');
    $items->execute([(int)($row['id'] ?? 0)]);
    $item = $items->fetch(PDO::FETCH_ASSOC) ?: [];
    $after = $stock($db, $dates['auto']);
    $assert((int)$first['closed'] === 1 && (int)$first['implicit'] === 1, 'a terminal route with no load is closed by an implicit load');
    $assert($row !== null && $row['status'] === 'reconciled', 'the implicit load is reconciled');
    $assert($row !== null && $row['load_source'] === 'implicit' && $row['closeout_source'] === 'auto', 'sources are implicit and auto');
    $assert((int)($item['loaded_quantity'] ?? 0) === 4 && (int)($item['returned_quantity'] ?? -1) === 0 && (int)($item['wasted_quantity'] ?? -1) === 0, 'loaded equals delivered and returns are 0');
    $assert($before === $after, 'implicit closeout does not change stock rows or movements');
    $second = bakery_route_autoclose_past($db, $now, $dates['auto']);
    $assert((int)$second['closed'] === 0, 'a second pass does not close the route again');

    $place($db, $dates['today'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    $todayNow = new DateTimeImmutable($dates['today'] . ' 18:00:00', $pt);
    $todayResult = bakery_route_autoclose_past($db, $todayNow, $dates['today']);
    $assert((int)$todayResult['closed'] === 0 && $load($db, $dates['today'], $driverId) === null, 'today is not auto-closed');

    $place($db, $dates['open'], $customerId, $driverId, $panId > 0 ? $panId : $productId, 2, 'pending', 'pending');
    $openResult = bakery_route_autoclose_past($db, $now, $dates['open']);
    $assert((int)$openResult['left_open'] === 1 && $load($db, $dates['open'], $driverId) === null, 'a route with one open stop stays open');
    $carryFirst = bakery_pan_carry_forward_day($db, $dates['open']);
    $carryRows = $db->prepare('SELECT COUNT(*) FROM route_carry_forwards WHERE source_date = ?');
    $carryRows->execute([$dates['open']]);
    $carryCount = (int)$carryRows->fetchColumn();
    $carrySecond = bakery_pan_carry_forward_day($db, $dates['open']);
    $carryRows->execute([$dates['open']]);
    $carryAgain = (int)$carryRows->fetchColumn();
    $assert($carryAgain === $carryCount, 'carry does not duplicate on rerun');
    if ($panId > 0) {
        $assert((int)$carryFirst['carried'] === 1, 'an open pan dulce stop carries once');
        $assert((int)$carrySecond['carried'] === 0, 'the second carry does not add another stop');
    }

    $place($db, $dates['manager'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    bakery_inventory_save_driver_load($db, $dates['manager'], $driverId, [$productId => 6], 'manager pickup');
    $managerBefore = $stock($db, $dates['manager']);
    $managerSaved = $load($db, $dates['manager'], $driverId);
    bakery_route_autoclose_past($db, $now, $dates['manager']);
    $managerAfterLoad = $load($db, $dates['manager'], $driverId);
    $managerItems = $db->prepare('SELECT loaded_quantity FROM driver_load_items WHERE driver_load_id = ? AND product_id = ?');
    $managerItems->execute([(int)$managerAfterLoad['id'], $productId]);
    $assert($managerSaved !== null && $managerSaved['load_source'] === 'manager', 'a person save is source manager');
    $assert((int)$managerItems->fetchColumn() === 6 && $managerAfterLoad['status'] === 'loaded', 'a real load that does not balance is not replaced or closed');
    $assert($managerBefore === $stock($db, $dates['manager']), 'autoclose does not change stock on a real load it leaves open');

    $place($db, $dates['balance'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    bakery_inventory_save_driver_load($db, $dates['balance'], $driverId, [$productId => 4], 'manager pickup matches delivery');
    bakery_route_autoclose_past($db, $now, $dates['balance']);
    $balanced = $load($db, $dates['balance'], $driverId);
    $balancedQty = $db->prepare('SELECT loaded_quantity FROM driver_load_items WHERE driver_load_id = ? AND product_id = ?');
    $balancedQty->execute([(int)($balanced['id'] ?? 0), $productId]);
    $assert(
        $balanced !== null
            && $balanced['status'] === 'reconciled'
            && $balanced['load_source'] === 'manager'
            && $balanced['closeout_source'] === 'auto'
            && (int)$balancedQty->fetchColumn() === 4,
        'a real load that already matches delivery is closed without replacing the quantities'
    );

    $place($db, $dates['finish'], $customerId, $driverId, $productId, 3, 'delivered', 'delivered');
    $finishBefore = $stock($db, $dates['finish']);
    $tap = bakery_route_finish_nothing_left($db, $dates['finish'], $driverId);
    $again = bakery_route_finish_nothing_left($db, $dates['finish'], $driverId);
    $finishLoad = $load($db, $dates['finish'], $driverId);
    $assert($tap['ok'] === true && $tap['idempotent'] === false, 'Nothing left saves the closeout');
    $assert($again['ok'] === true && $again['idempotent'] === true, 'a second tap does not write again');
    $assert($finishLoad !== null && $finishLoad['status'] === 'reconciled' && $finishLoad['closeout_source'] === 'driver', 'Nothing left records source driver');
    $assert($finishBefore === $stock($db, $dates['finish']), 'Nothing left with no saved load does not change stock');

    $place($db, $dates['sealed'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    $db->prepare(
        'INSERT INTO operating_day_closeouts (operating_date, closed_at, closed_by_user_id, manager_note, reopened_at, reopened_by_user_id)
         VALUES (?, NOW(), ?, ?, NULL, NULL)'
    )->execute([$dates['sealed'], $userId > 0 ? $userId : null, 'already closed']);
    $sealed = bakery_route_autoclose_past($db, $now, $dates['sealed']);
    $sealedRow = $db->prepare('SELECT closed_at, reopened_at FROM operating_day_closeouts WHERE operating_date = ?');
    $sealedRow->execute([$dates['sealed']]);
    $sealedState = $sealedRow->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert((int)$sealed['skipped_closed_day'] === 1 && $load($db, $dates['sealed'], $driverId) === null, 'a closed day is not given a new closeout');
    $assert(!empty($sealedState['closed_at']) && empty($sealedState['reopened_at']), 'autoclose does not reopen a closed day');

    $place($db, $dates['get'], $customerId, $driverId, $productId, 4, 'delivered', 'delivered');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $get = bakery_demand_self_heal_dispatch($db, $now);
    $assert((int)($get['status'] ?? 0) === 405, 'self-heal GET is rejected');
    $assert($load($db, $dates['get'], $driverId) === null, 'a GET does not write an implicit load');
    $closeoutSrc = (string)file_get_contents($root . '/route_closeout.php');
    $driverSrc = (string)file_get_contents($root . '/driver.php');
    $postAt = strpos($closeoutSrc, "REQUEST_METHOD'] === 'POST'");
    $finishAt = strpos($closeoutSrc, 'bakery_route_finish_nothing_left');
    $assert(strpos($closeoutSrc, 'bakery_route_autoclose_past') === false, 'the closeout page does not scan past routes');
    $assert($postAt !== false && $finishAt !== false && $finishAt > $postAt, 'Nothing left on the closeout page is inside POST');
    $driverPost = strpos($driverSrc, "\$_POST['action'] ?? '') === 'nothing_left'");
    $driverCall = strpos($driverSrc, 'bakery_route_finish_nothing_left');
    $assert($driverPost !== false && $driverCall !== false && $driverCall > $driverPost, 'the driver Finish button posts');
    $assert(strpos($driverSrc, 'bakery_route_finish_snapshot') !== false, 'the Finish screen shows loaded and delivered');
    $assert(strpos((string)file_get_contents($root . '/scripts/demand_scheduler.php'), 'bakery_route_autoclose_past') !== false, 'the nightly script closes past routes');
    $healSrc = (string)file_get_contents($root . '/includes/daily_order_generation.php');
    $healGet = strpos($healSrc, "if (\$method !== 'POST')");
    $healCall = strpos($healSrc, 'bakery_route_autoclose_past');
    $assert($healGet !== false && $healCall !== false && $healCall > $healGet, 'self-heal calls autoclose only after the GET rejection');

    $place($db, $dates['post'], $customerId, $driverId, $productId, 5, 'delivered', 'delivered');
    $_SESSION['user_id'] = $userId > 0 ? $userId : 1;
    $_SESSION['user_email'] = 'autoclose@example.test';
    $_SESSION['user_display_name'] = 'Autoclose';
    $_SESSION['user_role_slug'] = 'administrator';
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['csrf_token'] = 'autoclose-csrf';
    $postNow = new DateTimeImmutable('2096-04-12 10:15:00', $pt);
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', ['source' => 'route-autoclose-test'], $postNow);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [];
    $posted = bakery_demand_self_heal_dispatch($db, $postNow);
    $postLoad = $load($db, $dates['post'], $driverId);
    $assert((string)($posted['body']['reason'] ?? '') === 'already_built', 'a stamped sell day does not build demand again');
    $assert($postLoad !== null && $postLoad['closeout_source'] === 'auto', 'the self-heal POST still auto-closes a past route');

    $runDate = $dates['run'];
    $wipe($db, $runDate);
    $ensured = bakery_ensure_daily_orders_for_date($db, $runDate, [
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $assert(!empty($ensured['ran']) || ($ensured['skipped_reason'] ?? '') === 'already_generated', 'the Daily Run fixture materializes dated orders');
    $required = bakery_daily_run_required_products($db, $runDate, bakery_standing_day_from_date($runDate));
    $planInsert = $db->prepare('INSERT INTO production_plan_items (delivery_date, product_id, planned_quantity) VALUES (?, ?, ?)');
    foreach ($required['by_product'] as $planProductId => $planQty) {
        $planInsert->execute([$runDate, (int)$planProductId, (int)$planQty]);
        bakery_inventory_set_finished_on_hand($db, $runDate, (int)$planProductId, (int)$planQty, 'autoclose daily run stock');
    }
    bakery_production_plan_commit($db, $runDate, null);
    $db->prepare(
        "UPDATE daily_order_assignments SET delivery_status = 'delivered' WHERE delivery_date = ?"
    )->execute([$runDate]);
    $db->prepare(
        'UPDATE daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         SET doi.delivered_quantity = doi.quantity
         WHERE do.order_date = ?'
    )->execute([$runDate]);
    $db->prepare("UPDATE daily_orders SET status = 'invoiced' WHERE order_date = ?")->execute([$runDate]);
    bakery_demand_confirmation_confirm($db, $runDate, $userId > 0 ? $userId : null);
    $runBefore = $stock($db, $runDate);
    $runNow = new DateTimeImmutable('2096-01-05 11:00:00', $pt);
    $runAuto = bakery_route_autoclose_past($db, $runNow, $runDate);
    $assert((int)$runAuto['closed'] >= 1, 'self-heal style autoclose closes the materialized routes');
    $assert($runBefore === $stock($db, $runDate), 'closing those routes does not change stock');
    $runState = bakery_daily_run_build($db, $runDate);
    if (empty($runState['operational_complete'])) {
        foreach ($runState['stages'] as $stage) {
            echo 'NOTE  stage ' . ($stage['key'] ?? '') . ' ' . ($stage['ui_state'] ?? '') . ' ' . ($stage['summary'] ?? '') . "\n";
        }
        foreach ($runState['blockers'] as $blocker) {
            echo 'NOTE  blocker ' . ($blocker['severity'] ?? '') . ' ' . ($blocker['type'] ?? $blocker['title'] ?? '') . "\n";
        }
    }
    $closedOk = false;
    $closeError = '';
    try {
        bakery_daily_run_close_day($db, $runDate, $userId > 0 ? $userId : null, 'autoclose test');
        $closedOk = true;
    } catch (Throwable $e) {
        $closeError = $e->getMessage();
    }
    $assert($closedOk, 'the Daily Run can close' . ($closeError !== '' ? ' (' . $closeError . ')' : ''));
    $closedStamp = $db->prepare('SELECT closed_at, reopened_at FROM operating_day_closeouts WHERE operating_date = ?');
    $closedStamp->execute([$runDate]);
    $closedState = $closedStamp->fetch(PDO::FETCH_ASSOC) ?: [];
    bakery_route_autoclose_past($db, $runNow, $runDate);
    $closedStamp->execute([$runDate]);
    $closedAgain = $closedStamp->fetch(PDO::FETCH_ASSOC) ?: [];
    $assert(
        !empty($closedState['closed_at'])
            && empty($closedState['reopened_at'])
            && (string)($closedAgain['reopened_at'] ?? '') === (string)($closedState['reopened_at'] ?? ''),
        'a second pass does not reopen the closed day'
    );

    $en = bakery_load_lang_catalog('en');
    $es = bakery_load_lang_catalog('es');
    foreach ([
        'driver.finish_counts',
        'driver.nothing_left',
        'driver.nothing_left_saved',
        'closeout.chip_auto',
        'closeout.chip_implicit',
        'driver_load.implicit_chip',
    ] as $key) {
        $assert(isset($en[$key], $es[$key]), 'lang key ' . $key);
        $assert(strpos((string)$en[$key], "\u{2013}") === false && strpos((string)$en[$key], "\u{2014}") === false, 'en ' . $key . ' has no en or em dash');
        $assert(strpos((string)$es[$key], "\u{2013}") === false && strpos((string)$es[$key], "\u{2014}") === false, 'es ' . $key . ' has no en or em dash');
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
    $fail++;
} finally {
    foreach ($dates as $date) {
        try {
            $wipe($db, $date);
        } catch (Throwable $e) {
            fwrite(STDERR, 'cleanup ' . $date . ' ' . $e->getMessage() . "\n");
        }
    }
    $db->prepare(
        "DELETE FROM operational_events
         WHERE id > ? AND event_type = 'cron_run' AND summary LIKE 'Cron demand_scheduler %'"
    )->execute([$maxEventId]);
    if ($cronBackup === null) {
        if (is_file($cronPath)) {
            unlink($cronPath);
        }
    } else {
        file_put_contents($cronPath, $cronBackup);
    }
}

echo $pass . " passed, " . $fail . " failed\n";
exit($fail > 0 ? 1 : 0);
