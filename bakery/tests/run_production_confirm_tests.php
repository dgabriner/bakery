<?php
/**
 * Bake-sheet production confirm: additive batches, stale re-entry rejected.
 * Usage: php tests/run_production_confirm_tests.php
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

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

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

$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if ($productId <= 0) {
    fwrite(STDERR, "Need at least one product on bakerysf_test\n");
    exit(1);
}

$date = date('Y-m-d', strtotime('+46 days'));
echo "Test date: $date product $productId\n";

$cleanup = static function () use ($db, $date, $productId): void {
    if (table_exists($db, 'inventory_movements')) {
        $db->prepare('DELETE FROM inventory_movements WHERE delivery_date=? AND product_id=?')->execute([$date, $productId]);
    }
    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date=? AND product_id=?')->execute([$date, $productId]);
};

$producedOf = static function () use ($db, $date, $productId): int {
    $stmt = $db->prepare('SELECT produced_quantity FROM product_inventory_days WHERE delivery_date=? AND product_id=?');
    $stmt->execute([$date, $productId]);
    return (int)$stmt->fetchColumn();
};
$availableOf = static function () use ($db, $date, $productId): int {
    $stmt = $db->prepare('SELECT available_quantity FROM product_inventory_days WHERE delivery_date=? AND product_id=?');
    $stmt->execute([$date, $productId]);
    return (int)$stmt->fetchColumn();
};

$cleanup();

try {
    $page = file_get_contents($root . DIRECTORY_SEPARATOR . 'production.php');
    $assert(is_string($page) && strpos($page, 'produced_was[') !== false, 'bake sheet posts expected produced');
    $assert(is_string($page) && strpos($page, 'value="<?php echo (int)$product[\'remaining_quantity\']; ?>"') === false, 'Record-now no longer prefills remaining');

    bakery_inventory_record_production($db, $date, $productId, 4, 'confirm test first', 0);
    $assert($producedOf() === 4, 'first batch of 4 is recorded');

    $stale = false;
    try {
        bakery_inventory_record_production($db, $date, $productId, 4, 'stale resubmit', 0);
    } catch (RuntimeException $e) {
        $stale = $e->getMessage() === 'stale_production_count';
    }
    $assert($stale, 'stale expected=0 is rejected after 4 already made');
    $assert($producedOf() === 4, 'stale resubmit did not double-count');

    bakery_inventory_record_production($db, $date, $productId, 3, 'second tray', 4);
    $assert($producedOf() === 7, 'fresh second batch adds 3 on top of 4');

    bakery_inventory_record_production($db, $date, $productId, 8, 'batch with waste', 7, 2);
    $assert($producedOf() === 15, 'only eight sellable units advance Made');
    $assert($availableOf() === 15, 'waste never enters available finished goods');
    $wasteStmt = $db->prepare("SELECT COALESCE(SUM(quantity_delta),0) FROM inventory_movements WHERE delivery_date=? AND product_id=? AND movement_type='waste'");
    $wasteStmt->execute([$date, $productId]);
    $assert((int)$wasteStmt->fetchColumn() === -2, 'production waste is recorded on the immutable ledger');
} catch (Throwable $e) {
    echo 'FAIL  ' . $e->getMessage() . "\n";
    $fail++;
} finally {
    $cleanup();
}

echo $fail === 0 ? "\n$pass passed, 0 failed\n" : "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
