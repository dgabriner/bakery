<?php
/**
 * Rolling demand horizon: standing → dated, Monday bake/route cadence.
 * CLI / local only. Cleans up the synthetic dates it uses.
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
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/demand_confirmation.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: demand scheduler tests must run with APP_ENV=local\n");
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

$from = '2026-08-17'; // Monday
$cadence = bakery_demand_cadence_dates($from);
$assert($cadence['bake_day'] === '2026-08-18', 'Monday cadence bake day is Tuesday');
$assert($cadence['route_date'] === '2026-08-19', 'Monday cadence route date is Wednesday');
$assert($cadence['production_date'] === '2026-08-19', 'production sheet is keyed on Wednesday delivery');
$assert($cadence['horizon_end'] === '2026-08-23', 'default horizon is 7 days through Sunday');

$dates = bakery_demand_horizon_date_list($from, 3);
$assert($dates === ['2026-08-17', '2026-08-18', '2026-08-19'], '3-day horizon is Mon–Wed');
$assert(bakery_demand_horizon_date_list('nope') === [], 'invalid from-date yields no horizon');

$tickSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(is_file($root . '/scripts/demand_scheduler.php'), 'cron tick script exists');
$assert(strpos($tickSrc, 'bakery_demand_scheduler_assert_cli') !== false, 'cron script uses the CLI guard');
$assert(strpos($tickSrc, 'bakery.sourflour.org/bake/scripts/demand_scheduler.php') !== false, 'DreamHost path is documented');

$en = bakery_load_lang_catalog('en');
$es = bakery_load_lang_catalog('es');
$assert(!empty($en['cadence.title']) && !empty($es['cadence.title']), 'cadence copy exists in en and es');
$assert(!empty($en['cadence.cover_note']) && !empty($es['cadence.cover_note']), 'cover-window cadence copy exists');
$assert($en['cadence.cover_note'] !== $es['cadence.cover_note'], 'cover-window cadence copy is translated');
$assert(!empty($en['daily_run.demand_horizon_filled']) && !empty($es['daily_run.demand_horizon_filled']), 'horizon flash copy exists');

$genSrc = (string)file_get_contents($root . '/includes/daily_order_generation.php');
$assert(strpos($genSrc, "'overwrite_changed' => false") !== false, 'horizon path keeps overwrite_changed off');

$future = date('Y-m-d', strtotime('+24 days'));
$horizonDates = bakery_demand_horizon_date_list($future, 3);
$cleanup = static function (PDO $db, array $dates): void {
    foreach ($dates as $date) {
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
    }
};

$cleanup($db, $horizonDates);

$first = bakery_ensure_daily_orders_horizon($db, $future, [
    'record_event' => false,
    'days' => 3,
]);
$assert($first['days'] === 3, 'horizon reports 3 days');
$assert($first['from_date'] === $future, 'horizon starts on requested date');
$assert($first['ran_count'] >= 0, 'horizon ran or skipped standing-empty weekdays');

if ($first['ran_count'] > 0) {
    $second = bakery_ensure_daily_orders_horizon($db, $future, [
        'record_event' => false,
        'days' => 3,
    ]);
    $assert($second['ran_count'] === 0, 'second horizon pass is a no-op');
    $assert(count($second['skipped']) === 3, 'all three dates skipped as already_generated or empty');

    $item = $db->prepare('
        SELECT doi.id, doi.quantity
        FROM daily_order_items doi
        JOIN daily_orders do ON do.id = doi.daily_order_id
        WHERE do.order_date = ?
        LIMIT 1
    ');
    $item->execute([$future]);
    $row = $item->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newQty = (int)$row['quantity'] + 11;
        $db->prepare('UPDATE daily_order_items SET quantity = ?, line_total = quantity * unit_price WHERE id = ?')
            ->execute([$newQty, (int)$row['id']]);
        bakery_ensure_daily_orders_horizon($db, $future, [
            'record_event' => false,
            'days' => 3,
        ]);
        $check = $db->prepare('SELECT quantity FROM daily_order_items WHERE id = ?');
        $check->execute([(int)$row['id']]);
        $kept = (int)$check->fetchColumn();
        $assert($kept === $newQty, 'dated quantity edit preserved across horizon re-run (got ' . $kept . ')');
    } else {
        echo "NOTE  no daily_order_items on first horizon date — skip edit-preservation assert\n";
    }
} else {
    echo "NOTE  no standing demand on synthetic weekdays — skip generate/preserve asserts\n";
}

$cleanup($db, $horizonDates);

$dashSrc = (string)file_get_contents($root . '/index.php');
$runSrc = (string)file_get_contents($root . '/daily_run.php');
$ordersSrc = (string)file_get_contents($root . '/daily_orders.php');
$prodSrc = (string)file_get_contents($root . '/production.php');
$assert(strpos($dashSrc, 'bakery_fill_demand_horizon') !== false, 'dashboard fills the horizon');
$assert(strpos($dashSrc, 'bakery_render_demand_cadence_strip') !== false, 'dashboard shows cadence strip');
$assert(strpos($runSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Run fills the horizon');
$assert(strpos($ordersSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Orders fills the horizon');
$assert(strpos($prodSrc, 'bakery_fill_demand_horizon') !== false, 'Daily Production fills the horizon');

$schedSrc = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(strpos($schedSrc, 'bakery_cron_record_run') !== false, 'demand_scheduler records cron_run stamps');
$healthSrc = (string)file_get_contents($root . '/health_deploy.php');
$assert(strpos($healthSrc, 'cron.demand_scheduler.age_hours') !== false, 'health_deploy reports demand_scheduler age_hours');
$dashCc = (string)file_get_contents($root . '/includes/dashboard_command_center.php');
$assert(strpos($dashCc, 'cron_overnight_stale') !== false, 'dashboard warns when overnight cron is stale');
require_once $root . '/includes/cron_run.php';
$stamp = bakery_cron_record_run(null, 'demand_scheduler_test', 'ok', ['probe' => 1]);
$assert(($stamp['outcome'] ?? '') === 'ok', 'cron stamp writes outcome');
$assert(bakery_cron_age_hours('demand_scheduler_test') !== null, 'cron age_hours readable after stamp');
@unlink($root . '/storage/cron/demand_scheduler_test.json');

echo "\n=== Standing-route fill copies every stop ===\n";
require_once $root . '/includes/driver_assignments.php';
require_once $root . '/includes/daily_run.php';

$fillDate = '2099-08-19'; // Wednesday
$mondayDate = '2099-08-17';
$fillWeekday = 3;
$mondayWeekday = 1;
$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();
$sweetId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     WHERE p.id <> " . (int)$productId . "
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();
$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$insertDriver->execute(['Route Fill Sergio']);
$sergioId = (int)$db->lastInsertId();
$insertDriver->execute(['Route Fill Laura']);
$lauraId = (int)$db->lastInsertId();
$insertDriver->execute(['Route Fill Other']);
$otherId = (int)$db->lastInsertId();

$originSql = '';
if (function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin')) {
    $originSql = ", sfb_origin";
}
$insertCustomer = $db->prepare(
    'INSERT INTO customers (name, address, is_active' . $originSql . ') VALUES (?, ?, ?' . ($originSql !== '' ? ", 'human'" : '') . ')'
);
$fillCustomerIds = [];
$bySlot = [];
for ($slot = 1; $slot <= 14; $slot++) {
    $name = sprintf('Route Fill Sergio %02d', $slot);
    $insertCustomer->execute([$name, $slot . ' Fill Way', 1]);
    $id = (int)$db->lastInsertId();
    $fillCustomerIds[] = $id;
    $bySlot[$slot] = $id;
}
$insertCustomer->execute(['Route Fill Laura Ordered', '15 Fill Way', 1]);
$lauraOrderedId = (int)$db->lastInsertId();
$fillCustomerIds[] = $lauraOrderedId;
$insertCustomer->execute(['Route Fill Laura Visit', '16 Fill Way', 1]);
$lauraVisitId = (int)$db->lastInsertId();
$fillCustomerIds[] = $lauraVisitId;
$insertCustomer->execute(['Route Fill Inactive', '17 Fill Way', 0]);
$inactiveId = (int)$db->lastInsertId();
$fillCustomerIds[] = $inactiveId;
$insertCustomer->execute(['Route Fill Paused', '18 Fill Way', 1]);
$pausedId = (int)$db->lastInsertId();
$fillCustomerIds[] = $pausedId;

$orphanNames = ['Route Fill Amigos', 'Route Fill Plaza', 'Route Fill Fishtail', 'Route Fill Evergreen'];
$orphanIds = [];
foreach ($orphanNames as $index => $orphanName) {
    $insertCustomer->execute([$orphanName, (20 + $index) . ' Monday Way', 1]);
    $orphanIds[] = (int)$db->lastInsertId();
}
$insertCustomer->execute(['Route Fill Routed Monday', '30 Monday Way', 1]);
$routedMondayId = (int)$db->lastInsertId();
$fillCustomerIds = array_merge($fillCustomerIds, $orphanIds, [$routedMondayId]);

$fillCleanup = static function () use ($db, $fillDate, $mondayDate, $fillCustomerIds, $sergioId, $lauraId, $otherId): void {
    if ($fillCustomerIds !== []) {
        $ph = implode(',', array_fill(0, count($fillCustomerIds), '?'));
        $orderIds = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) AND order_date IN (?, ?)");
        $orderIds->execute(array_merge($fillCustomerIds, [$fillDate, $mondayDate]));
        $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
        if ($ids !== []) {
            $oph = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($ids);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($ids);
        }
        $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($fillCustomerIds);
        if (table_exists($db, 'standing_routes')) {
            $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($fillCustomerIds);
        }
        if (table_exists($db, 'standing_order_pauses')) {
            $db->prepare("DELETE FROM standing_order_pauses WHERE customer_id IN ($ph)")->execute($fillCustomerIds);
        }
        if (table_exists($db, 'customer_delivery_skips')) {
            $db->prepare("DELETE FROM customer_delivery_skips WHERE customer_id IN ($ph)")->execute($fillCustomerIds);
        }
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($fillCustomerIds);
    }
    $db->prepare('DELETE FROM drivers WHERE id IN (?, ?, ?)')->execute([$sergioId, $lauraId, $otherId]);
};

$assignmentCount = static function (PDO $db, int $customerId, string $date): int {
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    );
    $stmt->execute([$customerId, $date, $date]);
    return (int)$stmt->fetchColumn();
};
$assignmentRow = static function (PDO $db, int $customerId, string $date): ?array {
    $stmt = $db->prepare(
        'SELECT doa.driver_id, doa.route_order, doa.delivery_status
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?
         ORDER BY doa.id
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

try {
    $assert($productId > 0 && $sergioId > 0 && $lauraId > 0, 'route-fill fixtures have a product and drivers');
    $standingOrder = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
    );
    $standingRoute = $db->prepare(
        'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
    );
    $gapSlots = [9, 11, 13];
    foreach ($bySlot as $slot => $customerId) {
        $standingRoute->execute([$fillWeekday, $sergioId, $customerId, $slot]);
        if (!in_array($slot, $gapSlots, true)) {
            $standingOrder->execute([$customerId, $productId, $fillWeekday, 2]);
        }
    }
    $standingRoute->execute([$fillWeekday, $lauraId, $lauraOrderedId, 1]);
    $standingOrder->execute([$lauraOrderedId, $productId, $fillWeekday, 3]);
    $standingRoute->execute([$fillWeekday, $lauraId, $lauraVisitId, 2]);
    $standingRoute->execute([$fillWeekday, $sergioId, $inactiveId, 15]);
    $standingOrder->execute([$inactiveId, $productId, $fillWeekday, 4]);
    $standingRoute->execute([$fillWeekday, $sergioId, $pausedId, 16]);
    $standingOrder->execute([$pausedId, $productId, $fillWeekday, 4]);
    if (table_exists($db, 'standing_order_pauses')) {
        $db->prepare('INSERT INTO standing_order_pauses (customer_id, week_start) VALUES (?, ?)')
            ->execute([$pausedId, bakery_week_start_monday($fillDate)]);
    }

    foreach ($orphanIds as $orphanId) {
        $standingOrder->execute([$orphanId, $productId, $mondayWeekday, 4]);
        if ($sweetId > 0) {
            $standingOrder->execute([$orphanId, $sweetId, $mondayWeekday, 2]);
        }
    }
    $standingOrder->execute([$routedMondayId, $productId, $mondayWeekday, 6]);
    $standingRoute->execute([$mondayWeekday, $sergioId, $routedMondayId, 1]);

    $first = bakery_generate_daily_orders_from_standing($db, $fillDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $assert((int)($first['drivers_assigned'] ?? 0) >= 14, 'first fill assigns Sergio and Laura standing stops');
    foreach ($bySlot as $slot => $customerId) {
        $assert($assignmentCount($db, $customerId, $fillDate) === 1, "Sergio slot #$slot is on the dated route");
        $row = $assignmentRow($db, $customerId, $fillDate);
        $assert($row !== null && (int)$row['driver_id'] === $sergioId, "Sergio slot #$slot stays on Sergio");
        $assert($row !== null && (int)$row['route_order'] === $slot, "Sergio slot #$slot keeps standing position $slot");
    }
    $assert($assignmentCount($db, $lauraOrderedId, $fillDate) === 1, 'Laura ordered stop is copied');
    $assert($assignmentCount($db, $lauraVisitId, $fillDate) === 1, 'Laura visit-only stop is copied');
    $assert($assignmentCount($db, $inactiveId, $fillDate) === 0, 'inactive standing stop is not copied');
    $assert($assignmentCount($db, $pausedId, $fillDate) === 0, 'paused standing stop is not copied');
    if (function_exists('bakery_demand_review_build')) {
        $visitReview = bakery_demand_review_build($db, $fillDate, []);
        $visitState = null;
        foreach ($visitReview['customers'] as $visitCustomer) {
            if ((int)$visitCustomer['customer_id'] === $lauraVisitId) {
                $visitState = $visitCustomer['state'];
            }
        }
        $assert($visitState === 'matches', 'visit-only standing stop is not an empty-demand blocker (got ' . var_export($visitState, true) . ')');
    }

    $positions = [];
    foreach ($bySlot as $slot => $customerId) {
        $positions[$slot] = $assignmentRow($db, $customerId, $fillDate);
    }
    $second = bakery_generate_daily_orders_from_standing($db, $fillDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $assert((int)($second['drivers_assigned'] ?? 0) === 0, 're-running the fill does not insert duplicate stops');
    foreach ($bySlot as $slot => $customerId) {
        $assert($assignmentCount($db, $customerId, $fillDate) === 1, "re-run leaves Sergio slot #$slot once");
        $again = $assignmentRow($db, $customerId, $fillDate);
        $assert(
            $again !== null
            && (int)$again['driver_id'] === (int)$positions[$slot]['driver_id']
            && (int)$again['route_order'] === (int)$positions[$slot]['route_order'],
            "re-run preserves Sergio slot #$slot driver and position"
        );
    }
    $dupStmt = $db->prepare(
        'SELECT COUNT(*) FROM (
            SELECT driver_id, route_order
            FROM daily_order_assignments
            WHERE delivery_date = ? AND driver_id IN (?, ?)
            GROUP BY driver_id, route_order
            HAVING COUNT(*) > 1
         ) duplicates'
    );
    $dupStmt->execute([$fillDate, $sergioId, $lauraId]);
    $assert((int)$dupStmt->fetchColumn() === 0, 're-run creates no duplicate route positions');

    $db->prepare(
        'UPDATE daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         SET doa.driver_id = ?, doa.route_order = 7, do.driver_id = ?
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    )->execute([$otherId, $otherId, $bySlot[1], $fillDate, $fillDate]);
    bakery_generate_daily_orders_from_standing($db, $fillDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $movedAfter = $assignmentRow($db, $bySlot[1], $fillDate);
    $assert(
        $movedAfter !== null && (int)$movedAfter['driver_id'] === $otherId && (int)$movedAfter['route_order'] === 7,
        're-run keeps a dated driver move'
    );
    $assert($assignmentCount($db, $bySlot[1], $fillDate) === 1, 'dated driver move is not duplicated back onto the standing driver');
    unset($moved);

    $db->prepare(
        'UPDATE daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         SET doa.delivery_status = \'cancelled\'
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    )->execute([$bySlot[2], $fillDate, $fillDate]);
    bakery_generate_daily_orders_from_standing($db, $fillDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $cancelled = $assignmentRow($db, $bySlot[2], $fillDate);
    $assert($assignmentCount($db, $bySlot[2], $fillDate) === 1, 'cancelled stop is not duplicated');
    $assert($cancelled !== null && (string)$cancelled['delivery_status'] === 'cancelled', 'cancelled stop stays cancelled');

    $db->prepare(
        'DELETE doa FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    )->execute([$bySlot[3], $fillDate, $fillDate]);
    $assert($assignmentCount($db, $bySlot[3], $fillDate) === 0, 'hard-removed stop is gone before the next fill');
    bakery_generate_daily_orders_from_standing($db, $fillDate, [
        'overwrite_changed' => false,
        'record_event' => false,
        'assign_routes' => true,
    ]);
    $restored = $assignmentRow($db, $bySlot[3], $fillDate);
    $assert(
        $restored !== null && (int)$restored['driver_id'] === $sergioId,
        'hard-removed stop is copied again because removal is not tracked'
    );

    $db->prepare(
        'DELETE doa FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    )->execute([$bySlot[9], $fillDate, $fillDate]);
    $ensured = bakery_ensure_daily_orders_for_date($db, $fillDate, [
        'record_event' => false,
        'assign_routes' => true,
        'skip_if_closed' => true,
    ]);
    $assert(!empty($ensured['ran']), 'scheduler ensure backfills a dated route that is missing standing stops');
    $assert($assignmentCount($db, $bySlot[9], $fillDate) === 1, 'ensure puts the missing Wednesday stop back');
    $ensuredAgain = bakery_ensure_daily_orders_for_date($db, $fillDate, [
        'record_event' => false,
        'assign_routes' => true,
        'skip_if_closed' => true,
    ]);
    $assert(empty($ensuredAgain['ran']), 'ensure is a no-op once every standing stop is on the dated route');
    $assert($assignmentCount($db, $bySlot[9], $fillDate) === 1, 'second ensure does not duplicate the backfilled stop');

    $assert(function_exists('bakery_daily_run_unrouted_standing_orders'), 'Daily Run can list standing orders with no driver');
    if (function_exists('bakery_daily_run_unrouted_standing_orders')) {
        $orphans = bakery_daily_run_unrouted_standing_orders($db, $mondayDate);
        $orphanByName = [];
        foreach ($orphans as $orphan) {
            $orphanByName[(string)$orphan['customer_name']] = $orphan;
        }
        foreach ($orphanNames as $orphanName) {
            $assert(isset($orphanByName[$orphanName]), "Monday orphan $orphanName is flagged");
            $summary = (string)($orphanByName[$orphanName]['summary'] ?? '');
            $assert(strpos($summary, '4') !== false, "Monday orphan $orphanName summary includes the standing quantity");
            if ($sweetId > 0) {
                $assert(strpos($summary, '2') !== false, "Monday orphan $orphanName summary includes the second product");
            }
        }
        $assert(!isset($orphanByName['Route Fill Routed Monday']), 'a Monday store that already has a driver is not flagged');
    }

    $assert(function_exists('bakery_driver_assignment_restore_label'), 'Restore label names the missing stores');
    if (function_exists('bakery_driver_assignment_restore_label')) {
        $label = bakery_driver_assignment_restore_label(3, ['Amigos (#9)', 'Plaza (#11)', 'Fishtail (#13)']);
        $assert(
            $label === 'Restore 3 missing stops: Amigos (#9), Plaza (#11), Fishtail (#13)',
            'Restore label lists the missing store names'
        );
        $oneLabel = bakery_driver_assignment_restore_label(1, ['Evergreen (#2)']);
        $assert($oneLabel === 'Restore 1 missing stop: Evergreen (#2)', 'Restore label stays singular for one store');
    }
    $assignPage = (string)file_get_contents($root . '/driver_assignment.php');
    $runPage = (string)file_get_contents($root . '/daily_run.php');
    $assert(strpos($assignPage, 'bakery_driver_assignment_restore_label') !== false, 'Driver Assignment prints store names on Restore');
    $assert(strpos($runPage, 'unrouted_standing_orders') !== false, 'Daily Run renders the unrouted standing-order warning');
    $en = require $root . '/lang/en.php';
    $es = require $root . '/lang/es.php';
    foreach ([
        'driver_assignment.restore_missing_one',
        'driver_assignment.restore_missing_many',
        'driver_assignment.restore_missing_hint',
        'daily_run.unrouted_standing_title',
        'daily_run.unrouted_standing_help',
        'daily_run.unrouted_standing_open',
    ] as $key) {
        $assert(!empty($en[$key]) && !empty($es[$key]), "lang key $key exists in English and Spanish");
    }
} catch (Throwable $e) {
    $assert(false, 'route-fill scenario threw: ' . $e->getMessage());
} finally {
    $fillCleanup();
}

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
