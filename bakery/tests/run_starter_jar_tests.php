<?php
/**
 * Starter jar landing: pickup $5 / ship $30 via education purchase rails.
 * Runs against bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require __DIR__ . '/isolate_test_db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

require_once __DIR__ . '/../includes/sf_baker.php';
require_once __DIR__ . '/../includes/auth.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  {$msg}\n";
        $pass++;
        return;
    }
    echo "FAIL  {$msg}\n";
    $fail++;
};

$assert(in_array('starter.php', bakery_public_scripts(), true), 'starter.php is a public door');

if (!bakery_sfb_payments_ready($db)) {
    echo "NOTE  [blocker] education payments (066+) missing on bakerysf_test\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// Apply 070 table + seeds if the migration has not landed yet (idempotent).
$sqlFile = dirname(__DIR__) . '/database/schema/070_starter_jar_orders.sql';
if (!bakery_sfb_starter_jar_ready($db) && is_file($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0) {
            continue;
        }
        try {
            $db->exec($stmt);
        } catch (Throwable $e) {
            // Seeds may already exist; table create is IF NOT EXISTS.
        }
    }
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('sfb_starter_jar_orders');
    }
}

$assert(bakery_sfb_starter_jar_ready($db), '070 starter jar orders table exists');

$db->prepare('DELETE FROM customers WHERE name IN (?, ?, ?)')
    ->execute(['SFB Jar Customer', 'Starter Buyer', 'Baker 5550199']);
$db->exec("DELETE FROM sfb_offerings WHERE title IN (
    'Sourdough Starter — Bakery Pickup',
    'Sourdough Starter — Shipped',
    'First Loaf Kit — Bakery Pickup'
)");

// Re-seed kits the way 070 does.
try {
    bakery_sfb_create_offering(
        $db,
        'Sourdough Starter — Bakery Pickup',
        5.00,
        'kit',
        'Pickup jar'
    );
    bakery_sfb_create_offering(
        $db,
        'Sourdough Starter — Shipped',
        30.00,
        'kit',
        'Shipped jar'
    );
} catch (Throwable $e) {
    // Titles may already exist from a prior partial run.
}

$pickupOffering = bakery_sfb_starter_jar_offering($db, 'pickup');
$shipOffering = bakery_sfb_starter_jar_offering($db, 'ship');
$assert($pickupOffering && (int)$pickupOffering['price_cents'] === 500, 'pickup kit is $5');
$assert($shipOffering && (int)$shipOffering['price_cents'] === 3000, 'ship kit is $30');

try {
    bakery_sfb_starter_jar_normalize_draft(['fulfillment' => 'pickup', 'contact_name' => 'A']);
    $assert(false, 'pickup without day rejected');
} catch (InvalidArgumentException $e) {
    $assert(true, 'pickup without day rejected');
}

$pickupDraft = bakery_sfb_starter_jar_normalize_draft([
    'fulfillment' => 'pickup',
    'pickup_day' => 'friday',
    'contact_name' => 'Starter Buyer',
    'notes' => 'Please leave at counter',
]);
$assert($pickupDraft['pickup_day'] === 'friday', 'friday pickup normalized');

$shipDraft = bakery_sfb_starter_jar_normalize_draft([
    'fulfillment' => 'ship',
    'contact_name' => 'Starter Buyer',
    'ship_line1' => '12 Flour St',
    'ship_city' => 'San Francisco',
    'ship_state' => 'CA',
    'ship_zip' => '94110',
]);
$assert($shipDraft['fulfillment'] === 'ship' && $shipDraft['ship_zip'] === '94110', 'ship address normalized');

$ins = $db->prepare(
    'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active, sfb_origin)
     VALUES (?, ?, ?, 1, 1, 1, ?)'
);
try {
    $ins->execute(['Baker 5550199', '555-0199', '9 Jar Way', 'human']);
} catch (Throwable $e) {
    $ins = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ins->execute(['Baker 5550199', '555-0199', '9 Jar Way']);
}
$customerId = (int)$db->lastInsertId();
$assert($customerId > 0, 'fixture customer created');

$GLOBALS['bakery_sfb_payments_disabled'] = true;
$buyPickup = bakery_sfb_buy_starter_jar($db, $customerId, $pickupDraft);
unset($GLOBALS['bakery_sfb_payments_disabled']);
$assert($buyPickup['configured'] === false && $buyPickup['order_id'] > 0, 'pickup buy records honest intent + jar order');
$order = bakery_sfb_starter_jar_order($db, $buyPickup['order_id']);
$assert($order && (string)$order['fulfillment'] === 'pickup' && (string)$order['pickup_day'] === 'friday', 'pickup fulfillment stored');
$purchase = bakery_sfb_purchase($db, $buyPickup['purchase_id']);
$assert((string)$purchase['status'] === 'intent' && (int)$purchase['price_cents_snapshot'] === 500, 'pickup price snapshot $5');

$GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null): array {
    $redirect = (string)($body['checkout_options']['redirect_url'] ?? '');
    if (strpos($redirect, 'starter.php?purchased=') === false) {
        throw new RuntimeException('expected starter.php redirect, got ' . $redirect);
    }
    return ['payment_link' => [
        'id' => 'PL-JAR-1',
        'url' => 'https://sandbox.square.link/u/jar-checkout',
        'order_id' => 'ORDER-JAR-1',
    ]];
};
$buyShip = bakery_sfb_buy_starter_jar($db, $customerId, $shipDraft);
unset($GLOBALS['bakery_square_api_handler']);
$assert($buyShip['configured'] === true && strpos((string)$buyShip['url'], 'square.link') !== false, 'ship buy opens Square checkout');
$shipOrder = bakery_sfb_starter_jar_for_purchase($db, $buyShip['purchase_id']);
$assert($shipOrder && (string)$shipOrder['ship_city'] === 'San Francisco', 'ship address stored on jar order');
$shipPurchase = bakery_sfb_purchase($db, $buyShip['purchase_id']);
$assert((string)$shipPurchase['status'] === 'pending' && (int)$shipPurchase['price_cents_snapshot'] === 3000, 'ship pending at $30');

$name = $db->prepare('SELECT name FROM customers WHERE id = ?');
$name->execute([$customerId]);
$assert((string)$name->fetchColumn() === 'Starter Buyer', 'provisional name upgraded from contact name');

$returnPath = bakery_sfb_starter_jar_return_path('continue=1');
$assert(strpos($returnPath, 'starter.php') !== false, 'return path points at starter.php');
$assert(strpos($returnPath, 'continue=1') !== false, 'return path asks to continue checkout');
if (defined('BASE_URL') && BASE_URL !== '' && BASE_URL !== '/') {
    $assert(strpos($returnPath, rtrim((string)BASE_URL, '/')) === 0, 'return path stays under BASE_URL');
}

$sqlKit = dirname(__DIR__) . '/database/schema/072_first_loaf_kit.sql';
if (!bakery_sfb_first_loaf_kit_ready($db) && is_file($sqlKit)) {
    $sql = file_get_contents($sqlKit);
    foreach (array_filter(array_map('trim', explode(';', (string)$sql))) as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0) {
            continue;
        }
        try {
            $db->exec($stmt);
        } catch (Throwable $e) {
            // ALTER/seed may already be applied.
        }
    }
    bakery_forget_column_exists('sfb_starter_jar_orders', 'pack_kind');
}

$assert(bakery_sfb_first_loaf_kit_ready($db), '072 pack_kind column exists');
try {
    bakery_sfb_create_offering(
        $db,
        'First Loaf Kit — Bakery Pickup',
        75.00,
        'kit',
        'Pickup kit'
    );
} catch (Throwable $e) {
    // Seeded by 072 or a prior run.
}
try {
    $db->exec("UPDATE sfb_offerings SET price_cents = 3000 WHERE title = 'Sourdough Starter — Shipped'");
    $db->exec("UPDATE sfb_offerings SET price_cents = 7500 WHERE title = 'First Loaf Kit — Bakery Pickup'");
} catch (Throwable $e) {
    // 073 catalog bump; leftover bakerysf_test rows from $25/$45 seeds.
}
$kitOffering = bakery_sfb_first_loaf_kit_offering($db);
$assert($kitOffering && (int)$kitOffering['price_cents'] === 7500, 'first loaf kit is $75');

try {
    bakery_sfb_starter_jar_normalize_draft([
        'fulfillment' => 'kit',
        'pack_kind' => 'first_loaf_kit',
        'contact_name' => 'Starter Buyer',
        'ship_line1' => '12 Flour St',
        'ship_city' => 'San Francisco',
        'ship_state' => 'CA',
        'ship_zip' => '94110',
    ]);
    // kit + leftover ship fields is fine; fulfillment kit forces pickup — but no pickup_day
    $assert(false, 'kit without pickup day rejected');
} catch (InvalidArgumentException $e) {
    $assert(true, 'kit without pickup day rejected');
}

try {
    bakery_sfb_starter_jar_normalize_draft([
        'fulfillment' => 'ship',
        'pack_kind' => 'first_loaf_kit',
        'contact_name' => 'Starter Buyer',
        'pickup_day' => 'tuesday',
        'ship_line1' => '12 Flour St',
        'ship_city' => 'San Francisco',
        'ship_state' => 'CA',
        'ship_zip' => '94110',
    ]);
    $assert(false, 'kit cannot ship');
} catch (InvalidArgumentException $e) {
    $assert(true, 'kit cannot ship');
}

$kitDraft = bakery_sfb_starter_jar_normalize_draft([
    'fulfillment' => 'kit',
    'pickup_day' => 'tuesday',
    'contact_name' => 'Starter Buyer',
]);
$assert($kitDraft['fulfillment'] === 'pickup' && $kitDraft['pack_kind'] === 'first_loaf_kit', 'kit normalizes to pickup pack');

$GLOBALS['bakery_sfb_payments_disabled'] = true;
$buyKit = bakery_sfb_buy_starter_jar($db, $customerId, $kitDraft);
unset($GLOBALS['bakery_sfb_payments_disabled']);
$assert($buyKit['configured'] === false && $buyKit['order_id'] > 0, 'kit buy records honest intent');
$kitOrder = bakery_sfb_starter_jar_order($db, $buyKit['order_id']);
$assert($kitOrder && (string)$kitOrder['pack_kind'] === 'first_loaf_kit', 'kit pack_kind stored');
$kitPurchase = bakery_sfb_purchase($db, $buyKit['purchase_id']);
$assert((int)$kitPurchase['price_cents_snapshot'] === 7500, 'kit price snapshot $75');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
