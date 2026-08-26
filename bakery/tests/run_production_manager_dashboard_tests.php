<?php
/**
 * Production Manager Dashboard — pure helpers + file surface.
 *
 * Pure math tests always run. DB board build runs only when bakerysf_test is available.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
$root = dirname(__DIR__);
require_once $root . '/includes/production_manager_dashboard.php';

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

$assert(is_file($root . '/production_manager.php'), 'production_manager.php exists');
$assert(is_file($root . '/css/production_manager.css'), 'production_manager.css exists');
$assert(is_file($root . '/includes/production_manager_dashboard.php'), 'dashboard include exists');

$assert(bakery_pmd_resolve_date('2026-08-27') === '2026-08-27', 'resolve valid date');
$assert(bakery_pmd_resolve_date('nope') === date('Y-m-d', strtotime('+1 day')), 'resolve invalid → tomorrow');

$wt = bakery_pmd_format_dough_weight(79200);
$assert($wt['grams'] === 79200, 'dough weight grams');
$assert(abs($wt['lb'] - 174.6) < 0.05, 'dough weight lb ~174.6');
$assert(strpos($wt['label'], 'lb') !== false, 'dough weight label has lb');
$assert(bakery_pmd_format_dough_weight(0)['label'] === '', 'zero dough weight empty label');

$assert(bakery_pmd_fmt_qty(1.50) === '1.5', 'fmt trims trailing zero');
$assert(bakery_pmd_fmt_qty(2.00) === '2', 'fmt trims .00');

$batchEmpty = bakery_pmd_dough_batch(null, 100);
$assert($batchEmpty['unit'] === 'piece', 'no yield → piece unit');
$assert($batchEmpty['label'] === '100 pcs', 'no yield → pcs label');

$batchTray = bakery_pmd_dough_batch([
    'pieces_per_tray' => 20,
    'trays_per_gallon' => null,
], 45);
$assert($batchTray['trays'] === 2, '45 pcs / 20 → 2 trays');
$assert($batchTray['tray_remainder'] === 5, '45 pcs / 20 → 5 remainder');
$assert(strpos($batchTray['label'], '2 trays') !== false, 'tray label includes trays');

$batchGal = bakery_pmd_dough_batch([
    'pieces_per_tray' => 20,
    'trays_per_gallon' => 14.666667,
], 880);
$assert($batchGal['unit'] === 'gallon', 'gal+tray yield → gallon unit');
$assert($batchGal['gallons'] !== null && abs((float)$batchGal['gallons'] - 3.0) < 0.02, '880 pcs ≈ 3 gal');
$assert(strpos($batchGal['label'], 'gal') !== false, 'gallon label includes gal');

$std = bakery_pmd_standard_batches(10000, 25000);
$assert($std['batches'] === 2.5, 'standard batches 2.5');
$assert($std['batches_ceil'] === 3, 'standard batches ceil 3');
$assert($std['label'] !== '', 'standard batches label');
$assert(bakery_pmd_standard_batches(null, 1000)['label'] === '', 'no standard batch → empty');

// Optional live board build against bakerysf_test when configured.
$ranBoard = false;
$envPath = $root . '/.env';
if (is_readable($envPath)) {
    try {
        require_once $root . '/tests/isolate_test_db.php';
        require_once $root . '/includes/config.php';
        require_once $root . '/includes/database.php';
        require_once $root . '/includes/test_target_guard.php';
        if (defined('IS_LOCAL') && IS_LOCAL) {
            $db = check_mysql_connection();
            bakery_assert_local_test_target($db);
            $date = date('Y-m-d', strtotime('+1 day'));
            $board = bakery_pmd_build($db, $date);
            $assert(isset($board['doughs'], $board['summary'], $board['links']), 'board has core keys');
            $assert($board['date'] === $date, 'board date matches');
            $assert(is_array($board['doughs']), 'doughs is list');
            $assert(isset($board['summary']['pieces']), 'summary pieces');
            $assert(strpos($board['links']['production_center'], 'production_center.php') !== false, 'center link');
            $ranBoard = true;
        }
    } catch (Throwable $e) {
        echo "SKIP  board build (" . $e->getMessage() . ")\n";
    }
}
if (!$ranBoard) {
    echo "SKIP  board build (no local bakerysf_test)\n";
}

// Auth surface: bakers must not get this page via baker scripts allow-list.
require_once $root . '/includes/auth.php';
$assert(!in_array('production_manager.php', bakery_baker_scripts(), true), 'baker scripts exclude production manager dashboard');

// Nav catalog registers the page for managers.
require_once $root . '/includes/navigation_catalog.php';
$found = false;
foreach (bakery_navigation_catalog() as $section) {
    foreach ($section['items'] ?? [] as $item) {
        if (($item['href'] ?? '') === 'production_manager.php') {
            $found = true;
            $roles = $item['roles'] ?? [];
            $assert(in_array('manager', $roles, true) || in_array('administrator', $roles, true), 'nav roles include manager/admin');
            $assert(!in_array('baker', $roles, true), 'nav roles exclude baker');
        }
    }
}
$assert($found, 'navigation catalog lists production_manager.php');

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
