<?php
/** Verify the phone + PIN customer account flow against the local database. */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/customer_portal.php';

if (!IS_LOCAL) { fwrite(STDERR, "Refusing: tests must run locally\n"); exit(1); }
$db = check_mysql_connection();
bakery_assert_local_test_target($db);
$results = [];
function phone_pin_test(bool $ok, string $message): void { global $results; $results[] = [$ok, $message]; }

$suffix = (string)random_int(2000000000, 8999999999);
$phone = substr($suffix, 0, 10);
$pin = '4826';
$createdId = 0;

try {
    $created = bakery_portal_sign_in_or_register($db, '+1 (' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6), $pin);
    phone_pin_test(!empty($created['success']) && !empty($created['first_batch']), 'new phone creates an account and starts the first-batch flow');
    $createdId = (int)($created['customer']['id'] ?? 0);
    $row = $db->prepare('SELECT name, phone, portal_phone_key, portal_code, portal_code_hash, portal_enabled, sf_baker_enabled FROM customers WHERE id = ?');
    $row->execute([$createdId]);
    $account = $row->fetch(PDO::FETCH_ASSOC) ?: [];
    phone_pin_test($createdId > 0 && $account['portal_phone_key'] === $phone, 'account keeps a normalized phone key');
    phone_pin_test(($account['portal_code'] ?? null) === $pin && password_verify($pin, (string)($account['portal_code_hash'] ?? '')), 'new account keeps its unique login code and a verification hash');
    phone_pin_test((int)($account['portal_enabled'] ?? 0) === 1, 'portal access is enabled');
    phone_pin_test(!array_key_exists('sf_baker_enabled', $account) || (int)$account['sf_baker_enabled'] === 1, 'baking journal access is enabled');

    bakery_portal_logout();
    $returning = bakery_portal_login_by_code($db, $pin);
    phone_pin_test($returning && bakery_portal_customer_id() === $createdId, 'returning customer signs in with only the 4-digit code');
    bakery_portal_logout();
    $wrong = bakery_portal_login_by_code($db, '0000');
    phone_pin_test(empty($wrong['success']), 'wrong PIN is refused');
    $duplicate = bakery_portal_sign_in_or_register($db, '555-555-5555', $pin);
    phone_pin_test(empty($duplicate['success']), 'a second account cannot reuse an existing 4-digit code');
} finally {
    bakery_portal_logout();
    if ($createdId > 0) $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$createdId]);
}

$pass = 0; $fail = 0;
foreach ($results as [$ok, $message]) { echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n"; $ok ? $pass++ : $fail++; }
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
