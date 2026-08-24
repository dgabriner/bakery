<?php
/**
 * Pack List check-offs: pack-all and line keys.
 * CLI / local bakerysf_test only.
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
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/pack_list.php';
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

$assert(bakery_pack_line_key(12, 34) === 'c12_p34', 'line key format');
$assert(bakery_pack_line_key_valid('c12_p34'), 'valid line key accepted');
$assert(!bakery_pack_line_key_valid('nope'), 'junk line key rejected');

if (!bakery_pack_progress_ready($db)) {
    echo "SKIP pack_progress table missing\n";
    echo "\n$pass passed, $fail failed\n";
    exit($fail > 0 ? 1 : 0);
}

$date = date('Y-m-d', strtotime('+53 days'));
$db->prepare('DELETE FROM pack_progress WHERE pack_date = ?')->execute([$date]);

bakery_pack_set_checked($db, $date, 'c1_p1', true, null);
$one = $db->prepare('SELECT COUNT(*) FROM pack_progress WHERE pack_date = ? AND line_key = ?');
$one->execute([$date, 'c1_p1']);
$assert((int)$one->fetchColumn() === 1, 'toggle on inserts a pack line');

$count = bakery_pack_mark_keys($db, $date, ['c1_p1', 'c2_p9', 'c2_p9', 'bad'], null);
$assert($count >= 2, 'pack-all inserts remaining valid keys');
$keys = $db->prepare('SELECT line_key FROM pack_progress WHERE pack_date = ? ORDER BY line_key');
$keys->execute([$date]);
$found = $keys->fetchAll(PDO::FETCH_COLUMN);
$assert(in_array('c1_p1', $found, true) && in_array('c2_p9', $found, true), 'pack-all keeps both lines');
$assert(!in_array('bad', $found, true), 'pack-all ignores invalid keys');

bakery_pack_set_checked($db, $date, 'c1_p1', false, null);
$one->execute([$date, 'c1_p1']);
$assert((int)$one->fetchColumn() === 0, 'toggle off deletes the pack line');

$db->prepare('DELETE FROM pack_progress WHERE pack_date = ?')->execute([$date]);

$schema065 = $root . '/database/schema/065_product_pack_boxes.sql';
$assert(is_readable($schema065), '065 box conversion migration is present');

$packSrc = (string)file_get_contents($root . '/pack_list.php');
$loadSrc = (string)file_get_contents($root . '/driver_load.php');
$assert(strpos($packSrc, 'backfill_day') !== false && strpos($packSrc, 'mark_day_produced') !== false, 'Pack List has day-batch mark produced');
$assert(strpos($packSrc, 'backfill_production') !== false, 'Pack List has per-product mark produced');
$assert(strpos($loadSrc, 'backfill_day') !== false && strpos($loadSrc, 'mark_produced_qty') !== false, 'Load board can mark missing production');

if (bakery_inventory_ready($db)) {
    $productIds = $db->query('SELECT id FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    $productA = (int)($productIds[0] ?? 0);
    $productB = (int)($productIds[1] ?? $productA);
    if ($productA > 0) {
        $invDate = date('Y-m-d', strtotime('+54 days'));
        $wipeInv = static function () use ($db, $invDate, $productA, $productB): void {
            if (table_exists($db, 'inventory_movements')) {
                $db->prepare('DELETE FROM inventory_movements WHERE delivery_date=? AND product_id IN (?,?)')->execute([$invDate, $productA, $productB]);
            }
            $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date=? AND product_id IN (?,?)')->execute([$invDate, $productA, $productB]);
        };
        $wipeInv();

        $targets = bakery_inventory_missing_production_targets(
            [$productA => 24],
            [$productA => 0],
            [$productA => 24],
            [$productA => 0]
        );
        $assert(($targets[$productA] ?? 0) === 24, 'missing target uses loaded units when produced is 0');
        $assert(bakery_inventory_missing_production_targets(
            [$productA => 24],
            [$productA => 0],
            [$productA => 24],
            [$productA => 24]
        ) === [], 'covered produced quantity is not missing');

        bakery_inventory_ensure_day($db, $invDate, $productA);
        $db->prepare(
            'UPDATE product_inventory_days
             SET available_quantity=0, produced_quantity=0, loaded_quantity=24
             WHERE delivery_date=? AND product_id=?'
        )->execute([$invDate, $productA]);
        $backfill = bakery_inventory_backfill_production($db, $invDate, $productA, 24, 'pack list test after load');
        $row = $db->prepare('SELECT available_quantity, produced_quantity, loaded_quantity FROM product_inventory_days WHERE delivery_date=? AND product_id=?');
        $row->execute([$invDate, $productA]);
        $afterLoad = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $assert(!empty($backfill['changed']) && (int)$backfill['added_produced'] === 24, 'backfill records 24 produced after a load');
        $assert((int)$afterLoad['produced_quantity'] === 24, 'produced is 24 after retroactive mark');
        $assert((int)$afterLoad['available_quantity'] === 0, 'already-loaded units do not re-enter warehouse');
        $assert((int)$afterLoad['loaded_quantity'] === 24, 'loaded quantity stays 24');

        $again = bakery_inventory_backfill_production($db, $invDate, $productA, 24, 'pack list test idempotent');
        $assert(empty($again['changed']), 'second mark is a no-op');

        $wipeInv();
        $fresh = bakery_inventory_backfill_production($db, $invDate, $productA, 24, 'pack list test unused stock');
        $row->execute([$invDate, $productA]);
        $afterFresh = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $assert((int)$fresh['added_available'] === 24, 'unused bake adds warehouse stock');
        $assert((int)$afterFresh['available_quantity'] === 24 && (int)$afterFresh['produced_quantity'] === 24, 'fresh backfill makes 24 available');

        if ($productB !== $productA) {
            $wipeInv();
            $day = bakery_inventory_backfill_day_production($db, $invDate, [$productA => 10, $productB => 6], 'pack list day batch');
            $assert((int)$day['updated'] === 2 && (int)$day['added_produced'] === 16, 'day batch marks both products');
        }

        $wipeInv();
    } else {
        echo "SKIP inventory backfill — no products on bakerysf_test\n";
    }
} else {
    echo "SKIP inventory backfill — finished-goods tables missing\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
