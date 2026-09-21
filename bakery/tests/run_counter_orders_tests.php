<?php
/**
 * Counter pickup slips (bakerysf_test only).
 * Usage: php tests/run_counter_orders_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';
putenv('APP_ENV=local');
$_ENV['APP_ENV'] = 'local';
$_SERVER['APP_ENV'] = 'local';

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/counter_orders.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$passed = 0;
$failed = 0;
function counter_assert($ok, $message) {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function counter_apply_sql_file(PDO $db, $path) {
    $sql = (string)file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false
                && stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS drivers (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
counter_apply_sql_file($db, $root . '/database/schema/002_auth.sql');
counter_apply_sql_file($db, $root . '/database/schema/007_baker_role.sql');
counter_apply_sql_file($db, $root . '/database/schema/074_cashier_shop_photos.sql');
counter_apply_sql_file($db, $root . '/database/schema/082_counter_orders.sql');

function counter_user(PDO $db, string $roleSlug, string $name): int {
    $role = $db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
    $role->execute([$roleSlug]);
    $roleId = (int)$role->fetchColumn();
    $email = 'counter-' . $roleSlug . '-' . bin2hex(random_bytes(3)) . '@example.test';
    $stmt = $db->prepare(
        'INSERT INTO users (email, password_hash, display_name, role_id, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $stmt->execute([$email, password_hash('x', PASSWORD_DEFAULT), $name, $roleId]);
    return (int)$db->lastInsertId();
}

$cashierId = counter_user($db, 'cashier', 'Test Cashier');
$bakerId = counter_user($db, 'baker', 'Test Baker');
$managerId = counter_user($db, 'manager', 'Laura');

counter_assert(in_array('counter_orders.php', bakery_cashier_scripts(), true), 'cashiers can open counter orders');
counter_assert(in_array('counter_orders.php', bakery_baker_scripts(), true), 'bakers can open counter orders');
counter_assert(!in_array('counter_orders.php', bakery_driver_scripts(), true), 'drivers cannot open counter orders');
counter_assert(bakery_counter_order_can_complete('cashier'), 'cashiers can mark done');
counter_assert(bakery_counter_order_can_complete('manager'), 'managers can mark done');
counter_assert(!bakery_counter_order_can_complete('baker'), 'bakers cannot mark done');

$empty = bakery_counter_order_create($db, $cashierId, [
    'pickup_date' => '2026-09-22',
    'pickup_place' => 'capp',
    'pay_status' => 'unpaid',
    'order_text' => '',
]);
counter_assert(empty($empty['ok']) && ($empty['error'] ?? '') === 'need_what', 'order needs words or a photo');

$badEmail = bakery_counter_order_create($db, $cashierId, [
    'pickup_date' => '2026-09-22',
    'pickup_place' => 'capp',
    'pay_status' => 'paid',
    'order_text' => 'one cake',
    'customer_email' => 'not-an-email',
]);
counter_assert(empty($badEmail['ok']) && ($badEmail['error'] ?? '') === 'bad_email', 'bad email is rejected');

$created = bakery_counter_order_create($db, $cashierId, [
    'pickup_date' => '2026-09-22',
    'pickup_place' => 'panaderia',
    'pay_status' => 'other',
    'pay_note' => 'pay at pickup',
    'order_text' => "2 tres leches\n1 dozen conchas",
    'customer_name' => 'Maria',
    'customer_phone' => '415-555-0100',
    'customer_email' => 'maria@example.test',
]);
counter_assert(!empty($created['ok']) && (int)($created['id'] ?? 0) > 0, 'cashier saves a pickup slip');
$orderId = (int)($created['id'] ?? 0);

$row = $db->prepare('SELECT pickup_place, pay_status, pay_note, customer_name, status FROM counter_orders WHERE id = ?');
$row->execute([$orderId]);
$saved = $row->fetch(PDO::FETCH_ASSOC);
counter_assert($saved && $saved['pickup_place'] === 'panaderia', 'place is panaderia');
counter_assert($saved && $saved['pay_status'] === 'other' && $saved['pay_note'] === 'pay at pickup', 'other payment keeps the note');
counter_assert($saved && $saved['customer_name'] === 'Maria' && $saved['status'] === 'pending', 'customer and pending status saved');

$paid = bakery_counter_order_create($db, $bakerId, [
    'pickup_date' => '2026-09-23',
    'pickup_place' => 'delivery',
    'pay_status' => 'paid',
    'pay_note' => 'should be cleared',
    'order_text' => 'one box of conchas',
]);
counter_assert(!empty($paid['ok']), 'baker can take an order');
$paidRow = $db->prepare('SELECT pay_note, pickup_place FROM counter_orders WHERE id = ?');
$paidRow->execute([(int)$paid['id']]);
$paidSaved = $paidRow->fetch(PDO::FETCH_ASSOC);
counter_assert($paidSaved && $paidSaved['pay_note'] === '' && $paidSaved['pickup_place'] === 'delivery', 'paid slips drop the other-note');

$pending = bakery_counter_order_list($db, 'pending', '2026-09-22', 'panaderia');
$ids = array_map(static function ($item) {
    return (int)$item['id'];
}, $pending);
counter_assert(in_array($orderId, $ids, true), 'pending list finds the Panadería slip');

$bakerDone = bakery_counter_order_mark_done($db, $orderId, $bakerId, 'baker');
counter_assert(empty($bakerDone['ok']) && ($bakerDone['error'] ?? '') === 'forbidden_done', 'baker cannot mark done');

$cashierDone = bakery_counter_order_mark_done($db, $orderId, $cashierId, 'cashier');
counter_assert(!empty($cashierDone['ok']), 'cashier marks the slip done');
$again = bakery_counter_order_mark_done($db, $orderId, $managerId, 'manager');
counter_assert(empty($again['ok']) && ($again['error'] ?? '') === 'missing', 'a finished slip cannot be completed twice');

$doneList = bakery_counter_order_list($db, 'done', '2026-09-22');
$doneIds = array_map(static function ($item) {
    return (int)$item['id'];
}, $doneList);
counter_assert(in_array($orderId, $doneIds, true), 'done list includes the finished slip');

$photoPath = '';
if (function_exists('imagejpeg')) {
    $img = imagecreatetruecolor(8, 8);
    $tmp = tempnam(sys_get_temp_dir(), 'co');
    imagejpeg($img, $tmp);
    imagedestroy($img);
    $photoOrder = bakery_counter_order_create($db, $cashierId, [
        'pickup_date' => '2026-09-24',
        'pickup_place' => 'capp',
        'pay_status' => 'unpaid',
        'order_text' => '',
    ], [
        'name' => 'slip.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => $tmp,
        'error' => UPLOAD_ERR_OK,
        'size' => (int)filesize($tmp),
    ], true);
    @unlink($tmp);
    counter_assert(!empty($photoOrder['ok']), 'a photo alone can be the order');
    if (!empty($photoOrder['id'])) {
        $photoStmt = $db->prepare('SELECT photo_path FROM counter_orders WHERE id = ?');
        $photoStmt->execute([(int)$photoOrder['id']]);
        $photoPath = (string)$photoStmt->fetchColumn();
        counter_assert($photoPath !== '' && is_file($root . '/uploads/counter_orders/' . $photoPath), 'photo file is stored');
        bakery_counter_order_unlink($photoPath);
        counter_assert(!is_file($root . '/uploads/counter_orders/' . $photoPath), 'photo file can be removed');
        $db->prepare('DELETE FROM counter_orders WHERE id = ?')->execute([(int)$photoOrder['id']]);
    }
} else {
    counter_assert(true, 'GD missing; photo file test skipped');
    counter_assert(true, 'GD missing; photo cleanup skipped');
}

$page = (string)file_get_contents($root . '/counter_orders.php');
counter_assert(strpos($page, "bakery_require_role(['cashier', 'baker', 'manager', 'administrator'])") !== false, 'page authorizes cashier, baker, manager, and admin');
counter_assert(strpos($page, 'bakery_counter_order_can_complete') !== false, 'done button follows the complete rule');
counter_assert(strpos($page, 'bakery_verify_csrf') !== false, 'posts check CSRF');
counter_assert(strpos($page, 'capture="environment"') !== false, 'photo input opens the camera');

$db->prepare('DELETE FROM counter_orders WHERE id IN (?, ?)')->execute([$orderId, (int)($paid['id'] ?? 0)]);
$db->prepare('DELETE FROM users WHERE id IN (?, ?, ?)')->execute([$cashierId, $bakerId, $managerId]);

echo $failed === 0
    ? "Counter order tests passed ({$passed})\n"
    : "Counter order tests finished with {$failed} failure(s), {$passed} pass(es)\n";
exit($failed === 0 ? 0 : 1);
