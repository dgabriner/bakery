<?php
/**
 * Purchase home: private workshops + Starter Workshop gift certificates.
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

if (!bakery_sfb_payments_ready($db)) {
    echo "NOTE  [blocker] education payments (066+) missing on bakerysf_test\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

$sqlFile = dirname(__DIR__) . '/database/schema/071_bread_education_purchase_home.sql';
if (!bakery_sfb_purchase_home_ready($db) && is_file($sqlFile)) {
    $sql = file_get_contents($sqlFile);
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
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('sfb_private_workshop_bookings');
        bakery_forget_table_exists('sfb_gift_certificates');
    }
}

$assert(bakery_sfb_purchase_home_ready($db), '071 purchase home tables exist');

$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')
    ->execute(['SFB Purchase Home Buyer', 'SFB Gift Redeemer']);
$db->exec("DELETE FROM sfb_offerings WHERE title IN (
    'Starter Workshop',
    'Gift Certificate — Starter Workshop',
    'Edu Purchase Home Gift'
)");

try {
    bakery_sfb_create_offering($db, 'Starter Workshop', 80.00, 'class', 'Hands-on starter workshop.');
} catch (Throwable $e) {
    // May already exist from 067 seed.
}
try {
    bakery_sfb_create_offering(
        $db,
        'Gift Certificate — Starter Workshop',
        80.00,
        'gift',
        'Gift for one Starter Workshop seat.'
    );
} catch (Throwable $e) {
    // May already exist from 071 seed.
}

$quote = bakery_sfb_private_workshop_quote([
    'workshop_type' => 'starter',
    'headcount' => 4,
    'bites' => 1,
    'drinks' => 1,
]);
$assert((int)$quote['price_cents'] === (8000 + 2000 + 2000) * 4, 'starter workshop 4p + bites + drinks = $480');

$pizza = bakery_sfb_private_workshop_quote([
    'workshop_type' => 'pizza',
    'headcount' => 2,
]);
$assert((int)$pizza['price_cents'] === 20000, 'pizza upgrade 2p = $200');

try {
    bakery_sfb_private_workshop_quote(['workshop_type' => 'starter', 'headcount' => 0]);
    $assert(false, 'headcount 0 rejected');
} catch (InvalidArgumentException $e) {
    $assert(true, 'headcount 0 rejected');
}

$ins = $db->prepare(
    'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
     VALUES (?, ?, ?, 1, 1, 1)'
);
$ins->execute(['SFB Purchase Home Buyer', '555-0711', '71 Purchase Way']);
$buyerId = (int)$db->lastInsertId();
$ins->execute(['SFB Gift Redeemer', '555-0712', '72 Gift Way']);
$redeemerId = (int)$db->lastInsertId();
$assert($buyerId > 0 && $redeemerId > 0, 'fixture customers created');

$GLOBALS['bakery_sfb_payments_disabled'] = true;

$ws = bakery_sfb_buy_private_workshop($db, $buyerId, [
    'workshop_type' => 'starter',
    'headcount' => 3,
    'bites' => 1,
    'drinks' => 0,
    'contact_name' => 'SFB Purchase Home Buyer',
    'preferred_date' => 'Next Saturday',
    'notes' => 'Allergy: none',
]);
$assert((int)$ws['purchase_id'] > 0 && (int)$ws['booking_id'] > 0, 'private workshop intent recorded');
$booking = bakery_sfb_private_workshop_for_purchase($db, (int)$ws['purchase_id']);
$assert($booking && (int)$booking['headcount'] === 3 && (int)$booking['bites'] === 1, 'workshop booking snapshot stored');
$purchase = bakery_sfb_purchase($db, (int)$ws['purchase_id']);
$assert($purchase && (int)$purchase['price_cents_snapshot'] === 30000, 'workshop price snapshot $300');

$giftBuy = bakery_sfb_buy_gift_certificate($db, $buyerId, ['recipient_name' => 'Friend']);
$assert((int)$giftBuy['purchase_id'] > 0 && (int)$giftBuy['gift_id'] > 0, 'gift certificate intent recorded');
$giftRow = $db->prepare('SELECT * FROM sfb_gift_certificates WHERE id = ?');
$giftRow->execute([(int)$giftBuy['gift_id']]);
$gift = $giftRow->fetch();
$assert($gift && (string)$gift['status'] === 'pending', 'gift starts pending until paid');
$code = (string)$gift['code'];

$assert(bakery_sfb_set_purchase_status($db, (int)$giftBuy['purchase_id'], 'paid', null, '', null, 'square'), 'gift purchase marked paid');
$giftAfter = bakery_sfb_gift_certificate_by_code($db, $code);
$assert($giftAfter && (string)$giftAfter['status'] === 'available', 'paid gift becomes available');

$redeemedId = bakery_sfb_redeem_gift_certificate($db, $redeemerId, $code);
$redeemed = bakery_sfb_purchase($db, $redeemedId);
$assert(
    $redeemed
    && (string)$redeemed['status'] === 'paid'
    && (string)$redeemed['paid_with'] === 'gift'
    && (string)$redeemed['offering_title_snapshot'] === 'Starter Workshop',
    'gift redeems for Starter Workshop'
);
$giftUsed = bakery_sfb_gift_certificate_by_code($db, $code);
$assert($giftUsed && (string)$giftUsed['status'] === 'redeemed', 'gift marked redeemed');

try {
    bakery_sfb_redeem_gift_certificate($db, $redeemerId, $code);
    $assert(false, 'second redeem rejected');
} catch (InvalidArgumentException $e) {
    $assert(true, 'second redeem rejected');
}

unset($GLOBALS['bakery_sfb_payments_disabled']);

$db->prepare('DELETE FROM customers WHERE id IN (?, ?)')->execute([$buyerId, $redeemerId]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
