<?php
/**
 * Correct MADE sets an exact count, lower or higher, with a reason and an audit row.
 * Usage: php tests/run_correct_made_tests.php
 * Database: bakerysf_test only.
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
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/i18n.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = 1;
$_SESSION['user_role_slug'] = 'baker';

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

if (!bakery_inventory_ready($db)) {
    fwrite(STDERR, "Finished-goods inventory is not installed on bakerysf_test\n");
    exit(1);
}
if (!bakery_audit_ready($db)) {
    fwrite(STDERR, "field_audit is not installed on bakerysf_test\n");
    exit(1);
}

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if ($productId <= 0) {
    fwrite(STDERR, "Need at least one product on bakerysf_test\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+61 days'));
$cleanup = static function () use ($db, $date, $productId): void {
    $dayId = (int)$db->query(
        'SELECT id FROM product_inventory_days WHERE delivery_date = ' . $db->quote($date) . ' AND product_id = ' . $productId
    )->fetchColumn();
    if ($dayId > 0 && table_exists($db, 'field_audit')) {
        $db->prepare("DELETE FROM field_audit WHERE entity_type = 'product_inventory_day' AND entity_id = ?")
            ->execute([$dayId]);
    }
    if (table_exists($db, 'inventory_movements')) {
        $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ? AND product_id = ?')
            ->execute([$date, $productId]);
    }
    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?')
        ->execute([$date, $productId]);
};

$counts = static function () use ($db, $date, $productId): array {
    $stmt = $db->prepare(
        'SELECT id, produced_quantity, available_quantity, loaded_quantity
         FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?'
    );
    $stmt->execute([$date, $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['id' => 0, 'produced_quantity' => 0, 'available_quantity' => 0, 'loaded_quantity' => 0];
};

$auditFor = static function (int $dayId) use ($db): array {
    $stmt = $db->prepare(
        "SELECT actor_user_id, action, field, old_value, new_value, created_at
         FROM field_audit
         WHERE entity_type = 'product_inventory_day' AND entity_id = ?
         ORDER BY id"
    );
    $stmt->execute([$dayId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
};

$page = (string)file_get_contents($root . '/production.php');
$postAt = strpos($page, "=== 'correct_made'");
$callAt = strpos($page, 'bakery_inventory_correct_made(');
$assert($postAt !== false && strpos($page, "REQUEST_METHOD'] === 'POST'") !== false, 'Correct MADE is a POST action');
$assert($callAt !== false && $postAt !== false && $callAt > $postAt, 'the inventory write sits inside the POST handler');
$assert(substr_count($page, 'bakery_inventory_correct_made(') === 1, 'production.php calls correct made once');
$assert(strpos($page, "bakery_require_role(['baker', 'manager', 'administrator'])") !== false, 'Correct MADE keeps the production roles');
$assert(strpos($page, 'name="csrf_token"') !== false, 'Correct MADE posts the existing CSRF token');
$assert(strpos($page, 'method="post" action="production.php" class="bp-correct-form"') !== false, 'Correct MADE form is POST');
$assert(strpos($page, 'min-height: 44px') !== false && strpos($page, '.bp-correct__toggle') !== false, 'Correct MADE toggle is a 44px target');
$assert(strpos($page, '.bp-correct__input { display: block; width: 100%; box-sizing: border-box; min-height: 44px;') !== false, 'Correct MADE inputs are 44px');
$assert(strpos($page, '.bp-correct__save { display: inline-flex; align-items: center; justify-content: center; min-height: 44px;') !== false, 'Correct MADE save button is 44px');
$assert(!is_file($root . '/database/schema/118_correct_made.sql') && !is_file($root . '/database/schema/119_correct_made.sql'), 'existing field_audit holds the trail, no new migration');

$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
foreach ([
    'production.correct_made',
    'production.correct_made_lead',
    'production.correct_made_save',
    'production.correct_made_reason',
    'production.correct_made_notice',
    'production.correct_made_below_shelf',
] as $key) {
    $assert(isset($en[$key]) && $en[$key] !== '', "en has $key");
    $assert(isset($es[$key]) && $es[$key] !== '', "es has $key");
}

$cleanup();
$db->prepare(
    'INSERT INTO product_inventory_days (delivery_date, product_id, available_quantity, produced_quantity, loaded_quantity)
     VALUES (?, ?, 12, 12, 0)'
)->execute([$date, $productId]);

$lowered = bakery_inventory_correct_made($db, $date, $productId, 9, 'over counted tray', 12);
$afterLower = $counts();
$assert((int)$lowered['old_produced'] === 12 && (int)$lowered['new_produced'] === 9, 'lower records old and new MADE');
$assert((int)$afterLower['produced_quantity'] === 9, 'MADE can be set lower');
$assert((int)$afterLower['available_quantity'] === 9, 'shelf stock drops with MADE');
$audit = $auditFor((int)$afterLower['id']);
$assert(count($audit) === 2, 'audit stores the count and the reason');
$assert((int)$audit[0]['actor_user_id'] === 1 && $audit[0]['field'] === 'produced_quantity', 'audit names who changed MADE');
$assert($audit[0]['old_value'] === '12' && $audit[0]['new_value'] === '9', 'audit stores old and new MADE');
$assert($audit[0]['created_at'] !== null && $audit[0]['created_at'] !== '', 'audit stores when');
$assert($audit[1]['field'] === 'reason' && $audit[1]['new_value'] === 'over counted tray', 'audit stores the reason');
$move = $db->prepare(
    'SELECT quantity_delta, notes, created_by_user_id FROM inventory_movements
     WHERE delivery_date = ? AND product_id = ? ORDER BY id DESC LIMIT 1'
);
$move->execute([$date, $productId]);
$movement = $move->fetch(PDO::FETCH_ASSOC);
$assert((int)$movement['quantity_delta'] === -3, 'movement delta is the MADE change');
$assert(strpos((string)$movement['notes'], 'over counted tray') !== false, 'movement note keeps the reason');
$assert(strpos((string)$movement['notes'], 'from 12 to 9') !== false, 'movement note keeps old and new');
$assert((int)$movement['created_by_user_id'] === 1, 'movement records who');

$raised = bakery_inventory_correct_made($db, $date, $productId, 14, 'found another tray', 9);
$afterRaise = $counts();
$assert((int)$raised['new_produced'] === 14 && (int)$afterRaise['produced_quantity'] === 14, 'MADE can be set higher');
$assert((int)$afterRaise['available_quantity'] === 14, 'shelf stock rises with MADE');

$beforeSame = $counts();
$sameThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, 14, 'same number again', 14);
} catch (InvalidArgumentException $e) {
    $sameThrew = $e->getMessage() === 'correct_made_unchanged';
}
$assert($sameThrew, 'the same MADE number is refused');
$assert((int)$counts()['produced_quantity'] === (int)$beforeSame['produced_quantity'], 'a refused same-number correction writes nothing');

$shortThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, 10, 'no', 14);
} catch (InvalidArgumentException $e) {
    $shortThrew = $e->getMessage() === 'correct_made_reason';
}
$assert($shortThrew, 'a reason shorter than 3 characters is refused');
$assert((int)$counts()['produced_quantity'] === 14, 'a refused reason does not change MADE');

$blankThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, 10, '   ', 14);
} catch (InvalidArgumentException $e) {
    $blankThrew = $e->getMessage() === 'correct_made_reason';
}
$assert($blankThrew, 'a blank reason is refused');

$negativeThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, -1, 'negative count', 14);
} catch (InvalidArgumentException $e) {
    $negativeThrew = $e->getMessage() === 'correct_made_negative';
}
$assert($negativeThrew, 'a negative MADE is refused');

$staleThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, 8, 'stale page', 12);
} catch (RuntimeException $e) {
    $staleThrew = $e->getMessage() === 'stale_production_count';
}
$assert($staleThrew, 'a stale expected MADE is refused');
$assert((int)$counts()['produced_quantity'] === 14, 'a stale correction does not change MADE');

$db->prepare(
    'UPDATE product_inventory_days SET available_quantity = 3, produced_quantity = 11, loaded_quantity = 8
     WHERE delivery_date = ? AND product_id = ?'
)->execute([$date, $productId]);
$belowThrew = false;
try {
    bakery_inventory_correct_made($db, $date, $productId, 2, 'too low for the van', 11);
} catch (InvalidArgumentException $e) {
    $belowThrew = $e->getMessage() === 'correct_made_below_shelf';
}
$still = $counts();
$assert($belowThrew, 'MADE cannot drop below pieces that already left the shelf');
$assert((int)$still['produced_quantity'] === 11 && (int)$still['available_quantity'] === 3, 'a refused drop leaves the counts alone');

$onVan = bakery_inventory_correct_made($db, $date, $productId, 8, 'van already has eight', 11);
$afterVan = $counts();
$assert((int)$onVan['new_produced'] === 8 && (int)$afterVan['available_quantity'] === 0, 'MADE can meet the loaded count');
$assert((int)$afterVan['loaded_quantity'] === 8, 'Correct MADE does not change the van load');

$cleanup();

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
