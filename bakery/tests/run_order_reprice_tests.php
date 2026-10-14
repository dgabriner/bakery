<?php
/**
 * Catalog price changes must not silently rewrite booked lines, and must
 * offer a manager action for open future lines that still carry the old price.
 *
 * Open means not delivered, not locked, not invoiced, and not closed.
 * No-charge lines (migration 088) stay $0. Standing orders store quantity only.
 *
 * Usage: php tests/run_order_reprice_tests.php
 * Database: bakerysf_test only. Never Staging or Live.
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

require_once $root . '/includes/customer_portal.php';
require_once $root . '/includes/operational_timeline.php';
require_once $root . '/includes/daily_orders_actions.php';

$include = $root . '/includes/order_reprice.php';
if (!is_file($include)) {
    fwrite(STDERR, "FAIL  includes/order_reprice.php is missing\n");
    exit(1);
}
require_once $include;

if (function_exists('bakery_set_locale')) {
    $GLOBALS['bakery_i18n_catalog'] = null;
    bakery_set_locale('en', false);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function orp_money(float $expected, $actual, string $message): void
{
    $actualFloat = $actual === null ? 0.0 : (float)$actual;
    assert_true(abs($expected - $actualFloat) < 0.005, $message . " (expected={$expected} actual={$actualFloat})");
}

function orp_line(PDO $db, int $orderId, int $productId): ?array
{
    $stmt = $db->prepare(
        'SELECT unit_price, line_total, quantity, is_no_charge
         FROM daily_order_items WHERE daily_order_id = ? AND product_id = ?'
    );
    $stmt->execute([$orderId, $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function orp_events(PDO $db, int $orderId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM operational_events
         WHERE daily_order_id = ? AND event_type = 'line_price_repaired'"
    );
    $stmt->execute([$orderId]);
    return (int)$stmt->fetchColumn();
}

echo "=== Where a price is stored ===\n";
assert_true(column_exists($db, 'products', 'price'), 'retail catalog price is products.price');
assert_true(column_exists($db, 'products', 'wholesale_price'), 'wholesale catalog price is products.wholesale_price');
assert_true(column_exists($db, 'daily_order_items', 'unit_price'), 'dated lines store daily_order_items.unit_price');
assert_true(column_exists($db, 'daily_order_items', 'line_total'), 'dated lines store daily_order_items.line_total');
assert_true(column_exists($db, 'daily_orders', 'delivery_order_total'), 'delivery confirm snapshots daily_orders.delivery_order_total');
assert_true(column_exists($db, 'customers', 'default_pan_dulce_price'), 'store pan dulce rate is customers.default_pan_dulce_price');
assert_true(table_exists($db, 'customer_product_prices'), 'custom tier prices live on customer_product_prices');
assert_true(!column_exists($db, 'standing_orders', 'price'), 'standing orders do not store price');
assert_true(!column_exists($db, 'standing_orders', 'unit_price'), 'standing orders do not store unit_price');
assert_true(column_exists($db, 'standing_orders', 'quantity'), 'standing orders store quantity');
assert_true(!table_exists($db, 'order_line_price_snapshots'), 'there is no separate order-line price snapshot table');
assert_true(
    function_exists('bakery_standing_orders_store_unit_price') && bakery_standing_orders_store_unit_price($db) === false,
    'reprice helper agrees standing orders have no unit price'
);

assert_true(function_exists('bakery_order_reprice_list_stale'), 'stale-line listing exists');
assert_true(function_exists('bakery_order_reprice_apply_product'), 'product reprice action exists');
assert_true(function_exists('bakery_order_reprice_apply_order'), 'single-order reprice action exists');
assert_true(function_exists('bakery_order_reprice_zero_catalog_blocked'), 'zero catalog add guard exists');

$stamp = 'orp' . bin2hex(random_bytes(3));
$today = '2098-04-02';
$customerIds = [];
$productIds = [];
$orderIds = [];
$userIds = [];
$closeDates = ['2098-04-08', '2098-04-12'];

$cleanup = static function () use ($db, &$customerIds, &$productIds, &$orderIds, &$userIds, $closeDates, $stamp): void {
    if ($orderIds !== []) {
        $in = implode(',', array_map('intval', $orderIds));
        $db->exec("DELETE FROM operational_events WHERE daily_order_id IN ($in)");
        $db->exec("DELETE FROM daily_orders WHERE id IN ($in)");
    }
    if ($closeDates !== []) {
        $quoted = implode(',', array_map(static fn(string $d): string => $db->quote($d), $closeDates));
        $db->exec("DELETE FROM operating_day_closeouts WHERE operating_date IN ($quoted)");
    }
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ($in)");
        $db->exec("DELETE FROM customers WHERE id IN ($in)");
    }
    if ($productIds !== []) {
        $in = implode(',', array_map('intval', $productIds));
        $db->exec("DELETE FROM products WHERE id IN ($in)");
    }
    if ($userIds !== []) {
        $in = implode(',', array_map('intval', $userIds));
        $db->exec("DELETE FROM users WHERE id IN ($in)");
    }
};

try {
    if (!column_exists($db, 'daily_order_items', 'is_no_charge')) {
        $db->exec(
            'ALTER TABLE daily_order_items
                ADD COLUMN is_no_charge TINYINT(1) NOT NULL DEFAULT 0,
                ADD COLUMN no_charge_reason VARCHAR(32) NULL DEFAULT NULL'
        );
        $cache = &bakery_schema_column_cache();
        unset($cache['daily_order_items.is_no_charge'], $cache['daily_order_items.no_charge_reason']);
    }
    assert_true(bakery_order_line_no_charge_ready($db), 'migration 088 marker is available');
    assert_true(column_exists($db, 'daily_orders', 'square_invoice_id'), 'square invoice id is on the order');
    assert_true(table_exists($db, 'billing_invoice_sends'), 'invoice sends exist');
    assert_true(table_exists($db, 'operating_day_closeouts'), 'operating day closeouts exist');

    $doughId = (int)$db->query("SELECT id FROM dough_types WHERE name = 'Demo Sourdough' LIMIT 1")->fetchColumn();
    $sweetId = (int)$db->query("SELECT id FROM dough_types WHERE name = 'Demo Sweet Dough' LIMIT 1")->fetchColumn();
    $driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
    $managerRole = (int)$db->query("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1")->fetchColumn();
    // Migration 007 is not applied by scripts/run_migrations.php (it jumps 006 to 008).
    // A fresh bakerysf_test therefore has no baker role unless staff codes were seeded.
    $db->exec(
        "INSERT INTO roles (slug, name, description) VALUES
         ('baker', 'Baker', 'Production and pack list only')
         ON DUPLICATE KEY UPDATE name = VALUES(name)"
    );
    $bakerRole = (int)$db->query("SELECT id FROM roles WHERE slug = 'baker' LIMIT 1")->fetchColumn();
    assert_true($doughId > 0 && $sweetId > 0 && $driverId > 0 && $managerRole > 0 && $bakerRole > 0, 'fixtures provide dough, driver, and roles');

    $insertUser = $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id) VALUES (?, ?, ?, ?, ?)'
    );
    $insertUser->execute([$stamp . '-mgr@example.test', 'x', substr(bin2hex(random_bytes(2)), 0, 4), $stamp . ' manager', $managerRole]);
    $managerId = (int)$db->lastInsertId();
    $userIds[] = $managerId;
    $insertUser->execute([$stamp . '-baker@example.test', 'x', substr(bin2hex(random_bytes(2)), 0, 4), $stamp . ' baker', $bakerRole]);
    $bakerId = (int)$db->lastInsertId();
    $userIds[] = $bakerId;
    $manager = ['id' => $managerId, 'role_slug' => 'manager'];
    $baker = ['id' => $bakerId, 'role_slug' => 'baker'];

    $insertCustomer = $db->prepare(
        'INSERT INTO customers (name, zone, email, address, phone, payment_collection, is_active, pricing_tier, default_pan_dulce_price)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
    );
    $insertCustomer->execute([$stamp . ' retail', 'North Zone', $stamp . '-r@example.test', '1 Reprice Street', '555-0191', 'signature', 'retail', null]);
    $retailId = (int)$db->lastInsertId();
    $customerIds[] = $retailId;
    $insertCustomer->execute([$stamp . ' wholesale', 'North Zone', $stamp . '-w@example.test', '2 Reprice Street', '555-0192', 'signature', 'wholesale', null]);
    $wholesaleId = (int)$db->lastInsertId();
    $customerIds[] = $wholesaleId;
    $insertCustomer->execute([$stamp . ' custom', 'North Zone', $stamp . '-c@example.test', '3 Reprice Street', '555-0193', 'signature', 'custom', null]);
    $customId = (int)$db->lastInsertId();
    $customerIds[] = $customId;
    $insertCustomer->execute([$stamp . ' pandulce', 'North Zone', $stamp . '-p@example.test', '4 Reprice Street', '555-0194', 'signature', 'retail', 1.25]);
    $panCustomerId = (int)$db->lastInsertId();
    $customerIds[] = $panCustomerId;

    $insertProduct = $db->prepare(
        'INSERT INTO products (name, dough_type_id, price, wholesale_price, weight_grams, description) VALUES (?, ?, ?, ?, 80, ?)'
    );
    $insertProduct->execute([$stamp . ' bagel', $doughId, 0.00, 2.00, 'sesame-style fixture']);
    $bagelId = (int)$db->lastInsertId();
    $productIds[] = $bagelId;
    $insertProduct->execute([$stamp . ' custombread', $doughId, 1.00, null, 'custom tier fixture']);
    $customProductId = (int)$db->lastInsertId();
    $productIds[] = $customProductId;
    $insertProduct->execute([$stamp . ' concha', $sweetId, 0.00, null, 'pan dulce fixture']);
    $conchaId = (int)$db->lastInsertId();
    $productIds[] = $conchaId;
    $insertProduct->execute([$stamp . ' priced', $doughId, 0.75, null, 'already priced']);
    $pricedId = (int)$db->lastInsertId();
    $productIds[] = $pricedId;

    $db->prepare(
        'INSERT INTO customer_product_prices (customer_id, product_id, unit_price) VALUES (?, ?, ?)'
    )->execute([$customId, $customProductId, 3.00]);

    $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, 1, 3)'
    )->execute([$retailId, $bagelId]);

    $insertOrder = $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, delivery_order_total, delivery_confirmed_at, square_invoice_id)
         VALUES (?, ?, ?, 0, ?, ?, ?)'
    );
    $insertItem = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total, is_no_charge, no_charge_reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    $make = static function (
        int $customerId,
        string $date,
        string $status,
        int $productId,
        int $qty,
        float $price,
        int $noCharge = 0,
        ?string $reason = null,
        $snapshot = null,
        ?string $confirmedAt = null,
        ?string $square = null
    ) use ($insertOrder, $insertItem, &$orderIds): int {
        $insertOrder->execute([$customerId, $date, $status, $snapshot, $confirmedAt, $square]);
        $orderId = (int)$GLOBALS['db']->lastInsertId();
        $orderIds[] = $orderId;
        $insertItem->execute([$orderId, $productId, $qty, $price, round($qty * $price, 2), $noCharge, $reason]);
        return $orderId;
    };
    $GLOBALS['db'] = $db;

    $openFuture = $make($retailId, '2098-04-03', 'pending', $bagelId, 4, 0.00);
    $openToday = $make($retailId, '2098-04-02', 'confirmed', $bagelId, 1, 0.00);
    $delivered = $make($retailId, '2098-04-04', 'delivered', $bagelId, 2, 0.00, 0, null, 12.00, '2098-04-04 08:00:00');
    $invoiced = $make($retailId, '2098-04-05', 'invoiced', $bagelId, 2, 0.00);
    $van = $make($retailId, '2098-04-06', 'out_for_delivery', $bagelId, 2, 0.00);
    $lockedAssign = $make($retailId, '2098-04-07', 'pending', $bagelId, 2, 0.00);
    $closed = $make($retailId, '2098-04-08', 'pending', $bagelId, 2, 0.00);
    $past = $make($retailId, '2098-03-01', 'pending', $bagelId, 2, 0.00);
    $noCharge = $make($retailId, '2098-04-09', 'pending', $bagelId, 2, 0.00, 1, 'comp');
    $special = $make($retailId, '2098-04-10', 'pending', $bagelId, 2, 1.25);
    $sentInvoice = $make($retailId, '2098-04-11', 'pending', $bagelId, 2, 0.00);
    $reopened = $make($retailId, '2098-04-12', 'pending', $bagelId, 2, 0.00);
    $squareOrder = $make($retailId, '2098-04-14', 'pending', $bagelId, 2, 0.00, 0, null, null, null, 'sq_orp_test');
    $wholesaleOrder = $make($wholesaleId, '2098-04-13', 'pending', $bagelId, 5, 2.00);
    $customOrder = $make($customId, '2098-04-15', 'pending', $customProductId, 1, 3.00);
    $panOrder = $make($panCustomerId, '2098-04-16', 'pending', $conchaId, 2, 1.25);

    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
         VALUES (?, ?, ?, 8801, ?)'
    )->execute([$lockedAssign, $driverId, '2098-04-07', 'in_transit']);
    $db->prepare(
        'INSERT INTO operating_day_closeouts (operating_date, manager_note) VALUES (?, ?)'
    )->execute(['2098-04-08', 'orp closed']);
    $db->prepare(
        'INSERT INTO operating_day_closeouts (operating_date, manager_note, reopened_at) VALUES (?, ?, NOW())'
    )->execute(['2098-04-12', 'orp reopened']);
    $db->prepare(
        'INSERT INTO billing_invoice_sends (daily_order_id, invoice_number, amount, channel, status)
         VALUES (?, ?, 0, ?, ?)'
    )->execute([$sentInvoice, 'INV-' . $stamp, 'log', 'logged']);

    $db->prepare('UPDATE products SET price = 0.75 WHERE id = ?')->execute([$bagelId]);

    echo "\n=== Open future lines that still carry the old price ===\n";
    $stale = bakery_order_reprice_list_stale($db, $bagelId, 0.00, 2.00, $today);
    $staleIds = array_map(static fn(array $row): int => (int)$row['order_id'], $stale);
    sort($staleIds);
    $expected = [$openFuture, $openToday, $reopened];
    sort($expected);
    assert_eq($expected, $staleIds, 'warning lists only open future lines still at the old price');
    $byOrder = [];
    foreach ($stale as $row) {
        $byOrder[(int)$row['order_id']] = $row;
    }
    assert_eq(4, (int)$byOrder[$openFuture]['quantity'], 'stale row keeps the booked quantity');
    orp_money(0.00, $byOrder[$openFuture]['old_unit_price'], 'stale row shows the old unit price');
    orp_money(0.75, $byOrder[$openFuture]['new_unit_price'], 'stale row shows the new catalog price');
    assert_true(!in_array($wholesaleOrder, $staleIds, true), 'wholesale line at the wholesale price is not stale when only retail changes');
    assert_true(!in_array($noCharge, $staleIds, true), 'no-charge line is not listed');
    assert_true(!in_array($special, $staleIds, true), 'a line that was not the old catalog price is not listed');
    assert_true(!in_array($delivered, $staleIds, true), 'delivered order is not listed');
    assert_true(!in_array($invoiced, $staleIds, true), 'invoiced order is not listed');
    assert_true(!in_array($sentInvoice, $staleIds, true), 'an order with a sent invoice is not listed');
    assert_true(!in_array($squareOrder, $staleIds, true), 'an order with a square invoice is not listed');
    assert_true(!in_array($van, $staleIds, true), 'out for delivery is not listed');
    assert_true(!in_array($lockedAssign, $staleIds, true), 'in-transit assignment is not listed');
    assert_true(!in_array($closed, $staleIds, true), 'closed operating day is not listed');
    assert_true(!in_array($past, $staleIds, true), 'past pending order is not listed');

    $unchanged = bakery_order_reprice_note_price_change($db, $bagelId, 0.75, 2.00, 0.75, 2.00, $today);
    assert_true($unchanged === null, 'saving the same price does not warn');

    echo "\n=== Reprice action is manager-only, CSRF-checked, and idempotent ===\n";
    $_SESSION['csrf_token'] = 'orp-token';
    $_POST['csrf_token'] = 'nope';
    $badCsrf = bakery_order_reprice_apply_product($db, $bagelId, 0.00, 2.00, $manager, $today, true);
    assert_true(($badCsrf['ok'] ?? true) === false && ($badCsrf['error'] ?? '') === 'csrf', 'bad CSRF is refused');
    orp_money(0.00, orp_line($db, $openFuture, $bagelId)['unit_price'], 'bad CSRF does not change the line');

    $_POST['csrf_token'] = 'orp-token';
    $asBaker = bakery_order_reprice_apply_product($db, $bagelId, 0.00, 2.00, $baker, $today, true);
    assert_true(($asBaker['ok'] ?? true) === false && ($asBaker['error'] ?? '') === 'forbidden', 'a baker cannot reprice');
    orp_money(0.00, orp_line($db, $openFuture, $bagelId)['unit_price'], 'baker refusal leaves the line');

    $applied = bakery_order_reprice_apply_product($db, $bagelId, 0.00, 2.00, $manager, $today, true);
    assert_true(($applied['ok'] ?? false) === true, 'manager reprice succeeds');
    assert_eq(3, (int)($applied['updated'] ?? -1), 'three open lines are repriced');
    orp_money(0.75, orp_line($db, $openFuture, $bagelId)['unit_price'], 'open future line takes the new price');
    orp_money(3.00, orp_line($db, $openFuture, $bagelId)['line_total'], 'line total is quantity times the new price');
    orp_money(0.75, orp_line($db, $openToday, $bagelId)['unit_price'], 'today open line is repriced');
    orp_money(0.75, orp_line($db, $reopened, $bagelId)['unit_price'], 'reopened day is repriced');
    $total = (float)$db->query('SELECT total_amount FROM daily_orders WHERE id = ' . (int)$openFuture)->fetchColumn();
    orp_money(3.00, $total, 'order total follows the repriced lines');

    orp_money(0.00, orp_line($db, $delivered, $bagelId)['unit_price'], 'delivered line stays at the booked price');
    orp_money(12.00, $db->query('SELECT delivery_order_total FROM daily_orders WHERE id = ' . (int)$delivered)->fetchColumn(), 'delivery snapshot stays frozen');
    orp_money(0.00, orp_line($db, $invoiced, $bagelId)['unit_price'], 'invoiced line stays');
    orp_money(0.00, orp_line($db, $sentInvoice, $bagelId)['unit_price'], 'sent invoice line stays');
    orp_money(0.00, orp_line($db, $squareOrder, $bagelId)['unit_price'], 'square invoice line stays');
    orp_money(0.00, orp_line($db, $van, $bagelId)['unit_price'], 'van line stays');
    orp_money(0.00, orp_line($db, $lockedAssign, $bagelId)['unit_price'], 'locked assignment line stays');
    orp_money(0.00, orp_line($db, $closed, $bagelId)['unit_price'], 'closed day line stays');
    orp_money(0.00, orp_line($db, $past, $bagelId)['unit_price'], 'past line stays');
    orp_money(0.00, orp_line($db, $noCharge, $bagelId)['unit_price'], 'no-charge line stays $0');
    assert_eq(1, (int)orp_line($db, $noCharge, $bagelId)['is_no_charge'], 'no-charge marker stays set');
    orp_money(1.25, orp_line($db, $special, $bagelId)['unit_price'], 'special price is not overwritten by the product action');
    orp_money(2.00, orp_line($db, $wholesaleOrder, $bagelId)['unit_price'], 'wholesale line is unchanged when retail changes');

    assert_eq(1, orp_events($db, $openFuture), 'the open future reprice is logged');
    assert_eq(1, orp_events($db, $openToday), 'the today reprice is logged');
    assert_eq(1, orp_events($db, $reopened), 'the reopened reprice is logged');
    assert_eq(0, orp_events($db, $delivered), 'delivered history is not logged as a reprice');
    assert_eq(0, orp_events($db, $noCharge), 'no-charge skip is not logged as a reprice');

    $again = bakery_order_reprice_apply_product($db, $bagelId, 0.00, 2.00, $manager, $today, true);
    assert_true(($again['ok'] ?? false) === true, 'second reprice succeeds');
    assert_eq(0, (int)($again['updated'] ?? -1), 'second reprice changes nothing');
    assert_eq(1, orp_events($db, $openFuture), 'second reprice does not log again');
    orp_money(0.75, orp_line($db, $openFuture, $bagelId)['unit_price'], 'second reprice leaves the new price');

    $standingQty = (int)$db->query(
        'SELECT quantity FROM standing_orders WHERE customer_id = ' . (int)$retailId . ' AND product_id = ' . (int)$bagelId
    )->fetchColumn();
    assert_eq(3, $standingQty, 'standing quantity is untouched');

    echo "\n=== Wholesale and custom prices ===\n";
    $db->prepare('UPDATE products SET wholesale_price = 2.50 WHERE id = ?')->execute([$bagelId]);
    $wholesaleStale = bakery_order_reprice_list_stale($db, $bagelId, 0.75, 2.00, $today);
    $wholesaleIds = array_map(static fn(array $row): int => (int)$row['order_id'], $wholesaleStale);
    assert_eq([$wholesaleOrder], $wholesaleIds, 'wholesale change lists the wholesale line that still has the old wholesale price');
    $wholesaleApplied = bakery_order_reprice_apply_product($db, $bagelId, 0.75, 2.00, $manager, $today, true);
    assert_eq(1, (int)($wholesaleApplied['updated'] ?? -1), 'one wholesale line is repriced');
    orp_money(2.50, orp_line($db, $wholesaleOrder, $bagelId)['unit_price'], 'wholesale line takes the new wholesale price');
    orp_money(12.50, orp_line($db, $wholesaleOrder, $bagelId)['line_total'], 'wholesale line total follows quantity');

    $db->prepare('UPDATE products SET price = 1.50 WHERE id = ?')->execute([$customProductId]);
    $customStale = bakery_order_reprice_list_stale($db, $customProductId, 1.00, null, $today);
    assert_eq([], array_map(static fn(array $row): int => (int)$row['order_id'], $customStale), 'custom tier line is not stale when retail changes');
    orp_money(3.00, orp_line($db, $customOrder, $customProductId)['unit_price'], 'custom line stays on the customer price');

    $db->prepare('UPDATE products SET price = 0.75 WHERE id = ?')->execute([$conchaId]);
    $panStale = bakery_order_reprice_list_stale($db, $conchaId, 0.00, null, $today);
    assert_eq([], array_map(static fn(array $row): int => (int)$row['order_id'], $panStale), 'pan dulce store price is not replaced by a catalog edit');
    orp_money(1.25, orp_line($db, $panOrder, $conchaId)['unit_price'], 'pan dulce line stays on the store rate');

    echo "\n=== Reprice one order from the catalog ===\n";
    $refused = bakery_order_reprice_apply_order($db, $delivered, $manager, $today, true);
    assert_true(($refused['ok'] ?? true) === false, 'delivered order refuses reprice from catalog');
    orp_money(0.00, orp_line($db, $delivered, $bagelId)['unit_price'], 'refused order line stays');
    $pastRefused = bakery_order_reprice_apply_order($db, $past, $manager, $today, true);
    assert_true(($pastRefused['ok'] ?? true) === false, 'past order refuses reprice from catalog');
    $closedRefused = bakery_order_reprice_apply_order($db, $closed, $manager, $today, true);
    assert_true(($closedRefused['ok'] ?? true) === false, 'closed day refuses reprice from catalog');

    $one = bakery_order_reprice_apply_order($db, $special, $manager, $today, true);
    assert_true(($one['ok'] ?? false) === true, 'open order reprice succeeds');
    assert_eq(1, (int)($one['updated'] ?? -1), 'the special line is updated from the catalog');
    orp_money(0.75, orp_line($db, $special, $bagelId)['unit_price'], 'order button sets the current catalog price');
    orp_money(1.50, orp_line($db, $special, $bagelId)['line_total'], 'order button updates the line total');
    assert_eq(1, orp_events($db, $special), 'order reprice is logged');
    $oneAgain = bakery_order_reprice_apply_order($db, $special, $manager, $today, true);
    assert_eq(0, (int)($oneAgain['updated'] ?? -1), 'order reprice is idempotent');
    assert_eq(1, orp_events($db, $special), 'idempotent order reprice does not log again');

    $noChargeOrder = bakery_order_reprice_apply_order($db, $noCharge, $manager, $today, true);
    assert_true(($noChargeOrder['ok'] ?? false) === true, 'no-charge order can be asked to reprice');
    assert_eq(0, (int)($noChargeOrder['updated'] ?? -1), 'no-charge line is skipped');
    orp_money(0.00, orp_line($db, $noCharge, $bagelId)['unit_price'], 'no-charge line is still $0 after the order button');
    assert_eq(1, (int)orp_line($db, $noCharge, $bagelId)['is_no_charge'], 'no-charge marker remains');

    $db->prepare('UPDATE products SET price = 0.00, wholesale_price = NULL WHERE id = ?')->execute([$conchaId]);

    echo "\n=== $0.00 catalog add is blocked unless No charge ===\n";
    assert_true(
        bakery_order_reprice_zero_catalog_blocked(['price' => 0], 0.0, false) === true,
        'unmarked $0 catalog price is blocked'
    );
    assert_true(
        bakery_order_reprice_zero_catalog_blocked(['price' => 0], 0.0, true) === false,
        'No charge is allowed at $0'
    );
    assert_true(
        bakery_order_reprice_zero_catalog_blocked(['price' => 0, 'wholesale_price' => 4], 4.0, false) === false,
        'a real resolved price is not blocked'
    );

    $zeroAdd = bakery_daily_orders_action_add_item($db, [
        'order_id' => $openFuture,
        'product_id' => $conchaId,
        'quantity' => 2,
    ], $manager);
    assert_true(($zeroAdd['success'] ?? true) === false, 'adding a $0 catalog product is refused');
    assert_true(($zeroAdd['code'] ?? '') === 'zero_catalog', 'refusal names the zero catalog block');
    $conchaOnOrder = orp_line($db, $openFuture, $conchaId);
    assert_true($conchaOnOrder === null, 'refused add does not insert a line');

    $db->prepare('UPDATE products SET price = 0.00, wholesale_price = 4.00 WHERE id = ?')->execute([$conchaId]);
    $wholesaleAdd = bakery_daily_orders_action_add_item($db, [
        'order_id' => $wholesaleOrder,
        'product_id' => $conchaId,
        'quantity' => 2,
    ], $manager);
    assert_true(($wholesaleAdd['success'] ?? false) === true, 'wholesale customer can add a product whose wholesale price is set');
    orp_money(4.00, orp_line($db, $wholesaleOrder, $conchaId)['unit_price'], 'added wholesale line uses the wholesale price');

    $marked = bakery_daily_orders_action_add_item($db, [
        'order_id' => $openToday,
        'product_id' => $conchaId,
        'quantity' => 1,
        'is_no_charge' => '1',
        'no_charge_reason' => 'sample',
    ], $manager);
    assert_true(($marked['success'] ?? false) === true, 'No charge add of a $0 product succeeds');
    $markedLine = orp_line($db, $openToday, $conchaId);
    assert_true($markedLine !== null, 'No charge line is inserted');
    orp_money(0.00, $markedLine['unit_price'], 'No charge line is stored at $0');
    assert_eq(1, (int)$markedLine['is_no_charge'], 'No charge marker is stored');

    $pricedAdd = bakery_daily_orders_action_add_item($db, [
        'order_id' => $openFuture,
        'product_id' => $pricedId,
        'quantity' => 3,
    ], $manager);
    assert_true(($pricedAdd['success'] ?? false) === true, 'a priced product can be added');
    orp_money(0.75, orp_line($db, $openFuture, $pricedId)['unit_price'], 'added line snapshots the catalog price');

    $kept = bakery_product_editor_next_wholesale([], 2.50);
    orp_money(2.50, $kept, 'inline save that omits wholesale keeps the stored wholesale price');
    assert_true(bakery_product_editor_next_wholesale(['wholesale_price' => ''], 2.50) === null, 'a blank wholesale field clears wholesale');

    echo "\n=== $0.00 catalog save asks for confirmation ===\n";
    $active = ['price' => 0.75, 'is_active' => 1];
    assert_true(bakery_product_zero_price_confirm_required($active, '0.00') === true, 'changing an active product to $0.00 requires confirmation');
    assert_true(bakery_product_zero_price_confirm_required($active, 0) === true, 'a numeric zero price requires confirmation');
    assert_true(bakery_product_zero_price_confirm_required(['price' => 0.00], '0.00') === false, 'a product already at $0.00 does not ask again');
    assert_true(bakery_product_save_zero_price_block(['price' => 0.00], '0.00', []) === null, 'a product already at $0.00 can be saved again');
    assert_true(bakery_product_save_zero_price_block($active, '0.00', []) === 'reprice.zero_save_blocked', 'a zero save without confirmation is blocked');
    assert_true(bakery_product_save_zero_price_block($active, '0.00', ['confirm_zero_price' => '1']) === null, 'a confirmed zero save is allowed');
    assert_true(bakery_product_save_zero_price_block($active, '1.25', []) === null, 'a non-zero save does not ask');
    assert_true(bakery_product_save_zero_price_block($active, '', []) === null, 'clearing the price to blank is not a $0.00 save');
    assert_true(bakery_product_save_zero_price_block(['price' => 0.75, 'is_active' => 0], '0', []) === null, 'an inactive product can be saved at $0.00 without confirmation');
    assert_true(bakery_product_save_zero_price_block([], '0.00', []) === 'reprice.zero_save_blocked', 'creating an active product at $0.00 requires confirmation');
    assert_true(bakery_product_shows_in_no_price_filter(['name' => 'Poppy Seed Bagel', 'price' => 0.00]) === true, 'an active $0.00 product is in the No price filter');
    assert_true(bakery_product_shows_in_no_price_filter(['price' => null]) === true, 'a blank catalog price is in the No price filter');
    assert_true(bakery_product_shows_in_no_price_filter(['price' => '0.75']) === false, 'a priced product is not in the No price filter');
    assert_true(bakery_product_shows_in_no_price_filter(['price' => 0, 'is_active' => 0]) === false, 'an inactive $0.00 product is not in the No price filter');

    echo "\n=== Page contracts ===\n";
    $productsPage = (string)file_get_contents($root . '/products.php');
    $ordersPage = (string)file_get_contents($root . '/daily_orders.php');
    $actions = (string)file_get_contents($root . '/includes/daily_orders_actions.php');
    $en = (string)file_get_contents($root . '/lang/en.php');
    $es = (string)file_get_contents($root . '/lang/es.php');
    assert_true(strpos($productsPage, 'reprice_open_orders') !== false, 'products page posts the reprice action');
    assert_true(strpos($productsPage, 'bakery_require_csrf()') !== false, 'products POST requires CSRF');
    assert_true(strpos($productsPage, "bakery_t('reprice.action')") !== false, 'products page uses the reprice label');
    assert_true(strpos($productsPage, "bakery_t('reprice.warn_standing')") !== false, 'products warning says standing orders do not store a price');
    assert_true(substr_count($productsPage, 'bakery_product_save_zero_price_block') >= 3, 'create, update, and batch save confirm a $0.00 price');
    assert_true(strpos($productsPage, 'id="priceFilter"') !== false, 'the product list has a price filter');
    assert_true(strpos($productsPage, "bakery_t('reprice.filter_no_price')") !== false, 'the price filter offers No price');
    assert_true(strpos($productsPage, 'data-no-price') !== false, 'product rows mark a missing catalog price');
    assert_true(strpos($productsPage, 'confirm_zero_price') !== false, 'a confirmed zero save posts the confirmation');
    assert_true(strpos($productsPage, 'zeroPriceConfirm') !== false, 'the zero price save uses the confirm dialog');
    assert_true(strpos($ordersPage, 'reprice_order_from_catalog') !== false, 'daily orders can reprice one order');
    assert_true(strpos($ordersPage, "bakery_t('reprice.order_button')") !== false, 'daily orders shows the catalog button');
    assert_true(strpos($ordersPage, "bakery_t('reprice.zero_warn')") !== false, 'add product warns on a $0 catalog price');
    assert_true(strpos($actions, 'reprice_order_from_catalog') !== false, 'dispatch handles order reprice');
    assert_true(strpos($actions, 'bakery_order_reprice_zero_catalog_blocked') !== false, 'add item uses the zero catalog guard');
    assert_true(strpos($en, "'reprice.action'") !== false && strpos($es, "'reprice.action'") !== false, 'reprice copy is in English and Spanish');
    foreach (['reprice.action', 'reprice.zero_block', 'reprice.order_button', 'reprice.warn_title', 'reprice.zero_save_confirm', 'reprice.zero_save_blocked', 'reprice.filter_no_price'] as $key) {
        $enPos = strpos($en, "'" . $key . "'");
        $esPos = strpos($es, "'" . $key . "'");
        assert_true($enPos !== false && $esPos !== false, $key . ' exists in both catalogs');
        $enLine = '';
        if (preg_match("/'" . preg_quote($key, '/') . "' => '([^']*)'/", $en, $m)) {
            $enLine = $m[1];
        }
        $esLine = '';
        if (preg_match("/'" . preg_quote($key, '/') . "' => '([^']*)'/", $es, $m)) {
            $esLine = $m[1];
        }
        assert_true(strpos($enLine, "\u{2014}") === false && strpos($enLine, "\u{2013}") === false, $key . ' English has no en or em dash');
        assert_true(strpos($esLine, "\u{2014}") === false && strpos($esLine, "\u{2013}") === false, $key . ' Spanish has no en or em dash');
    }
} catch (Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'ERROR ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

$cleanup();

echo "\nPASS {$GLOBALS['TEST_PASS']}  FAIL {$GLOBALS['TEST_FAIL']}\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
