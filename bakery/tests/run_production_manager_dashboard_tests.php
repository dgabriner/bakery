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
$assert(bakery_pmd_resolve_view('week') === 'week', 'resolve week view');
$assert(bakery_pmd_resolve_view('ROUTES') === 'routes', 'resolve routes view case-insensitive');
$assert(bakery_pmd_resolve_view('nope') === 'batches', 'resolve bad view → batches');
$assert(bakery_pmd_resolve_view('') === 'batches', 'resolve empty view → batches');
$assert(bakery_pmd_resolve_source('last_entered') === 'last_entered', 'resolve last_entered source');
$assert(bakery_pmd_resolve_source('BAKE_LIST') === 'bake_list', 'resolve bake_list source case-insensitive');
$assert(bakery_pmd_resolve_source('nope') === 'bake_list', 'resolve bad source → bake_list');
$assert(strpos((string)file_get_contents($root . '/includes/production_manager_print.php'), "'print' => '1'") !== false, 'print query is on the bake sheet link');
$assert(strpos((string)file_get_contents($root . '/production_manager.php'), 'print_sheet_link') !== false, 'production manager offers the bake sheet');
$assert(strpos((string)file_get_contents($root . '/production_manager.php'), "sourceMode = 'last_entered'") === false, 'bare print does not switch to last entered');
$assert(strpos((string)file_get_contents($root . '/css/production_manager.css'), 'size: letter') !== false, 'print CSS uses letter page');

require_once $root . '/includes/i18n.php';
require_once $root . '/includes/production_manager_print.php';
$printLinks = bakery_pmd_print_links('2026-10-17');
$assert(strpos($printLinks['bake_sheet'], 'print=1') !== false, 'bake sheet link prints');
$assert(strpos($printLinks['bake_sheet'], '2026-10-17') !== false, 'bake sheet link keeps the selected date');
$assert(strpos($printLinks['bake_sheet'], 'last_entered') === false, 'bake sheet link does not request last entered');
$assert(strpos($printLinks['draft'], 'source=last_entered') !== false, 'draft link is explicit');
$assert(strpos($printLinks['draft'], 'entered_date=2026-10-17') !== false, 'draft link is the selected date');
$saturdayCover = bakery_pmd_print_route_label('2026-10-17');
$assert(strpos($saturdayCover, 'October 16') !== false, 'Saturday delivery names the Friday bake');
$assert(strpos($saturdayCover, '10/19') !== false, 'Friday pan dulce cover includes Monday');
$assert(strpos($saturdayCover, 'Sour Flour') !== false, 'Saturday cover names Sour Flour');
$mondayCover = bakery_pmd_print_route_label('2026-10-19');
$assert(strpos($mondayCover, 'October 18') !== false, 'Monday Sour Flour is the Sunday bake');
$assert(bakery_pmd_print_title_key(['bake_source' => 'committed_plan', 'committed' => true]) === 'production_manager.print_title_released', 'released sheet title');
$assert(bakery_pmd_print_title_key(['bake_source' => 'demand', 'committed' => false]) === 'production_manager.print_title_uncommitted', 'uncommitted sheet title');
$assert(bakery_pmd_print_title_key(['bake_source' => 'last_entered', 'committed' => false]) === 'production_manager.print_title_draft', 'draft sheet title');
$assert(bakery_pmd_print_footer_key(['bake_source' => 'committed_plan', 'committed' => true]) === 'production_manager.print_footer_released', 'released sheet footer');

