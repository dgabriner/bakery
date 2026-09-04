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
$assert(strpos($packSrc, 'match_supposed') !== false && strpos($packSrc, 'set_on_hand') !== false, 'Pack List has packer count actions');
$assert(strpos($packSrc, 'seed_driver_loads') !== false && strpos($packSrc, 'pack-count-board') !== false, 'Pack List has driver-load seed board');
$assert(strpos($packSrc, 'bakery_kitchen_segments_render') !== false, 'Pack List renders kitchen segments for bakers');
$assert(strpos($packSrc, 'pack-page--baker') !== false, 'Pack List marks baker phone mode');
$assert(strpos($packSrc, 'bakery_pack_phone_focus_nav_html') !== false, 'Pack List renders phone focus chrome');
$assert(function_exists('bakery_pack_phone_focus_keys'), 'phone focus keys helper exists');
$assert(bakery_pack_phone_focus_keys('product', [['product_id' => 9], ['product_id' => 3]], [], []) === [9, 3], 'product focus keys');
$assert(bakery_pack_phone_focus_keys('route', [], [], [['driver_id' => 0], ['driver_id' => 4]]) === [0, 4], 'route focus keys include unassigned');
$focus = bakery_pack_phone_focus_state([9, 3, 7], 3);
$assert($focus['current'] === 3 && $focus['prev'] === 9 && $focus['next'] === 7, 'phone focus prev/next around middle');
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
        $counted = bakery_inventory_set_finished_on_hand($db, $invDate, $productA, 18, 'pack list count test');
        $row->execute([$invDate, $productA]);
        $afterCount = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $assert((int)$counted['on_hand'] === 18, 'count helper returns on-hand 18');
        $assert((int)$afterCount['available_quantity'] === 18, 'packer count sets warehouse available');
        $assert((int)$afterCount['produced_quantity'] >= 18, 'packer count keeps produced at least on hand');

        $matched = bakery_inventory_match_supposed_production($db, $invDate, $productA, 30, 'pack list match test');
        $row->execute([$invDate, $productA]);
        $afterMatch = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $assert(!empty($matched['changed']) && (int)$matched['produced_now'] === 30, 'match supposed raises produced to 30');
        $assert((int)$afterMatch['available_quantity'] === 30, 'match supposed raises free FG to supposed');

        $driverId = (int)$db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchColumn();
        $customerId = (int)$db->query('SELECT id FROM customers WHERE COALESCE(is_active,1)=1 ORDER BY id LIMIT 1')->fetchColumn();
        if ($driverId > 0 && $customerId > 0) {
            $seedDate = date('Y-m-d', strtotime('+55 days'));
            $orderIds = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
            $orderIds->execute([$seedDate]);
            $ids = array_map('intval', $orderIds->fetchAll(PDO::FETCH_COLUMN));
            if ($ids) {
                $in = implode(',', $ids);
                $db->exec("DELETE FROM daily_order_assignments WHERE daily_order_id IN ({$in})");
                $db->exec("DELETE FROM daily_order_items WHERE daily_order_id IN ({$in})");
                $db->exec("DELETE FROM daily_orders WHERE id IN ({$in})");
            }
            $loadIds = $db->prepare('SELECT id FROM driver_loads WHERE delivery_date = ?');
            $loadIds->execute([$seedDate]);
            foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
                $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
            }
            $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$seedDate]);
            $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$seedDate]);
            $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?')->execute([$seedDate, $productA]);

            $db->prepare(
                "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
            )->execute([$customerId, $seedDate]);
            $orderId = (int)$db->lastInsertId();
            $db->prepare(
                'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, 20, 0, 0)'
            )->execute([$orderId, $productA]);
            $db->prepare(
                "INSERT INTO daily_order_assignments
                 (daily_order_id, driver_id, delivery_date, route_order, scheduled_delivery_time, delivery_status)
                 VALUES (?, ?, ?, 1, '08:00:00', 'pending')"
            )->execute([$orderId, $driverId, $seedDate]);

            bakery_inventory_set_finished_on_hand($db, $seedDate, $productA, 12, 'seed prep short bake');
            $seed = bakery_inventory_seed_driver_loads_from_supposed($db, $seedDate, 'pack list seed test');
            $loadQty = $db->prepare(
                'SELECT li.loaded_quantity
                 FROM driver_loads dl
                 JOIN driver_load_items li ON li.driver_load_id = dl.id
                 WHERE dl.delivery_date = ? AND dl.driver_id = ? AND li.product_id = ?'
            );
            $loadQty->execute([$seedDate, $driverId, $productA]);
            $seeded = (int)$loadQty->fetchColumn();
            $assert((int)$seed['drivers'] === 1, 'seed touches one driver');
            $assert($seeded === 12, 'seed caps driver load at on-hand 12 when supposed is 20');
            $assert((int)$seed['short_units'] === 8, 'seed reports 8 units still short');

            $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$orderId]);
            $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id = ?')->execute([$orderId]);
            $db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);
            $loadIds->execute([$seedDate]);
            foreach ($loadIds->fetchAll(PDO::FETCH_COLUMN) as $loadId) {
                $db->prepare('DELETE FROM driver_load_items WHERE driver_load_id = ?')->execute([(int)$loadId]);
            }
            $db->prepare('DELETE FROM driver_loads WHERE delivery_date = ?')->execute([$seedDate]);
            $db->prepare('DELETE FROM inventory_movements WHERE delivery_date = ?')->execute([$seedDate]);
            $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?')->execute([$seedDate, $productA]);
        } else {
            echo "SKIP seed loads — no driver/customer on bakerysf_test\n";
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
