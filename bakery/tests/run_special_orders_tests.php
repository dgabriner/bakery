<?php
/**
 * Special orders: one-off lines that are not pan dulce.
 *
 * They belong to a customer and a delivery date, ride the dated stop, bill at
 * the entered price, and keep that stop out of the empty-stop guard.
 * A no-charge mark stays $0. Nothing is sent to Square.
 *
 * CLI / bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);

require_once $root . '/complete_delivery.php';
require_once $root . '/includes/driver_assignment_actions.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/daily_brief.php';
require_once $root . '/includes/billing.php';
require_once $root . '/includes/square_invoices.php';
require_once $root . '/includes/hosted_migration_runtime.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};
$assert_eq = static function ($expected, $actual, string $msg) use ($assert): void {
    $ok = $expected === $actual;
    if (!$ok) {
        $msg .= ' (expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . ')';
    }
    $assert($ok, $msg);
};

$date = '2099-11-03';
$weekday = bakery_standing_day_from_date($date);
echo "Test date: $date weekday $weekday\n";

$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_email'] = 'special-order@example.test';
$_SESSION['user_display_name'] = 'Special Order';
$_SESSION['locale'] = 'en';
if (function_exists('bakery_reset_locale_cache')) {
    bakery_reset_locale_cache();
}

$migrationPath = $root . '/database/schema/108_special_orders.sql';
$migrationSql = (string)file_get_contents($migrationPath);
$assert(is_file($migrationPath), 'migration 108 exists');
$assert(strpos($migrationSql, 'CREATE TABLE IF NOT EXISTS special_orders') !== false, '108 creates special_orders');
$assert(strpos($migrationSql, 'CREATE TABLE IF NOT EXISTS special_order_lines') !== false, '108 creates special_order_lines');
[$hostedSafe] = bakery_hosted_migration_sql_safe($migrationSql);
$assert($hostedSafe === true, '108 is an additive hosted migration');
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $migrationSql))) as $statement) {
    if ($statement !== '') {
        $db->exec($statement);
    }
}
$schemaCache = &bakery_table_exists_cache();
unset($schemaCache['special_orders'], $schemaCache['special_order_lines']);
$assert(bakery_special_orders_ready($db), 'special order tables are ready on bakerysf_test');

$driverId = (int)$db->query('SELECT id FROM drivers WHERE archived = 0 ORDER BY id LIMIT 1')->fetchColumn();
$product = $db->query(
    'SELECT p.id, p.name, p.price
     FROM products p
     WHERE p.price > 0
     ORDER BY p.id
     LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if ($driverId <= 0 || !$product) {
    throw new RuntimeException('bakerysf_test lacks a driver or priced product');
}
$productId = (int)$product['id'];

$customerIds = [];
$insertCustomer = static function (PDO $db, string $name) use (&$customerIds): int {
    $columns = 'name, zone, email, address, phone, payment_collection, is_active';
    $values = '?, ?, ?, ?, ?, ?, 1';
    $params = [
        $name,
        'North Zone',
        strtolower(str_replace(' ', '-', $name)) . '@example.test',
        '1 Special Order Street',
        '555-0199',
        'signature',
    ];
    if (function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin')) {
        $columns .= ', sfb_origin';
        $values .= ', ?';
        $params[] = 'human';
    }
    $db->prepare("INSERT INTO customers ($columns) VALUES ($values)")->execute($params);
    $id = (int)$db->lastInsertId();
    $customerIds[] = $id;
    return $id;
};

$cleanup = static function () use ($db, &$customerIds): void {
    if ($customerIds === []) {
        return;
    }
    $in = implode(',', array_map('intval', $customerIds));
    if (table_exists($db, 'special_orders')) {
        $specialIds = $db->query("SELECT id FROM special_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($specialIds) {
            $specialIn = implode(',', array_map('intval', $specialIds));
            $db->exec("DELETE FROM special_order_lines WHERE special_order_id IN ($specialIn)");
        }
        $db->exec("DELETE FROM special_orders WHERE customer_id IN ($in)");
    }
    $orderIds = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    if ($orderIds) {
        $orderIn = implode(',', array_map('intval', $orderIds));
        $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ($orderIn)");
        $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ($orderIn)");
        if (table_exists($db, 'operational_events')) {
            $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($orderIn)");
        }
        $db->exec("DELETE FROM daily_orders WHERE id IN ($orderIn)");
    }
    $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
    $db->exec("DELETE FROM standing_routes WHERE customer_id IN ($in)");
    if (table_exists($db, 'operational_events')) {
        $db->exec("DELETE FROM operational_events WHERE customer_id IN ($in)");
    }
    $db->exec("DELETE FROM customers WHERE id IN ($in)");
};
register_shutdown_function($cleanup);

$onlyId = $insertCustomer($db, 'SpecialOrder Only');
$withPanId = $insertCustomer($db, 'SpecialOrder WithPan');
$cancelId = $insertCustomer($db, 'SpecialOrder Cancel');
$db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)')
    ->execute([$driverId, $onlyId, $weekday]);
$db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, ?)')
    ->execute([$withPanId, $productId, $weekday, 5]);
$db->prepare('INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)')
    ->execute([$driverId, $withPanId, $weekday]);
$standingBefore = (int)$db->query('SELECT COUNT(*) FROM standing_orders')->fetchColumn();

$saved = bakery_special_order_save($db, [
    'customer_id' => $onlyId,
    'delivery_date' => $date,
    'note' => 'Side door',
    'lines' => [
        [
            'line_kind' => 'text',
            'description' => 'Test sandwich tray',
            'quantity' => 4,
            'unit_price' => 3.50,
        ],
        [
            'line_kind' => 'catalog',
            'product_id' => $productId,
            'quantity' => 2,
            'unit_price' => 2.00,
        ],
        [
            'line_kind' => 'text',
            'description' => 'Test sample',
            'quantity' => 1,
            'unit_price' => 9.00,
            'is_no_charge' => 1,
            'no_charge_reason' => 'sample',
        ],
    ],
]);
$assert(!empty($saved['ok']), 'manager can save a special order');
$specialId = (int)($saved['id'] ?? 0);
$dailyOrderId = (int)($saved['daily_order_id'] ?? 0);
$assert($specialId > 0 && $dailyOrderId > 0, 'save returns the special order and the dated stop');

$assign = $db->prepare('SELECT driver_id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ?');
$assign->execute([$dailyOrderId, $date]);
$assert_eq($driverId, (int)$assign->fetchColumn(), 'a single standing route assigns the special stop');
$panCount = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ?');
$panCount->execute([$dailyOrderId]);
$assert_eq(0, (int)$panCount->fetchColumn(), 'the special order does not write pan dulce lines');
$assert_eq($standingBefore, (int)$db->query('SELECT COUNT(*) FROM standing_orders')->fetchColumn(), 'standing orders are unchanged');

$invoice = bakery_delivery_invoice($db, $dailyOrderId);
$assert(!bakery_delivery_order_is_empty($invoice), 'a special order with quantity is not an empty stop');
$assert_eq(0, (int)$invoice['ordered_pieces'], 'pan dulce piece count stays separate');
$assert(abs((float)$invoice['special_total'] - 18.00) < 0.02, 'invoice special total uses entered prices and skips no-charge');

$add = bakery_driver_assignment_action_add_customer_to_route($db, [
    'customer_id' => $onlyId,
    'driver_id' => $driverId,
    'delivery_date' => $date,
], null);
$assert(empty($add['needs_restore']), 'adding a special stop does not ask to restore an empty order');

$review = bakery_demand_review_build($db, $date, []);
$byName = [];
foreach ($review['customers'] as $customer) {
    $byName[(string)$customer['customer_name']] = $customer;
}
$assert(isset($byName['SpecialOrder Only']), 'demand review includes the special customer');
$assert((int)($byName['SpecialOrder Only']['special_units'] ?? 0) === 7, 'special units are 4 plus 2 plus 1');
$assert(($byName['SpecialOrder Only']['state'] ?? '') !== 'empty_daily', 'special demand is not classified empty');
$assert(!bakery_demand_review_is_routed_zero_order($byName['SpecialOrder Only']), 'a routed special stop is not a zero order');
$flags = bakery_demand_review_empty_stop_flags($review['customers']);
$assert(!in_array('SpecialOrder Only', $flags['names'], true), 'morning empty-stop flags skip the special customer');

$lines = bakery_special_order_lines($db, $specialId);
$textLineId = 0;
$catalogLineId = 0;
foreach ($lines as $line) {
    if ((string)$line['description'] === 'Test sandwich tray') {
        $textLineId = (int)$line['id'];
    }
    if ((string)$line['line_kind'] === 'catalog') {
        $catalogLineId = (int)$line['id'];
    }
}
$confirmed = bakery_confirm_delivery($db, $dailyOrderId, 0, 0, [
    'special_quantities' => [
        $textLineId => 2,
        $catalogLineId => 2,
    ],
    'amount_collected' => 0,
]);
$assert(!empty($confirmed['success']), 'driver can close a special-only stop with zero pan pieces');
$assert(abs((float)$confirmed['total'] - 11.00) < 0.02, 'confirmed total is delivered special prices only');
$header = $db->prepare('SELECT total_amount, delivery_order_total, status FROM daily_orders WHERE id = ?');
$header->execute([$dailyOrderId]);
$headerRow = $header->fetch(PDO::FETCH_ASSOC);
$assert_eq('delivered', (string)($headerRow['status'] ?? ''), 'the dated stop is delivered');
$assert(abs((float)($headerRow['delivery_order_total'] ?? 0) - 11.00) < 0.02, 'delivery header stores the special total');

$itemsByOrder = bakery_billing_load_items($db, [$dailyOrderId]);
$billingItems = $itemsByOrder[$dailyOrderId] ?? [];
$specialBilling = array_values(array_filter($billingItems, static function ($item) {
    return !empty($item['is_special_order']);
}));
$assert(count($specialBilling) === 3, 'billing items include the special lines');
$squareRefused = false;
try {
    bakery_square_order_line_items(['items' => $billingItems]);
} catch (RuntimeException $e) {
    $squareRefused = strpos($e->getMessage(), 'no snapshot line items') !== false;
}
$assert($squareRefused, 'a special-only invoice is not sent to Square');
$classified = bakery_billing_classify_order($headerRow + [
    'delivery_confirmed_at' => date('Y-m-d H:i:s'),
    'credits_taken_back' => 0,
    'delivered_pieces' => 0,
], $billingItems);
$assert(empty($classified['pricing_issue']) || ($classified['pricing_issue'] ?? null) === false, 'an entered special price is not a missing catalog price');

$locked = bakery_special_order_cancel($db, $specialId);
$assert(empty($locked['ok']), 'a confirmed delivery cannot cancel the special order');
$assert_eq('already_delivered', $locked['error'] ?? '', 'cancel explains that the delivery is confirmed');

$panSaved = bakery_special_order_save($db, [
    'customer_id' => $withPanId,
    'delivery_date' => $date,
    'lines' => [
        [
            'line_kind' => 'text',
            'description' => 'Test cake',
            'quantity' => 1,
            'unit_price' => 12.00,
        ],
    ],
]);
$assert(!empty($panSaved['ok']), 'a customer with standing demand can also have a special order');
$panOrderId = (int)$panSaved['daily_order_id'];
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, 2, 1.50, 3.00)'
)->execute([$panOrderId, $productId]);
bakery_customer_update_daily_total($db, $panOrderId);
$panHeader = $db->prepare('SELECT total_amount FROM daily_orders WHERE id = ?');
$panHeader->execute([$panOrderId]);
$assert(abs((float)$panHeader->fetchColumn() - 15.00) < 0.02, 'open header is pan dulce plus the special price');
$panItemId = (int)$db->query('SELECT id FROM daily_order_items WHERE daily_order_id = ' . $panOrderId)->fetchColumn();
$panConfirmed = bakery_confirm_delivery($db, $panOrderId, 2, 0, [
    'line_quantities' => [$panItemId => 2],
    'amount_collected' => 0,
]);
$assert(abs((float)$panConfirmed['total'] - 15.00) < 0.02, 'closeout bills pan dulce and the special line together');
$mixedItems = bakery_billing_load_items($db, [$panOrderId]);
$squareLines = bakery_square_order_line_items(['items' => $mixedItems[$panOrderId] ?? []]);
$squareNames = array_column($squareLines, 'name');
$assert(in_array((string)$product['name'], $squareNames, true), 'Square still receives the pan dulce line');
$assert(!in_array('Special order: Test cake', $squareNames, true), 'Square does not receive the special line');

$cancelSaved = bakery_special_order_save($db, [
    'customer_id' => $cancelId,
    'delivery_date' => $date,
    'lines' => [
        ['line_kind' => 'text', 'description' => 'Test cookies', 'quantity' => 3, 'unit_price' => 1.25],
    ],
]);
$cancelResult = bakery_special_order_cancel($db, (int)$cancelSaved['id']);
$assert(!empty($cancelResult['ok']), 'an open special order can be cancelled');
$cancelOrderId = (int)$cancelSaved['daily_order_id'];
$assert(!bakery_special_order_has_positive_qty($db, $cancelOrderId), 'a cancelled special order no longer counts as quantity');

$run = bakery_daily_run_build($db, $date);
$blockerText = json_encode($run['blockers'] ?? []);
$assert(is_string($blockerText) && strpos($blockerText, 'SpecialOrder Only') === false, 'daily run does not call the special stop empty');
$changes = bakery_daily_brief_important_changes($db, $date, bakery_demand_review_build($db, $date, []));
$emptyTitles = array_values(array_filter(array_column($changes, 'title'), static function ($title) {
    $title = (string)$title;
    $named = strpos($title, 'SpecialOrder Only') !== false || strpos($title, 'SpecialOrder WithPan') !== false;
    return $named && strpos($title, 'Empty dated order') !== false;
}));
$assert($emptyTitles === [], 'morning brief does not flag a positive special order as empty');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach (['special_order.title', 'special_order.badge', 'driver.special_order_heading', 'nav.item.special_orders'] as $key) {
    $assert(($en[$key] ?? '') !== '' && ($es[$key] ?? '') !== '', 'lang key ' . $key . ' exists in English and Spanish');
    $assert(strpos((string)$en[$key], "\u{2014}") === false && strpos((string)$en[$key], "\u{2013}") === false, 'English ' . $key . ' has no en or em dash');
    $assert(strpos((string)$es[$key], "\u{2014}") === false && strpos((string)$es[$key], "\u{2013}") === false, 'Spanish ' . $key . ' has no en or em dash');
}
$page = (string)file_get_contents($root . '/special_orders.php');
$assert(strpos($page, 'bakery_csrf_field') !== false, 'the input page uses the CSRF field');
$assert(strpos($page, 'bakery_require_role') !== false, 'the input page requires a manager role');
$driverJs = (string)file_get_contents($root . '/includes/driver_delivery.js');
$assert(strpos($driverJs, 'special_delivered') !== false, 'driver closeout posts special quantities');
$assert(strpos($driverJs, 'delivery-invoice-item--special') !== false, 'driver invoice labels special lines');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
