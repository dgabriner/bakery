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

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