$links = bakery_pmd_links('2026-08-27');
$assert(strpos($links['production_center'], 'production_center.php') !== false, 'links include production center');
$assert(strpos($links['daily_orders'], 'daily_orders.php') !== false, 'links include daily orders');
$assert(strpos($links['route_manager'], 'route_manager.php') !== false, 'links include route manager');
$assert(strpos($links['inventory'], 'inventory.php') !== false, 'links include inventory');

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

            $entered = bakery_pmd_latest_entered_plan($db);
            if ($entered !== null) {
                $enteredBoard = bakery_pmd_build($db, $date, ['source' => 'last_entered']);
                $assert($enteredBoard['bake_source'] === 'last_entered', 'last_entered board source');
                $assert((int)$enteredBoard['summary']['pieces'] === (int)$entered['pieces'], 'last_entered pieces match saved plan');
                $assert($enteredBoard['entered_plan']['date'] === $entered['date'], 'last_entered date stamped');
                $assert($enteredBoard['quantity_date'] === $entered['date'], 'last entered quantities keep their own date');
                $assert($enteredBoard['date_mismatch'] === ($entered['date'] !== $date), 'a substituted plan date is visible');
            } else {
                echo "SKIP  last_entered board (no saved production_plan_items)\n";
            }

            $weekLog = tempnam(sys_get_temp_dir(), 'pmd-week-');
            $prevLog = ini_get('error_log');
            ini_set('error_log', $weekLog);
            $week = bakery_pmd_week_orders($db, $date);
            ini_set('error_log', $prevLog);
            $weekLogged = is_string($weekLog) ? (string)file_get_contents($weekLog) : '';
            if (is_string($weekLog)) {
                @unlink($weekLog);
            }
            $assert(count($week['days']) === 7, 'week has 7 days');
            $assert(isset($week['summary']['week_pieces'], $week['summary']['typical_weekday_avg']), 'week summary keys');
            $assert(is_array($week['top_products']), 'week top products list');
            $assert(($week['load_state'] ?? '') === 'ready', 'week query loaded; a SQL failure must not look like a normal week');
            $assert(strpos($weekLogged, 'pmd week orders') === false, 'week query does not log a SQL error');

            $fixtureDate = '2099-06-16';
            $insideDate = '2099-06-13';
            $outsideDate = '2099-06-09';
            $emptyDate = '2099-06-15';
            $fixtureCustomers = $db->query(
                'SELECT id FROM customers WHERE COALESCE(is_active, 1) = 1 ORDER BY id LIMIT 2'
            )->fetchAll(PDO::FETCH_COLUMN);
            $fixtureProducts = $db->query('SELECT id FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
            $assert(count($fixtureCustomers) >= 2 && count($fixtureProducts) >= 1, 'fixture has two customers and a product');
            $wipeDates = [$fixtureDate, $insideDate, $outsideDate, $emptyDate];
            $wipeIn = implode(',', array_fill(0, count($wipeDates), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date IN ($wipeIn))")
                ->execute($wipeDates);
            $db->prepare("DELETE FROM daily_orders WHERE order_date IN ($wipeIn)")->execute($wipeDates);
            $insertOrder = $db->prepare(
                "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
            );
            $insertItem = $db->prepare(
                'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, 1.00, ?)'
            );
            $customerA = (int)$fixtureCustomers[0];
            $customerB = (int)$fixtureCustomers[1];
            $productA = (int)$fixtureProducts[0];
            $productB = (int)($fixtureProducts[1] ?? $fixtureProducts[0]);
            $insertOrder->execute([$customerA, $fixtureDate]);
            $orderA = (int)$db->lastInsertId();
            $insertItem->execute([$orderA, $productA, 10, 10]);
            $insertItem->execute([$orderA, $productB, 4, 4]);
            $insertOrder->execute([$customerB, $fixtureDate]);
            $orderB = (int)$db->lastInsertId();
            $insertItem->execute([$orderB, $productA, 6, 6]);
            $insertOrder->execute([$customerA, $insideDate]);
            $orderInside = (int)$db->lastInsertId();
            $insertItem->execute([$orderInside, $productA, 7, 7]);
            $insertOrder->execute([$customerB, $outsideDate]);
            $orderOutside = (int)$db->lastInsertId();
            $insertItem->execute([$orderOutside, $productA, 99, 99]);

            $known = bakery_pmd_week_orders($db, $fixtureDate);
            $knownByDate = [];
            foreach ($known['days'] as $dayRow) {
                $knownByDate[$dayRow['date']] = $dayRow;
            }
            $assert(($known['load_state'] ?? '') === 'ready', 'fixture week loaded');
            $assert((int)($knownByDate[$fixtureDate]['customers'] ?? -1) === 2, 'selected day has 2 customers');
            $assert((int)($knownByDate[$fixtureDate]['lines'] ?? -1) === 3, 'selected day has 3 lines');
            $assert((int)($knownByDate[$fixtureDate]['pieces'] ?? -1) === 20, 'selected day has 20 pieces');
            $assert((int)($knownByDate[$insideDate]['customers'] ?? -1) === 1, 'inside day has 1 customer');
            $assert((int)($knownByDate[$insideDate]['pieces'] ?? -1) === 7, 'inside day has 7 pieces');
            $assert((int)($knownByDate[$emptyDate]['pieces'] ?? -1) === 0, 'empty day stays zero');
            $assert(empty($knownByDate[$emptyDate]['has_orders']), 'empty day has no orders');
            $assert(!isset($knownByDate[$outsideDate]), 'day before the window is excluded');
            $assert((int)($known['summary']['week_pieces'] ?? -1) === 27, 'week total is 27 pieces');
            $assert((int)($known['summary']['active_days'] ?? -1) === 2, 'two active days in the window');

            $failed = null;
            try {
                $failed = bakery_pmd_week_orders($db, $fixtureDate, static function (): array {
                    throw new RuntimeException('simulated week retrieval failure');
                });
            } catch (Throwable $e) {
                $assert(false, 'simulated retrieval failure is unavailable (' . $e->getMessage() . ')');
            }
            if (is_array($failed)) {
                $assert(($failed['load_state'] ?? '') === 'unavailable', 'simulated retrieval failure is unavailable');
                $assert(array_key_exists('week_pieces', $failed['summary']) && $failed['summary']['week_pieces'] === null, 'simulated failure is not stored as zero pieces');
            }

            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date IN ($wipeIn))")
                ->execute($wipeDates);
            $db->prepare("DELETE FROM daily_orders WHERE order_date IN ($wipeIn)")->execute($wipeDates);

            $managerPage = (string)file_get_contents($root . '/production_manager.php');
            $assert(strpos($managerPage, 'production_manager.week_unavailable') !== false, 'week screen names the unavailable state');
            $assert(strpos($managerPage, 'load_state') !== false, 'week screen branches on load_state');
            $assert(strpos((string)file_get_contents($root . '/lang/en.php'), "'production_manager.week_unavailable'") !== false, 'english unavailable copy');
            $assert(strpos((string)file_get_contents($root . '/lang/es.php'), "'production_manager.week_unavailable'") !== false, 'spanish unavailable copy');

            $planCursor = new DateTimeImmutable('2099-08-01');
            while ($planCursor->format('N') !== '5') {
                $planCursor = $planCursor->modify('+1 day');
            }
            $planDateA = $planCursor->format('Y-m-d');
            $planDateB = $planCursor->modify('+14 days')->format('Y-m-d');
            $planDateC = $planCursor->modify('+21 days')->format('Y-m-d');
            $planProductId = (int)$fixtureProducts[0];
            $planDates = [$planDateA, $planDateB, $planDateC];
            $wipePlan = static function (PDO $db, array $dates, int $productId): void {
                $in = implode(',', array_fill(0, count($dates), '?'));
                if (table_exists($db, 'production_plan_commit_items')) {
                    $db->prepare("DELETE FROM production_plan_commit_items WHERE delivery_date IN ($in)")->execute($dates);
                }
                if (table_exists($db, 'production_plan_commits')) {
                    $db->prepare("DELETE FROM production_plan_commits WHERE delivery_date IN ($in)")->execute($dates);
                }
                if (table_exists($db, 'production_plan_items')) {
                    $db->prepare("DELETE FROM production_plan_items WHERE delivery_date IN ($in)")->execute($dates);
                }
                if (table_exists($db, 'product_inventory_days')) {
                    $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date = ? AND product_id = ?')
                        ->execute([$dates[0], $productId]);
                }
            };
            $qtyFor = static function (array $built, int $productId): ?int {
                foreach ($built['doughs'] as $dough) {
                    foreach ($dough['products'] as $product) {
                        if ((int)$product['product_id'] === $productId) {
                            return (int)$product['bake_quantity'];
                        }
                    }
                }
                return null;
            };
            $wipePlan($db, $planDates, $planProductId);
            $allowedPlan = [$planProductId => true];
            bakery_production_plan_save_targets($db, [$planDateA => [$planProductId => 11]], $allowedPlan, null);
            bakery_production_plan_commit($db, $planDateA, null);
            bakery_production_plan_save_targets($db, [$planDateA => [$planProductId => 77]], $allowedPlan, null);
            bakery_production_plan_save_targets($db, [$planDateB => [$planProductId => 250]], $allowedPlan, null);
            $releasedBoard = bakery_pmd_build($db, $planDateA, ['source' => 'bake_list']);
            $assert($qtyFor($releasedBoard, $planProductId) === 11, 'printing A uses committed 11, not draft 77 or date B');
            $assert($releasedBoard['quantity_date'] === $planDateA, 'released sheet date is A');
            $assert($releasedBoard['date_mismatch'] === false, 'released sheet does not substitute another date');
            $assert($releasedBoard['bake_source'] === 'committed_plan', 'released sheet is the committed plan');
            $draftBoard = bakery_pmd_build($db, $planDateA, ['source' => 'last_entered', 'entered_date' => $planDateA]);
            $assert($qtyFor($draftBoard, $planProductId) === 77, 'explicit draft print for A is 77');
            $assert($draftBoard['quantity_date'] === $planDateA, 'explicit draft print stays on A');
            $assert($draftBoard['bake_source'] === 'last_entered', 'explicit draft print is labeled as a draft');
            $laterBoard = bakery_pmd_build($db, $planDateA, ['source' => 'last_entered']);
            $assert($laterBoard['quantity_date'] === $planDateB, 'undated reference resolves to later date B');
            $assert($laterBoard['date_mismatch'] === true, 'undated reference is marked as a different date');
            $assert($qtyFor($laterBoard, $planProductId) === 250, 'undated reference quantities are B, not silent');
            $openBoard = bakery_pmd_build($db, $planDateC, ['source' => 'bake_list']);
            $assert($openBoard['committed'] === false, 'uncommitted date is not released');
            $assert($openBoard['bake_source'] !== 'last_entered', 'uncommitted date does not borrow another plan');
            $assert($openBoard['quantity_date'] === $planDateC, 'uncommitted date keeps its own date');
            if (table_exists($db, 'product_inventory_days')) {
                $db->prepare(
                    'INSERT INTO product_inventory_days (delivery_date, product_id, available_quantity, produced_quantity, loaded_quantity)
                     VALUES (?, ?, 4, 4, 0)
                     ON DUPLICATE KEY UPDATE produced_quantity = 4'
                )->execute([$planDateA, $planProductId]);
                bakery_production_plan_commit($db, $planDateA, null);
                $madeAfter = (int)$db->query(
                    'SELECT produced_quantity FROM product_inventory_days WHERE delivery_date = ' . $db->quote($planDateA) . ' AND product_id = ' . $planProductId
                )->fetchColumn();
                $recommitted = bakery_pmd_build($db, $planDateA, ['source' => 'bake_list']);
                $assert($madeAfter === 4, 're-commit keeps made counts');
                $assert($qtyFor($recommitted, $planProductId) === 77, 're-commit publishes the newer draft');
            }
            $wipePlan($db, $planDates, $planProductId);

            $routes = bakery_pmd_route_plan_vs_actual($db, $date);
            $assert(isset($routes['summary']['planned_stops'], $routes['summary']['actual_stops']), 'routes summary keys');
            $assert(is_array($routes['rows']), 'routes rows list');

            $supply = bakery_pmd_demand_vs_supply($db, $date);
            $assert(isset($supply['summary']['demand'], $supply['summary']['short_skus']), 'supply summary keys');
            $assert(is_array($supply['rows']), 'supply rows list');
            $assert(isset($supply['inventory_ready']), 'supply inventory_ready flag');

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
