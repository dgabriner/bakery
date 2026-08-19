<?php
/** Customer QR login integration tests. Run against the local database only. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/customer_qr_login.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
$results = [];
function qr_test(bool $condition, string $message): void {
    global $results;
    $results[] = [$condition, $message];
}

$customer = $db->query('SELECT id, name, portal_code, portal_enabled FROM customers WHERE is_active = 1 ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    fwrite(STDERR, "No active customer found.\n");
    exit(1);
}

$customerId = (int)$customer['id'];
$originalCode = $customer['portal_code'];
$originalEnabled = (int)$customer['portal_enabled'];
$createdInviteIds = [];

try {
    $db->prepare('UPDATE customers SET portal_code = NULL, portal_enabled = 0 WHERE id = ?')->execute([$customerId]);
    $invite = bakery_customer_qr_create_invite($db, $customerId, []);
    $inviteRow = bakery_customer_qr_find_invite($db, $invite['token']);
    qr_test(strlen($invite['token']) === 48, 'generated invitation uses a strong random token');
    qr_test($inviteRow && (int)$inviteRow['customer_id'] === $customerId, 'generated invitation resolves to its customer');

    $stored = $db->prepare('SELECT id, token_hash FROM customer_qr_login_invites WHERE customer_id = ? ORDER BY id DESC LIMIT 1');
    $stored->execute([$customerId]);
    $storedRow = $stored->fetch(PDO::FETCH_ASSOC);
    $createdInviteIds[] = (int)$storedRow['id'];
    qr_test($storedRow['token_hash'] !== $invite['token'] && $storedRow['token_hash'] === hash('sha256', $invite['token']), 'database stores only the token digest');

    $code = '';
    for ($candidate = 6200; $candidate <= 6299; $candidate++) {
        $candidateCode = (string)$candidate;
        if (bakery_customer_portal_code_available($db, $customerId, $candidateCode)) {
            $code = $candidateCode;
            break;
        }
    }
    if ($code === '') throw new RuntimeException('No available test code.');

    $mismatch = bakery_customer_qr_complete($db, $invite['token'], $code, '9999');
    qr_test(empty($mismatch['success']), 'new-code flow rejects a mismatched confirmation');
    qr_test((bool)bakery_customer_qr_find_invite($db, $invite['token']), 'failed attempt does not consume invitation');

    $created = bakery_customer_qr_complete($db, $invite['token'], $code, $code);
    qr_test(!empty($created['success']), 'first scan creates the code and signs in');
    qr_test(!bakery_customer_qr_find_invite($db, $invite['token']), 'successful login consumes invitation');

    bakery_portal_logout();
    $returnInvite = bakery_customer_qr_create_invite($db, $customerId, []);
    $stored->execute([$customerId]);
    $createdInviteIds[] = (int)$stored->fetch(PDO::FETCH_ASSOC)['id'];
    $wrong = bakery_customer_qr_complete($db, $returnInvite['token'], '0000');
    qr_test(empty($wrong['success']), 'returning-customer flow rejects the wrong code');
    $returning = bakery_customer_qr_complete($db, $returnInvite['token'], $code);
    qr_test(!empty($returning['success']), 'returning customer can use the existing code');
} finally {
    bakery_portal_logout();
    if ($createdInviteIds) {
        $placeholders = implode(',', array_fill(0, count($createdInviteIds), '?'));
        $db->prepare("DELETE FROM customer_qr_login_invites WHERE id IN ($placeholders)")->execute($createdInviteIds);
    }
    $db->prepare('UPDATE customers SET portal_code = ?, portal_enabled = ? WHERE id = ?')->execute([$originalCode, $originalEnabled, $customerId]);
}

$pass = 0;
$fail = 0;
foreach ($results as [$ok, $message]) {
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $message . "\n";
    $ok ? $pass++ : $fail++;
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
