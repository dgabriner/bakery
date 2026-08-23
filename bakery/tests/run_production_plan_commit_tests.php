<?php
/**
 * Production plan commit ritual: save is not bake-list truth; commit reaches the baker;
 * post-commit demand drift is loud; re-commit preserves produced_quantity.
 *
 * CLI / local bakerysf_test only. Cleans up the synthetic future date it uses.
 * Does not reset the disposable snapshot.
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
require_once $root . '/includes/auth.php';
require_once $root . '/includes/production_plan.php';
require_once $root . '/includes/daily_run.php';
require_once $root . '/includes/product_inventory.php';
require_once $root . '/includes/operational_timeline.php';

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

$focusWeek = bakery_production_center_resolve_focus('', '2026-08-20', '2026-08-17');
$assert($focusWeek['date'] === '2026-08-20', 'week= operating date opens that delivery day');
$assert($focusWeek['week_start'] === '2026-08-17', 'week start is Monday of the focused day');
$focusDateWins = bakery_production_center_resolve_focus('2026-08-21', '2026-08-17', '2026-08-17');
$assert($focusDateWins['date'] === '2026-08-21', 'date= wins over week=');
$focusToday = bakery_production_center_resolve_focus('', '', '2026-08-20');
$assert($focusToday['date'] === '2026-08-20', 'empty params use today');

$findBake = static function (array $bakeList, int $productId): ?array {
    foreach ($bakeList['items'] as $item) {
        if ((int)$item['product_id'] === $productId) {
            return $item;
        }
    }
    return null;
};

$date = date('Y-m-d', strtotime('+40 days'));
echo "Test date: $date\n";

$customerId = (int)$db->query(
    "SELECT id FROM customers WHERE COALESCE(is_active, 1) = 1 ORDER BY id LIMIT 1"
)->fetchColumn();
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
if ($customerId <= 0 || $productId <= 0) {
    fwrite(STDERR, "Need at least one customer and product on bakerysf_test\n");
    exit(1);
}

$cleanup = static function (PDO $db, string $date) use ($productId): void {
    if (table_exists($db, 'production_plan_commit_items')) {
        $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_commits')) {
        $db->prepare('DELETE FROM production_plan_commits WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'production_plan_items')) {
        $db->prepare('DELETE FROM production_plan_items WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'inventory_movements')) {
        $db->prepare('DELETE FROM inventory_movements WHERE delivery_date=?')->execute([$date]);
    }
    if (table_exists($db, 'product_inventory_days')) {
        $db->prepare('DELETE FROM product_inventory_days WHERE delivery_date=? AND product_id=?')
            ->execute([$date, $productId]);
    }
    $db->prepare('DELETE FROM daily_order_items WHERE daily_order_id IN (SELECT id FROM daily_orders WHERE order_date=?)')
        ->execute([$date]);
    if (table_exists($db, 'daily_order_assignments')) {
        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date=?')->execute([$date]);
    }
    $db->prepare('DELETE FROM daily_orders WHERE order_date=?')->execute([$date]);
    if (function_exists('bakery_operational_events_ready') && bakery_operational_events_ready($db)) {
        $db->prepare('DELETE FROM operational_events WHERE operational_date=?')->execute([$date]);
    }
};

$cleanup($db, $date);
bakery_production_plan_commits_ensure($db);
$assert(bakery_production_plan_commits_ready($db), 'production_plan_commits table available');
$assert(bakery_production_plan_commit_items_ready($db), 'production_plan_commit_items table available');

$insertOrder = $db->prepare(
    "INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, 'pending', 0)"
);
$insertOrder->execute([$customerId, $date]);
$orderId = (int)$db->lastInsertId();
$assert($orderId > 0, 'dated order created');

$demandQtySeed = 5;
$db->prepare(
    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
     VALUES (?, ?, ?, 1.00, ?)'
)->execute([$orderId, $productId, $demandQtySeed, $demandQtySeed * 1.00]);
$db->prepare('UPDATE daily_orders SET total_amount=? WHERE id=?')->execute([$demandQtySeed * 1.00, $orderId]);

$demand = bakery_operating_demand_by_product($db, $date);
$demandQty = (int)($demand['by_product'][$productId] ?? 0);
$assert($demandQty >= $demandQtySeed, 'operating demand includes the dated line');

$planQty = $demandQty + 17;
$savedDraft = bakery_production_plan_save_targets($db, [$date => [$productId => $planQty]], [$productId => true], null);
$assert($savedDraft === 1, 'save_targets writes one draft quantity');
$draftAfterSave = bakery_production_plan_draft_quantities($db, $date);
$assert((int)($draftAfterSave[$productId] ?? 0) === $planQty, 'draft quantity matches the autosave helper');

$casQty = $planQty + 3;
$casSaved = bakery_production_plan_save_target_cas($db, $date, $productId, $casQty, [$productId => true], null, true, $planQty);
$assert((int)$casSaved['planned_quantity'] === $casQty, 'conflict-safe autosave accepts the value the browser read');
$conflictCaught = false;
try {
    bakery_production_plan_save_target_cas($db, $date, $productId, $casQty + 4, [$productId => true], null, true, $planQty);
} catch (RuntimeException $e) {
    $conflictCaught = str_starts_with($e->getMessage(), 'production_plan_conflict:');
}
$assert($conflictCaught, 'stale autosave is rejected instead of overwriting newer work');
$draftAfterConflict = bakery_production_plan_draft_quantities($db, $date);
$assert((int)($draftAfterConflict[$productId] ?? 0) === $casQty, 'stale autosave leaves the newer target intact');
$planQty = $casQty;

$bakeUncommitted = bakery_production_bake_list($db, $date);
$uncommittedItem = $findBake($bakeUncommitted, $productId);
$assert($uncommittedItem !== null, 'uncommitted bake list still shows the demanded product');
$assert((int)$uncommittedItem['demand_quantity'] === $demandQty, 'uncommitted demand is readable');
$assert((int)$uncommittedItem['bake_quantity'] === $demandQty, 'save without commit does not become bake-list truth');
$assert((int)$uncommittedItem['bake_quantity'] !== $planQty, 'saved target is not the uncommitted bake quantity');
$assert(empty($bakeUncommitted['committed']), 'date is not committed after save');

$runUncommitted = bakery_daily_run_build($db, $date);
$planStage = null;
foreach ($runUncommitted['stages'] as $stage) {
    if (($stage['key'] ?? '') === 'production_plan') {
        $planStage = $stage;
        break;
    }
}
$assert(is_array($planStage), 'Commit Production Plan stage present');
$assert(($planStage['ui_state'] ?? '') !== 'complete', 'stage 2 is not complete without commit');
$hasUncommitted = false;
foreach ($runUncommitted['blockers'] as $blocker) {
    if (($blocker['type'] ?? '') === 'production_plan_uncommitted') {
        $hasUncommitted = true;
        break;
    }
}
$assert($hasUncommitted, 'critical production_plan_uncommitted blocker present');
$assert(empty($runUncommitted['operational_complete']), 'day not operationally complete while uncommitted');

$closeFailed = false;
try {
    bakery_daily_run_close_day($db, $date, null, 'premature commit-plan test');
} catch (RuntimeException $e) {
    $closeFailed = true;
}
$assert($closeFailed, 'closeout rejected without plan commit');

bakery_production_plan_commit($db, $date, null);
sleep(1);
$bakeCommitted = bakery_production_bake_list($db, $date);
$committedItem = $findBake($bakeCommitted, $productId);
$assert(!empty($bakeCommitted['committed']), 'date is committed');
$assert($committedItem !== null, 'committed bake list includes the product');
$assert((int)$committedItem['bake_quantity'] === $planQty, 'baker quantity equals committed plan');
$assert((int)$committedItem['demand_quantity'] === $demandQty, 'demand still readable after commit');
$assert((string)$committedItem['source'] === 'committed_plan', 'bake source is committed_plan');

$produceTargets = bakery_production_produce_targets_by_product($db, $date);
$assert(!empty($produceTargets['committed']), 'produce targets mark the date committed');
$assert((int)($produceTargets['by_product'][$productId] ?? 0) === $planQty, 'produce targets use committed bake qty');

$runAfterCommit = bakery_daily_run_build($db, $date);
$produceStageAfterCommit = null;
foreach ($runAfterCommit['stages'] as $stage) {
    if (($stage['key'] ?? '') === 'produce') {
        $produceStageAfterCommit = $stage;
        break;
    }
}
$assert(($produceStageAfterCommit['target_source'] ?? '') === 'committed_plan', 'Daily Run Produce measures against committed bake');

$producedQty = 3;
$producedStmt = $db->prepare(
    'SELECT produced_quantity FROM product_inventory_days WHERE delivery_date=? AND product_id=?'
);
if (bakery_inventory_ready($db)) {
    bakery_inventory_record_production($db, $date, $productId, $producedQty, 'commit-plan test');
    $producedStmt->execute([$date, $productId]);
    $assert((int)$producedStmt->fetchColumn() === $producedQty, 'produced_quantity recorded before drift');
} else {
    $assert(false, 'finished-goods inventory is required to prove produced_quantity is preserved');
}

$newLineQty = $demandQtySeed + 8;
$db->prepare('UPDATE daily_order_items SET quantity=?, line_total=quantity*unit_price WHERE daily_order_id=? AND product_id=?')
    ->execute([$newLineQty, $orderId, $productId]);
bakery_record_operational_event(
    $db,
    BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
    'Test: dated demand changed after plan commit',
    ['operational_date' => $date, 'daily_order_id' => $orderId, 'product_id' => $productId]
);

$demandAfter = bakery_operating_demand_by_product($db, $date);
$demandQtyAfter = (int)($demandAfter['by_product'][$productId] ?? 0);
$assert($demandQtyAfter !== $demandQty, 'dated demand moved after commit');

$bakeAfterDrift = bakery_production_bake_list($db, $date);
$driftItem = $findBake($bakeAfterDrift, $productId);
$assert($driftItem !== null, 'bake list still has the product after demand change');
$assert((int)$driftItem['bake_quantity'] === $planQty, 'bake list stays on committed plan after demand change');
$assert((int)$driftItem['demand_quantity'] === $demandQtyAfter, 'new demand is visible beside committed bake qty');
$assert((int)($bakeAfterDrift['changed_since']['count'] ?? 0) > 0, 'changed_since count is non-zero after demand event');

$runDrift = bakery_daily_run_build($db, $date);
$hasDrift = false;
foreach ($runDrift['blockers'] as $blocker) {
    if (($blocker['type'] ?? '') === 'production_plan_drift') {
        $hasDrift = true;
        break;
    }
}
$cc = bakery_dashboard_command_center($db, $date);
foreach ($cc['exceptions'] as $ex) {
    if (($ex['type'] ?? '') === 'production_plan_drift') {
        $hasDrift = true;
        break;
    }
}
$assert($hasDrift, 'plan-drift exception exists on Daily Run or dashboard');
$driftStage = null;
foreach ($runDrift['stages'] as $stage) {
    if (($stage['key'] ?? '') === 'production_plan') {
        $driftStage = $stage;
        break;
    }
}
$assert(($driftStage['ui_state'] ?? '') !== 'complete', 'stage 2 reopens on post-commit demand drift');

$recommitQty = $planQty + 4;
$db->prepare(
    'UPDATE production_plan_items SET planned_quantity=? WHERE delivery_date=? AND product_id=?'
)->execute([$recommitQty, $date, $productId]);
bakery_production_plan_commit($db, $date, null);

$bakeRecommit = bakery_production_bake_list($db, $date);
$recommitItem = $findBake($bakeRecommit, $productId);
$assert($recommitItem !== null, 'bake list present after re-commit');
$assert((int)$recommitItem['bake_quantity'] === $recommitQty, 'baker quantities update on re-commit');
$producedStmt->execute([$date, $productId]);
$assert((int)$producedStmt->fetchColumn() === $producedQty, 'produced_quantity is not zeroed on re-commit');

$runAfterRecommit = bakery_daily_run_build($db, $date);
$hasDriftAfter = false;
foreach ($runAfterRecommit['blockers'] as $blocker) {
    if (($blocker['type'] ?? '') === 'production_plan_drift') {
        $hasDriftAfter = true;
        break;
    }
}
$assert(!$hasDriftAfter, 're-commit clears plan-drift until demand moves again');

$assert(!in_array('production_center.php', bakery_baker_scripts(), true), 'baker role still cannot open Production Center');
$assert(in_array('production.php', bakery_baker_scripts(), true), 'baker role still opens Daily Production');

// Re-commit diff helper: bakers see what changed between the previous commit
// and the live one. Table-missing degradation is asserted as a source
// contract because bakerysf_test installs the real commit tables.
$diffSource = (string)file_get_contents($root . '/includes/production_plan.php');
$assert(strpos($diffSource, 'function bakery_production_commit_diff(PDO $db, string $date)') !== false, 'commit diff helper exists with the canonical signature');
$assert(strpos($diffSource, "if (!bakery_production_plan_commits_ready(\$db) || !bakery_production_plan_commit_items_ready(\$db))") !== false, 'commit diff returns empty when commit tables are missing');
$assert(strpos($diffSource, '!bakery_operational_events_ready($db)') !== false, 'commit diff returns empty when operational_events is missing');

// Baker UX contract: bakers live in the committed plan on their own sheet.
// The committed bake target sits beside Left/Made only after commit, the
// fresh-commit stamp names when the manager set the numbers, and an
// uncommitted date says so plainly instead of a vague manager note.
$productionSource = (string)file_get_contents($root . '/production.php');
$assert(strpos($productionSource, "'production.bake_target'") !== false, 'baker qty grid renders the committed bake target label');
$assert(
    preg_match('/if \(\$planCommitted\):\s*\?>\s*<div class="bp-qty-target">/', $productionSource) === 1,
    'bake target cell renders only when the date is committed'
);
$assert(strpos($productionSource, 'bp-qty-grid--baker-committed') !== false, 'committed baker grid switches to the three-cell layout');
$assert(strpos($productionSource, "bakery_t('production.baker_committed_stamp'") !== false, 'baker focus strip stamps when the manager set the numbers');
$assert(strpos($productionSource, "'production.baker_uncommitted_summary'") !== false, 'uncommitted dates say so on the sheet summary');
$assert(strpos($productionSource, 'baker_plan_note') === false, 'retired vague plan-note key is gone from the page');

$langEn = require $root . '/lang/en.php';
$langEs = require $root . '/lang/es.php';
foreach (['production.bake_target', 'production.baker_committed_stamp', 'production.baker_uncommitted_summary'] as $i18nKey) {
    $assert(isset($langEn[$i18nKey], $langEs[$i18nKey]), "i18n key present in both languages: $i18nKey");
}
$assert(
    !isset($langEn['production.baker_plan_note'], $langEs['production.baker_plan_note']),
    'retired plan-note key removed from both languages'
);


$dateB = date('Y-m-d', strtotime('+41 days'));
echo "Diff test date: $dateB\n";
$cleanup($db, $dateB);
bakery_production_plan_commits_ensure($db);

$diffProductIds = array_map('intval', $db->query('SELECT id FROM products ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN));
$diffProductA = $diffProductIds[0] ?? 0;
$diffProductB = $diffProductIds[1] ?? 0;
$assert($diffProductA > 0, 'diff fixture has at least one product');

$assert(bakery_production_commit_diff($db, $dateB) === [], 'zero commits yield an empty re-commit diff');

bakery_production_plan_save_targets(
    $db,
    [$dateB => [$diffProductA => 100]],
    array_fill_keys($diffProductIds, true),
    null
);
bakery_production_plan_commit($db, $dateB, null);
$assert(bakery_production_commit_diff($db, $dateB) === [], 'a single commit yields an empty re-commit diff');

$secondDraft = [$diffProductA => 80];
if ($diffProductB > 0) {
    $secondDraft[$diffProductB] = 30;
}
bakery_production_plan_save_targets($db, [$dateB => $secondDraft], array_fill_keys($diffProductIds, true), null);
bakery_production_plan_commit($db, $dateB, null);

$commitDiff = bakery_production_commit_diff($db, $dateB);
$diffByProduct = [];
foreach ($commitDiff as $diffRow) {
    $diffByProduct[(int)$diffRow['product_id']] = $diffRow;
}
$assert(isset($diffByProduct[$diffProductA]), 're-commit diff reports the changed product');
$assert((int)$diffByProduct[$diffProductA]['previous_quantity'] === 100, 're-commit diff carries the previous quantity');
$assert((int)$diffByProduct[$diffProductA]['new_quantity'] === 80, 're-commit diff carries the new quantity');
if ($diffProductB > 0) {
    $assert(isset($diffByProduct[$diffProductB]), 'product appearing only in the newer commit is reported sanely');
    $assert((int)$diffByProduct[$diffProductB]['previous_quantity'] === 0, 'newer-only product diffs from zero');
    $assert((int)$diffByProduct[$diffProductB]['new_quantity'] === 30, 'newer-only product shows its committed quantity');
    $assert(trim((string)$diffByProduct[$diffProductB]['product_name']) !== '', 'diff rows carry a product name');
}

if ($diffProductB > 0) {
    $db->prepare('DELETE FROM production_plan_items WHERE delivery_date=? AND product_id=?')
        ->execute([$dateB, $diffProductB]);
    bakery_production_plan_commit($db, $dateB, null);
    $commitDiffAfterRemoval = bakery_production_commit_diff($db, $dateB);
    $removalRow = null;
    foreach ($commitDiffAfterRemoval as $diffRow) {
        if ((int)$diffRow['product_id'] === $diffProductB) {
            $removalRow = $diffRow;
            break;
        }
    }
    $assert($removalRow !== null, 'product removed on re-commit is reported');
    $assert($removalRow !== null && (int)$removalRow['new_quantity'] === 0, 'removed product diffs down to zero');
}

$cleanup($db, $dateB);
$cleanup($db, $date);
echo "\nPassed: $pass\nFailed: $fail\n";
exit($fail > 0 ? 1 : 0);
