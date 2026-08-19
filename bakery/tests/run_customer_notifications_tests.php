<?php
/**
 * Customer notification center — characterization tests.
 *
 * Run: php tests/run_customer_notifications_tests.php
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
require_once $root . '/includes/customer_notifications.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
bakery_customer_notifications_ensure_schema($db);

$pass = 0;
$fail = 0;

function notify_test_assert($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
}

$customerStmt = $db->query(
    "SELECT id, name FROM customers WHERE portal_enabled = 1 AND is_active = 1 LIMIT 1"
);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
notify_test_assert((bool)$customer, 'portal-enabled customer exists');
if (!$customer) {
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

$customerId = (int)$customer['id'];

// Clean test notifications for this customer.
if (table_exists($db, 'customer_notifications')) {
    $db->prepare('DELETE FROM customer_notifications WHERE customer_id = ? AND dedupe_key LIKE ?')
        ->execute([$customerId, 'test:%']);
}

$id1 = bakery_customer_notify($db, $customerId, BAKERY_CN_ORDER_DAILY_CHANGED, 'Test title', 'Test message', [
    'dedupe_key' => 'test:notify:1',
    'link_url' => 'customer_portal.php',
]);
notify_test_assert($id1 !== null && $id1 > 0, 'creates notification');

$idDup = bakery_customer_notify($db, $customerId, BAKERY_CN_ORDER_DAILY_CHANGED, 'Dup', 'Dup', [
    'dedupe_key' => 'test:notify:1',
]);
notify_test_assert($idDup === null, 'deduplicates identical dedupe_key');

$unread = bakery_customer_notifications_unread_count($db, $customerId);
notify_test_assert($unread >= 1, 'unread count includes new notification');

notify_test_assert(
    bakery_customer_notification_mark_read($db, $customerId, (int)$id1),
    'mark single notification read'
);

$unreadAfter = bakery_customer_notifications_unread_count($db, $customerId);
notify_test_assert($unreadAfter === $unread - 1, 'unread count decreases after mark read');

$prefs = bakery_customer_notification_save_preferences($db, $customerId, [
    'delivery_email' => true,
]);
notify_test_assert(!empty($prefs['delivery_email']), 'saves notification preferences');

$list = bakery_customer_notifications_list($db, $customerId, 5, 0);
notify_test_assert(is_array($list) && count($list) >= 1, 'lists notifications for customer');

// Security: another customer cannot read/mark.
$otherStmt = $db->query(
    "SELECT id FROM customers WHERE id != {$customerId} AND is_active = 1 LIMIT 1"
);
$otherId = (int)$otherStmt->fetchColumn();
if ($otherId > 0) {
    notify_test_assert(
        !bakery_customer_notification_mark_read($db, $otherId, (int)$id1),
        'other customer cannot mark foreign notification'
    );
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
