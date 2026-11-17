<?php
/**
 * Staff-page self-heal for a missed nightly demand build, and idempotent Restore.
 * CLI / bakerysf_test only. No email or SMS.
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
require_once $root . '/includes/auth.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/cron_run.php';
require_once $root . '/includes/daily_order_generation.php';
require_once $root . '/includes/driver_assignments.php';
require_once $root . '/includes/driver_assignment_actions.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'bakerysf_test') {
    fwrite(STDERR, "Refusing: database is not bakerysf_test\n");
    exit(1);
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

echo "\n=== Proven gaps the self-heal has to cover ===\n";
$worker = (string)file_get_contents($root . '/scripts/hosted_promotion_worker.php');
$assert(
    strpos($worker, '#^(?:storage|database|scripts|tests|docs)(?:/|$)#') !== false,
    'files-only Live promotion skips scripts/'
);
$scheduler = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
$assert(strpos($scheduler, "PHP_SAPI !== 'cli'") !== false, 'non-CLI cron exits before the try block');
$assert(
    strpos($scheduler, 'bakery_cron_record_run') > strpos($scheduler, 'try {'),
    'a cron_run row is recorded only after requires succeed'
);
$kit = (string)file_get_contents($root . '/docs/CRON_KIT.md');
$assert(strpos($kit, 'demand horizon lazily') === false, 'CRON_KIT no longer says page loads fill lazily');
$assert(strpos($kit, 'page loads keep filling demand lazily') === false, 'CRON_KIT drops the lazy page-load note');
$assert(strpos($kit, 'demand_self_heal.php') !== false, 'CRON_KIT documents the self-heal endpoint');
$assert(
    strpos($kit, '30 2 * * * /usr/local/bin/php /home/dh_dp755h/bakery.sourflour.org/bake/scripts/demand_scheduler.php >> /home/dh_dp755h/cron_demand.log 2>&1') !== false,
    'CRON_KIT keeps the exact Live cron line'
);

echo "\n=== Beacon is a POST, not a GET write ===\n";
$header = (string)file_get_contents($root . '/includes/header.php');
$assert(strpos($header, 'bakery_demand_self_heal_render_beacon') !== false, 'staff header can fire the beacon');
$assert(strpos($header, 'bakery_demand_self_heal_if_stale') === false, 'header render does not run the build');
foreach (['daily_run.php', 'manager.php', 'driver.php', 'driver_assignment.php', 'index.php', 'sales_home.php', 'daily_route.php'] as $page) {
    $src = (string)file_get_contents($root . '/' . $page);
    $assert(strpos($src, 'includes/header.php') !== false, $page . ' uses the staff header');
    $assert(strpos($src, 'bakery_demand_self_heal_if_stale') === false, $page . ' GET does not call the build');
    $assert(strpos($src, 'bakery_fill_demand_horizon(') === false, $page . ' GET does not fill the horizon');
}
$endpointPath = $root . '/demand_self_heal.php';
$endpoint = is_file($endpointPath) ? (string)file_get_contents($endpointPath) : '';
$assert($endpoint !== '' && strpos($endpoint, 'BAKERY_SKIP_REQUEST_SECURITY') === false, 'the endpoint stays behind the auth gate');
$assert($endpoint !== '' && strpos($endpoint, 'mail(') === false && stripos($endpoint, 'twilio') === false, 'the endpoint sends no mail or SMS');
$roles = function_exists('bakery_navigation_roles_for_script')
    ? bakery_navigation_roles_for_script('demand_self_heal.php')
    : [];
foreach (['administrator', 'manager', 'driver', 'driver_assistant'] as $allowed) {
    $assert(in_array($allowed, $roles, true), $allowed . ' may open the self-heal endpoint');
}
foreach (['baker', 'cashier', 'sales'] as $blocked) {
    $assert(!in_array($blocked, $roles, true), $blocked . ' is not given a new page');
}
$assert(
    function_exists('bakery_sales_page_scripts')
        && !in_array('demand_self_heal.php', bakery_sales_page_scripts(), true),
    'sales keeps the five lookup pages'
);
$assert(
    function_exists('bakery_navigation_scripts_for_role')
        && in_array('demand_self_heal.php', bakery_navigation_scripts_for_role('driver'), true),
    'driver may open the self-heal endpoint'
);
$authSrc = (string)file_get_contents($root . '/includes/auth.php');
$assert(
    strpos($authSrc, "script === 'demand_self_heal.php'") !== false
        && strpos($authSrc, 'bakery_demand_self_heal_roles()') !== false,
    'a sales POST is allowed before the sales page redirect'
);
$assert(
    bakery_demand_self_heal_roles() === ['administrator', 'manager', 'driver', 'driver_assistant', 'sales'],
    'driver and sales can trigger the build'
);

$sellDay = '2099-11-17';
$weekday = 2;
$pt = new DateTimeZone('America/Los_Angeles');
$beforeWindow = new DateTimeImmutable($sellDay . ' 02:59:00', $pt);
$cronStamp = new DateTimeImmutable($sellDay . ' 02:40:00', $pt);
$afterWindow = new DateTimeImmutable($sellDay . ' 10:15:00', $pt);
$originSql = (function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin')) ? ', sfb_origin' : '';
$originVal = $originSql !== '' ? ", 'human'" : '';
$productId = (int)$db->query(
    "SELECT p.id
     FROM products p
     JOIN dough_types dt ON dt.id = p.dough_type_id
     JOIN product_lines pl ON pl.id = dt.product_line_id
     ORDER BY p.id
     LIMIT 1"
)->fetchColumn();
$userId = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$maxOrderId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM daily_orders')->fetchColumn();
$maxEventId = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM operational_events')->fetchColumn();
$cronPath = bakery_cron_storage_dir() . '/demand_scheduler.json';
$cronBackup = is_file($cronPath) ? (string)file_get_contents($cronPath) : null;
$exceptionKey = str_pad('selfheal-exception', 64, '0');

$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$insertDriver->execute(['Self Heal Ada']);
$adaId = (int)$db->lastInsertId();
$insertDriver->execute(['Self Heal Bo']);
$boId = (int)$db->lastInsertId();
$insertCustomer = $db->prepare(
    'INSERT INTO customers (name, address, is_active' . $originSql . ') VALUES (?, ?, 1' . $originVal . ')'
);
$customerIds = [];
$names = ['Self Heal Empty', 'Self Heal Kept', 'Self Heal Skipped', 'Self Heal Moved', 'Self Heal Off', 'Self Heal On'];
foreach ($names as $index => $name) {
    $insertCustomer->execute([$name, (80 + $index) . ' Heal Lane']);
    $customerIds[$name] = (int)$db->lastInsertId();
}
$emptyId = $customerIds['Self Heal Empty'];
$keptId = $customerIds['Self Heal Kept'];
$skipId = $customerIds['Self Heal Skipped'];
$movedId = $customerIds['Self Heal Moved'];
$offId = $customerIds['Self Heal Off'];
$onId = $customerIds['Self Heal On'];

$standingOrder = $db->prepare(
    'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)'
);
$standingRoute = $db->prepare(
    'INSERT INTO standing_routes (day_of_week, driver_id, customer_id, route_order) VALUES (?, ?, ?, ?)'
);
$standingOrder->execute([$emptyId, $productId, $weekday, 4]);
$standingRoute->execute([$weekday, $adaId, $emptyId, 1]);
$standingOrder->execute([$keptId, $productId, $weekday, 4]);
$standingRoute->execute([$weekday, $adaId, $keptId, 2]);
$standingOrder->execute([$skipId, $productId, $weekday, 6]);
$standingRoute->execute([$weekday, $adaId, $skipId, 3]);
$db->prepare('INSERT INTO customer_delivery_skips (customer_id, skip_date, note) VALUES (?, ?, ?)')
    ->execute([$skipId, $sellDay, 'one-time skip']);
$db->prepare(
    'INSERT INTO manager_exception_work (exception_key, operating_date, exception_type, exception_category, resolution_note)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$exceptionKey, $sellDay, 'self_heal_probe', 'test', 'leave this row']);

$orderCount = static function (PDO $db, int $customerId, string $date): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE customer_id = ? AND order_date = ?');
    $stmt->execute([$customerId, $date]);
    return (int)$stmt->fetchColumn();
};
$itemQty = static function (PDO $db, int $customerId, string $date, int $productId): ?int {
    $stmt = $db->prepare(
        'SELECT doi.quantity
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doi.product_id = ?
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date, $productId]);
    $qty = $stmt->fetchColumn();
    return $qty === false ? null : (int)$qty;
};
$assignmentDriver = static function (PDO $db, int $customerId, string $date): int {
    $stmt = $db->prepare(
        'SELECT doa.driver_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?
         ORDER BY doa.id
         LIMIT 1'
    );
    $stmt->execute([$customerId, $date, $date]);
    return (int)$stmt->fetchColumn();
};
$assignmentRows = static function (PDO $db, int $customerId, string $date): int {
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?'
    );
    $stmt->execute([$customerId, $date, $date]);
    return (int)$stmt->fetchColumn();
};
$session = static function (string $role) use ($userId): void {
    $_SESSION['user_id'] = $userId > 0 ? $userId : 1;
    $_SESSION['user_email'] = 'self-heal@example.test';
    $_SESSION['user_display_name'] = 'Self Heal';
    $_SESSION['user_role_slug'] = $role;
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    $_SESSION['csrf_token'] = 'self-heal-csrf';
};
$clearStamp = static function () use ($db, $maxEventId, $cronPath, $cronBackup): void {
    $db->prepare(
        "DELETE FROM operational_events
         WHERE event_type = 'cron_run' AND summary LIKE 'Cron demand_scheduler %'
           AND (id > ? OR metadata LIKE ?)"
    )->execute([$maxEventId, '%2099-11-17%']);
    if ($cronBackup === null) {
        if (is_file($cronPath)) {
            unlink($cronPath);
        }
        return;
    }
    file_put_contents($cronPath, $cronBackup);
};
$okRuns = static function () use ($db, $maxEventId): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM operational_events
         WHERE id > ? AND event_type = 'cron_run' AND summary = 'Cron demand_scheduler ok'"
    );
    $stmt->execute([$maxEventId]);
    return (int)$stmt->fetchColumn();
};

$cleanup = static function () use (
    $db, $customerIds, $adaId, $boId, $maxOrderId, $maxEventId, $exceptionKey, $cronPath, $cronBackup
): void {
    $ids = array_values($customerIds);
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $orderStmt = $db->prepare("SELECT id FROM daily_orders WHERE customer_id IN ($ph) OR id > ?");
        $orderStmt->execute(array_merge($ids, [$maxOrderId]));
        $orderIds = array_map('intval', $orderStmt->fetchAll(PDO::FETCH_COLUMN));
        if ($orderIds !== []) {
            $oph = implode(',', array_fill(0, count($orderIds), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($oph)")->execute($orderIds);
            $db->prepare("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($oph)")->execute($orderIds);
            $db->prepare("DELETE FROM daily_orders WHERE id IN ($oph)")->execute($orderIds);
        }
        $db->prepare("DELETE FROM standing_orders WHERE customer_id IN ($ph)")->execute($ids);
        $db->prepare("DELETE FROM standing_routes WHERE customer_id IN ($ph)")->execute($ids);
        if (table_exists($db, 'customer_delivery_skips')) {
            $db->prepare("DELETE FROM customer_delivery_skips WHERE customer_id IN ($ph)")->execute($ids);
        }
        $db->prepare("DELETE FROM customers WHERE id IN ($ph)")->execute($ids);
    }
    $db->prepare('DELETE FROM drivers WHERE id IN (?, ?)')->execute([$adaId, $boId]);
    $db->prepare('DELETE FROM manager_exception_work WHERE exception_key = ?')->execute([$exceptionKey]);
    $db->prepare(
        "DELETE FROM operational_events
         WHERE event_type = 'cron_run' AND summary LIKE 'Cron demand_scheduler %'
           AND (id > ? OR metadata LIKE ?)"
    )->execute([$maxEventId, '%2099-11-17%']);
    if ($cronBackup === null) {
        if (is_file($cronPath)) {
            unlink($cronPath);
        }
    } else {
        file_put_contents($cronPath, $cronBackup);
    }
};

try {
    $assert($productId > 0 && $userId > 0 && $adaId > 0, 'fixtures have a product, a user, and drivers');
    $assert(function_exists('bakery_demand_self_heal_if_stale'), 'self-heal function exists');
    $assert(function_exists('bakery_demand_self_heal_dispatch'), 'self-heal dispatch exists');

    $session('administrator');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $get = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert((int)($get['status'] ?? 0) === 405, 'GET is rejected');
    $assert($orderCount($db, $emptyId, $sellDay) === 0, 'plain GET creates zero orders');
    $assert($okRuns() === 0, 'plain GET records no cron_run');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['action' => 'drop_orders'];
    $session('driver');
    $early = bakery_demand_self_heal_dispatch($db, $beforeWindow);
    $assert((int)($early['status'] ?? 0) === 200, 'driver POST before 3 AM is accepted');
    $assert(($early['body']['ran'] ?? true) === false, 'before 3 AM does not build');
    $assert((string)($early['body']['reason'] ?? '') === 'before_window', 'before 3 AM reports before_window');
    $assert($orderCount($db, $emptyId, $sellDay) === 0, 'before 3 AM leaves the day empty');
    $assert($okRuns() === 0, 'before 3 AM records no successful cron_run');

    $keptOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, \'pending\', ?)'
    );
    $keptOrder->execute([$keptId, $sellDay, 9]);
    $keptOrderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$keptOrderId, $productId, 9, 1, 9]);
    bakery_cron_record_run($db, 'demand_scheduler', 'ok', ['source' => 'cron'], $cronStamp);
    $stamped = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert(($stamped['body']['ran'] ?? true) === false, 'a successful build already recorded for the sell day is a no-op');
    $assert((string)($stamped['body']['reason'] ?? '') === 'already_built', 'existing success reports already_built');
    $assert($orderCount($db, $emptyId, $sellDay) === 0, 'already_built does not fill the empty customer');
    $assert($itemQty($db, $keptId, $sellDay, $productId) === 9, 'already_built leaves the existing quantity');
    $clearStamp();

    $session('driver');
    $_POST = ['action' => 'drop_orders', 'delete' => '1'];
    $built = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert((int)($built['status'] ?? 0) === 200 && ($built['body']['ran'] ?? false) === true, 'driver POST builds a day with no dated orders');
    $assert((string)($built['body']['source'] ?? '') === 'self_heal', 'the build reports source self_heal');
    $assert($orderCount($db, $emptyId, $sellDay) === 1, 'the empty customer gets one dated order');
    $assert($itemQty($db, $emptyId, $sellDay, $productId) === 4, 'the new order copies the standing quantity');
    $assert($assignmentDriver($db, $emptyId, $sellDay) === $adaId, 'the new order is on the standing route');
    $assert($itemQty($db, $keptId, $sellDay, $productId) === 9, 'an existing dated quantity survives');
    $assert((int)$db->query('SELECT id FROM daily_orders WHERE customer_id = ' . $keptId . ' AND order_date = ' . $db->quote($sellDay))->fetchColumn() === $keptOrderId, 'the existing order row is not replaced');
    $assert($orderCount($db, $skipId, $sellDay) === 0, 'a one-time skip is not filled');
    $note = (string)$db->query('SELECT resolution_note FROM manager_exception_work WHERE exception_key = ' . $db->quote($exceptionKey))->fetchColumn();
    $assert($note === 'leave this row', 'an exception row survives the build');
    $stampStmt = $db->prepare(
        "SELECT metadata FROM operational_events
         WHERE id > ? AND event_type = 'cron_run' AND summary = 'Cron demand_scheduler ok'
         ORDER BY id DESC LIMIT 1"
    );
    $stampStmt->execute([$maxEventId]);
    $meta = json_decode((string)$stampStmt->fetchColumn(), true);
    $assert(is_array($meta) && (string)($meta['source'] ?? '') === 'self_heal', 'cron_run metadata source is self_heal');
    $runsAfterBuild = $okRuns();
    $assert($runsAfterBuild === 1, 'one successful cron_run row is recorded');

    $again = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert(($again['body']['ran'] ?? true) === false, 'a second call the same day is a no-op');
    $assert((string)($again['body']['reason'] ?? '') === 'already_built', 'the second call reports already_built');
    $assert($orderCount($db, $emptyId, $sellDay) === 1, 'the second call does not duplicate the order');
    $assert($assignmentRows($db, $emptyId, $sellDay) === 1, 'the second call does not duplicate the route stop');
    $assert($itemQty($db, $keptId, $sellDay, $productId) === 9, 'the second call still preserves the edited quantity');
    $assert($okRuns() === $runsAfterBuild, 'the second call does not record another cron_run');

    $session('sales');
    $sales = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert((int)($sales['status'] ?? 0) === 200 && ($sales['body']['ran'] ?? true) === false, 'sales may trigger the endpoint and gets a no-op');
    $session('baker');
    $baker = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert((int)($baker['status'] ?? 0) === 403, 'baker cannot use the endpoint');
    $assert($orderCount($db, $emptyId, $sellDay) === 1, 'a rejected role writes nothing');
    $session('cashier');
    $cashier = bakery_demand_self_heal_dispatch($db, $afterWindow);
    $assert((int)($cashier['status'] ?? 0) === 403, 'cashier cannot use the endpoint');

    $clearStamp();
    $db->prepare('DELETE FROM daily_orders WHERE customer_id = ? AND order_date = ?')->execute([$emptyId, $sellDay]);
    $assert($orderCount($db, $emptyId, $sellDay) === 0, 'lock setup removes only the healed order');
    $other = check_mysql_connection();
    $other->query("SELECT GET_LOCK('bakery_demand_self_heal', 1)")->fetchColumn();
    $session('manager');
    $locked = bakery_demand_self_heal_if_stale($db, $afterWindow);
    $assert((string)($locked['reason'] ?? '') === 'locked', 'a concurrent call does not start a second build');
    $assert($orderCount($db, $emptyId, $sellDay) === 0, 'the locked call creates zero orders');
    $other->query("SELECT RELEASE_LOCK('bakery_demand_self_heal')");
    $unlocked = bakery_demand_self_heal_if_stale($db, $afterWindow);
    $assert(($unlocked['ran'] ?? false) === true, 'the build runs after the lock is released');
    $assert($orderCount($db, $emptyId, $sellDay) === 1, 'the released call creates one order');
    $assert($itemQty($db, $keptId, $sellDay, $productId) === 9, 'the released call still preserves the edited order');
    $third = bakery_demand_self_heal_if_stale($db, $afterWindow);
    $assert(($third['ran'] ?? true) === false && $orderCount($db, $emptyId, $sellDay) === 1, 'the call after a concurrent build is a no-op');

    echo "\n=== Restore ignores stops already on a route ===\n";
    $session('administrator');
    $offOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, \'pending\', ?)'
    );
    $offOrder->execute([$offId, $sellDay, 1]);
    $offOrderId = (int)$db->lastInsertId();
    $onOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, \'pending\', ?)'
    );
    $onOrder->execute([$onId, $sellDay, 1]);
    $onOrderId = (int)$db->lastInsertId();
    $movedOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, \'pending\', ?)'
    );
    $movedOrder->execute([$movedId, $sellDay, 1]);
    $movedOrderId = (int)$db->lastInsertId();
    $assign = $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, ?, \'pending\')'
    );
    $assign->execute([$onOrderId, $adaId, $sellDay, 3]);
    $assign->execute([$movedOrderId, $boId, $sellDay, 1]);
    $standingRoute->execute([$weekday, $adaId, $offId, 4]);
    $standingRoute->execute([$weekday, $adaId, $onId, 5]);
    $standingRoute->execute([$weekday, $adaId, $movedId, 6]);
    $standingOrder->execute([$offId, $productId, $weekday, 2]);
    $standingOrder->execute([$onId, $productId, $weekday, 2]);
    $standingOrder->execute([$movedId, $productId, $weekday, 2]);

    $labels = [];
    foreach (bakery_route_missing_standing_by_driver($db, $sellDay) as $group) {
        if ((int)$group['driver_id'] === $adaId) {
            $labels = $group['labels'] ?? [];
        }
    }
    $labelText = implode(' | ', $labels);
    $assert(strpos($labelText, 'Self Heal Off') !== false, 'Restore flags the unassigned standing stop');
    $assert(strpos($labelText, 'Self Heal Moved') === false, 'Restore does not flag a stop already on another driver');
    $assert(strpos($labelText, 'Self Heal On') === false, 'Restore does not flag a stop already on this driver');

    $restored = bakery_driver_assignment_action_sync_driver_route($db, [
        'driver_id' => $adaId,
        'delivery_date' => $sellDay,
    ]);
    $assert(strpos((string)($restored['message'] ?? ''), 'Self Heal Off') !== false, 'Restore names the stop it added');
    $assert($assignmentDriver($db, $offId, $sellDay) === $adaId, 'Restore puts the missing stop on Ada');
    $assert($assignmentRows($db, $offId, $sellDay) === 1, 'Restore adds the missing stop once');
    $assert($assignmentDriver($db, $movedId, $sellDay) === $boId, 'Restore leaves the other driver stop where it is');
    $assert($assignmentRows($db, $movedId, $sellDay) === 1, 'Restore does not duplicate the other driver stop');
    $onRoute = $db->prepare(
        'SELECT route_order FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?'
    );
    $onRoute->execute([$onOrderId, $sellDay]);
    $assert((int)$onRoute->fetchColumn() === 3 && $assignmentRows($db, $onId, $sellDay) === 1, 'Restore does not re-append a stop already on the route');
    $assert($orderCount($db, $onId, $sellDay) === 1, 'Restore does not create a second dated order');

    $secondRestore = bakery_driver_assignment_action_sync_driver_route($db, [
        'driver_id' => $adaId,
        'delivery_date' => $sellDay,
    ]);
    $assert(
        strpos((string)($secondRestore['message'] ?? ''), 'No missing standing stops') !== false,
        'a second Restore is a no-op'
    );
    $assert($assignmentRows($db, $offId, $sellDay) === 1, 'the second Restore does not duplicate the stop');
    $assert($assignmentDriver($db, $movedId, $sellDay) === $boId, 'the second Restore still leaves the other driver alone');
    $assert($orderCount($db, $offId, $sellDay) === 1, 'the second Restore does not create another order');
} catch (Throwable $e) {
    $assert(false, 'self-heal scenario threw: ' . $e->getMessage());
} finally {
    try {
        if (isset($other) && $other instanceof PDO) {
            $other->query("SELECT RELEASE_LOCK('bakery_demand_self_heal')");
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'lock release: ' . $e->getMessage() . "\n");
    }
    $cleanup();
}

echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
