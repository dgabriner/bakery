<?php
/**
 * Bread Education course gating tests (migrations 068 / 069).
 * 068 / Prompt 26: gate readiness, default-free world, assign/clear offering,
 * entitlement unlock via paid state, refund re-lock, retired offering frees
 * the course, unlocks listing, and lesson-media reverse-lock.
 * 069: course template-formula handoff — setter validation, one-click
 * start_batch_from_course (owned copy + snapshot), unmapped-course errors.
 *
 * Runs against bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require __DIR__ . '/isolate_test_db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

require_once __DIR__ . '/../includes/sf_baker.php';

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  {$msg}\n";
        $pass++;
        return;
    }
    echo "FAIL  {$msg}\n";
    $fail++;
};

// Deterministic fixture pre-clean.
$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')->execute(['SFB Gate A', 'SFB Gate B']);
$db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Gate Class']);
$db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Gate Course'))");
$db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Gate Course')");
$db->prepare("DELETE FROM sfb_courses WHERE title = ?")->execute(['Gate Course']);

try {
    $assert(bakery_sfb_gating_ready($db), '068 required_offering_id column exists');

    // Fixtures ---------------------------------------------------------------
    $ins = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ins->execute(['SFB Gate A', '555-0171', '1 Gate Way']);
    $customerA = (int)$db->lastInsertId();
    $ins->execute(['SFB Gate B', '555-0172', '2 Gate Way']);
    $customerB = (int)$db->lastInsertId();

    $offeringId = bakery_sfb_create_offering($db, 'Gate Class', 45.00, 'class', 'Gated class fixture.', null);
    $courseId = bakery_sfb_create_course($db, 'Gate Course', 'Gated course fixture.');
    $lessonId = bakery_sfb_create_lesson($db, $courseId, 'Gate Lesson', '', '');
    bakery_sfb_add_lesson_step($db, $lessonId, 'Watch the fold:', 'gate/2026/fold.mp4', 'video');

    $courseRow = bakery_sfb_course($db, $courseId);
    $assert(is_array($courseRow) && array_key_exists('required_offering_id', $courseRow), 'course select exposes the gate column');

    // Default-free world ------------------------------------------------------
    $lock = bakery_sfb_course_lock($db, $customerB, $courseRow);
    $assert($lock['locked'] === false && $lock['offering'] === null, 'unassigned course is free');
    $assert(bakery_sfb_media_path_locked($db, 'gate/2026/fold.mp4', $customerB) === false, 'free-course media never locks');

    // Assign the class -> locks for non-entitled bakers -----------------------
    bakery_sfb_set_course_offering($db, $courseId, $offeringId);
    $gatedCourse = bakery_sfb_course($db, $courseId);
    $lockA = bakery_sfb_course_lock($db, $customerA, $gatedCourse);
    $lockB = bakery_sfb_course_lock($db, $customerB, $gatedCourse);
    $assert($lockA['locked'] === true && (int)$lockA['offering']['id'] === $offeringId, 'stranger sees the class lock');
    $assert($lockB['locked'] === true, 'non-entitled baker is locked too');
    $assert(bakery_sfb_media_path_locked($db, 'gate/2026/fold.mp4', $customerB) === true, 'locked-course media locks for non-entitled viewer');
    $assert(bakery_sfb_media_path_locked($db, 'nowhere/never.mp4', $customerB) === false, 'unknown media paths never lock');
    $assert(count(bakery_sfb_courses_requiring($db, $offeringId)) === 1, 'unlock list shows the assigned course');

    // Paid purchase unlocks ----------------------------------------------------
    $purchaseId = bakery_sfb_record_purchase_intent($db, $customerB, $offeringId);
    bakery_sfb_set_purchase_status($db, $purchaseId, 'paid', null, 'gate fixture', null);
    $assert(bakery_sfb_customer_entitled_to($db, $customerB, $offeringId), 'paid purchase grants entitlement');
    $lockBPaid = bakery_sfb_course_lock($db, $customerB, $gatedCourse);
    $assert($lockBPaid['locked'] === false, 'entitled baker passes the lock');
    $assert(bakery_sfb_media_path_locked($db, 'gate/2026/fold.mp4', $customerB) === false, 'entitled viewer unlocks lesson media');

    // Refund re-locks -----------------------------------------------------------
    bakery_sfb_set_purchase_status($db, $purchaseId, 'refunded', null, 'gate fixture refund', null);
    $assert(!bakery_sfb_customer_entitled_to($db, $customerB, $offeringId), 'refund removes entitlement');
    $lockBRefund = bakery_sfb_course_lock($db, $customerB, $gatedCourse);
    $assert($lockBRefund['locked'] === true, 'refunded baker is locked again');

    // Clearing the gate frees the course ---------------------------------------
    bakery_sfb_set_course_offering($db, $courseId, 0);
    $freedCourse = bakery_sfb_course($db, $courseId);
    $assert(bakery_sfb_course_lock($db, $customerA, $freedCourse)['locked'] === false, 'cleared gate is free for everyone');
    $assert(bakery_sfb_courses_requiring($db, $offeringId) === [], 'unlock list empties when the gate clears');

    try {
        bakery_sfb_set_course_offering($db, $courseId, $offeringId);
        bakery_sfb_toggle_offering($db, $offeringId); // owner retires the class
        $retired = bakery_sfb_course($db, $courseId);
        $retiredLock = bakery_sfb_course_lock($db, $customerA, $retired);
        $assert($retiredLock['locked'] === false && $retiredLock['offering'] === null, 'retired offering frees its course');
    } finally {
        $db->prepare('UPDATE sfb_offerings SET is_active = 1 WHERE id = ?')->execute([$offeringId]);
    }

    // Guard rails -----------------------------------------------------------------
    try {
        bakery_sfb_set_course_offering($db, 999999, $offeringId);
        $assert(false, 'gate assignment on unknown course rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'gate assignment on unknown course rejected');
    }
} catch (Throwable $e) {
    echo 'FAIL  unexpected: ' . $e->getMessage() . "\n";
    $fail++;
}

/* ── Course → formula handoff (migration 069) ──────────────────────────── */

// Pre-clean 069 fixtures; customer delete cascades formulas/batches/snapshots.
$db->prepare('DELETE FROM customers WHERE name = ?')->execute(['SFB Handoff Baker']);
$db->prepare('DELETE FROM sfb_courses WHERE title = ?')->execute(['Handoff Course']);
$db->prepare('DELETE FROM sfb_formulas WHERE name IN (?, ?)')->execute(['Handoff Standard', 'Baker Own Loaf']);

try {
    $assert(bakery_sfb_handoff_ready($db), '069 template_formula_id column exists');

    $insH = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $insH->execute(['SFB Handoff Baker', '555-0173', '3 Gate Way']);
    $customerH = (int)$db->lastInsertId();

    $db->prepare(
        "INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template)
         VALUES (NULL, 'Handoff Standard', 'Course handoff standard.', 900, 1)"
    )->execute();
    $templateFormulaId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO sfb_formula_ingredients (formula_id, percentage, sort_order) VALUES (?, 100.00, 0)')
        ->execute([$templateFormulaId]);

    $handoffCourseId = bakery_sfb_create_course($db, 'Handoff Course', 'Handoff course fixture.');
    $courseRowH = bakery_sfb_course($db, $handoffCourseId);
    $assert(is_array($courseRowH) && array_key_exists('template_formula_id', $courseRowH), 'course select exposes the template_formula_id column');
    $assert(empty($courseRowH['template_formula_id']), 'fresh course has no bake-along formula mapped');

    try {
        bakery_sfb_start_batch_from_course($db, $customerH, $handoffCourseId);
        $assert(false, 'unmapped course start rejected');
    } catch (RuntimeException $e) {
        $assert(true, 'unmapped course start rejected');
    }

    $db->prepare(
        "INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template)
         VALUES (?, 'Baker Own Loaf', NULL, 800, 0)"
    )->execute([$customerH]);
    $ownedFormulaId = (int)$db->lastInsertId();

    try {
        bakery_sfb_set_course_template_formula($db, $handoffCourseId, $ownedFormulaId);
        $assert(false, 'non-template formula rejected as bake-along');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'non-template formula rejected as bake-along');
    }

    try {
        bakery_sfb_set_course_template_formula($db, 999999, $templateFormulaId);
        $assert(false, 'template mapping on unknown course rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'template mapping on unknown course rejected');
    }

    bakery_sfb_set_course_template_formula($db, $handoffCourseId, $templateFormulaId);
    $mappedCourse = bakery_sfb_course($db, $handoffCourseId);
    $assert((int)$mappedCourse['template_formula_id'] === $templateFormulaId, 'setter maps the standard formula');

    $batchIdH = bakery_sfb_start_batch_from_course($db, $customerH, $handoffCourseId);
    $assert($batchIdH > 0, 'start_batch_from_course returns a batch id');

    $batchStmt = $db->prepare('SELECT id, customer_id, formula_id FROM sfb_batches WHERE id = ? LIMIT 1');
    $batchStmt->execute([$batchIdH]);
    $batchRow = $batchStmt->fetch();
    $assert($batchRow && (int)$batchRow['customer_id'] === $customerH, 'handoff batch belongs to the baker');

    $copiedFormula = bakery_sfb_formula($db, $customerH, (int)$batchRow['formula_id']);
    $assert($copiedFormula
        && (int)$copiedFormula['customer_id'] === $customerH
        && (int)$copiedFormula['is_template'] === 0, 'course start copies the template into an owned formula');

    $snapStmt = $db->prepare('SELECT source_formula_id, formula_name FROM sfb_batch_formula_snapshots WHERE batch_id = ? LIMIT 1');
    $snapStmt->execute([$batchIdH]);
    $snapRow = $snapStmt->fetch();
    $assert($snapRow
        && (int)$snapRow['source_formula_id'] === (int)$batchRow['formula_id']
        && $snapRow['formula_name'] === 'Handoff Standard', 'handoff batch carries a frozen formula snapshot');

    $linesStmt = $db->prepare('SELECT COUNT(*) FROM sfb_batch_formula_snapshot_lines WHERE batch_id = ?');
    $linesStmt->execute([$batchIdH]);
    $assert((int)$linesStmt->fetchColumn() >= 1, 'snapshot lines copied from the template');

    bakery_sfb_set_course_template_formula($db, $handoffCourseId, 0);
    $clearedCourse = bakery_sfb_course($db, $handoffCourseId);
    $assert(empty($clearedCourse['template_formula_id']), 'clearing the map frees the course');
} catch (Throwable $e) {
    echo 'FAIL  unexpected (069): ' . $e->getMessage() . "\n";
    $fail++;
}

/* ── Staff visibility pack: invite funnel + paid revenue ───────────────── */

// Pre-clean visibility fixtures; customer delete cascades their purchases.
$db->prepare('DELETE FROM sfb_invites WHERE label IN (?, ?, ?)')
    ->execute(['Funnel Alpha', 'Funnel Beta', 'Funnel Gamma']);
$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')->execute(['SFB Funnel One', 'SFB Funnel Two']);
$db->prepare('DELETE FROM sfb_offerings WHERE title IN (?, ?)')->execute(['Revenue Class A', 'Revenue Class B']);

try {
    $insV = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $insV->execute(['SFB Funnel One', '555-0174', '4 Gate Way']);
    $funnelOne = (int)$db->lastInsertId();
    $insV->execute(['SFB Funnel Two', '555-0175', '5 Gate Way']);
    $funnelTwo = (int)$db->lastInsertId();

    $offRevA = bakery_sfb_create_offering($db, 'Revenue Class A', 30.00, 'class', 'Visibility fixture A.', null);
    $offRevB = bakery_sfb_create_offering($db, 'Revenue Class B', 12.50, 'class', 'Visibility fixture B.', null);

    // Shared test DB: funnel totals are asserted as deltas over the baseline.
    $funnelBefore = bakery_sfb_invite_funnel($db);
    $inviteAlpha = bakery_sfb_create_invite($db, 'learn', 'Funnel Alpha');
    $inviteBeta = bakery_sfb_create_invite($db, 'share', 'Funnel Beta');
    bakery_sfb_create_invite($db, 'learn', 'Funnel Gamma');

    $assert(bakery_sfb_mark_invite_used($db, (int)$inviteAlpha['id'], $funnelOne), 'first invite claim sticks');
    $assert(bakery_sfb_mark_invite_used($db, (int)$inviteBeta['id'], $funnelTwo), 'second invite claims cleanly');

    $funnelAfter = bakery_sfb_invite_funnel($db);
    $assert((int)$funnelAfter['minted'] === (int)$funnelBefore['minted'] + 3, 'funnel sees three more invites minted');
    $assert((int)$funnelAfter['used'] === (int)$funnelBefore['used'] + 2, 'funnel sees exactly two claims');
    $assert((int)$funnelAfter['unused'] === (int)$funnelAfter['minted'] - (int)$funnelAfter['used'], 'open invites are minted minus claimed');

    $foundBeta = null;
    foreach ($funnelAfter['recent_used'] as $activationRow) {
        if (($activationRow['code'] ?? '') === $inviteBeta['code']) {
            $foundBeta = $activationRow;
            break;
        }
    }
    $assert(is_array($foundBeta)
        && ($foundBeta['intent'] ?? '') === 'share'
        && ($foundBeta['label'] ?? '') === 'Funnel Beta'
        && ($foundBeta['activated_name'] ?? '') === 'SFB Funnel Two'
        && !empty($foundBeta['used_at']), 'activations join customer name with label, intent, and used_at');

    // 2 paid on Class A; Class B keeps 1 paid beside 1 refunded.
    $purchaseAA = bakery_sfb_record_purchase_intent($db, $funnelOne, $offRevA);
    bakery_sfb_set_purchase_status($db, $purchaseAA, 'paid', null, 'visibility fixture', null);
    $purchaseAB = bakery_sfb_record_purchase_intent($db, $funnelTwo, $offRevA);
    bakery_sfb_set_purchase_status($db, $purchaseAB, 'paid', null, 'visibility fixture', null);
    $purchaseBKeep = bakery_sfb_record_purchase_intent($db, $funnelTwo, $offRevB);
    bakery_sfb_set_purchase_status($db, $purchaseBKeep, 'paid', null, 'visibility fixture', null);
    $purchaseBRefund = bakery_sfb_record_purchase_intent($db, $funnelOne, $offRevB);
    bakery_sfb_set_purchase_status($db, $purchaseBRefund, 'paid', null, 'visibility fixture', null);
    bakery_sfb_set_purchase_status($db, $purchaseBRefund, 'refunded', null, 'visibility fixture refund', null);

    $revenueRows = bakery_sfb_offering_revenue($db);
    $revA = null;
    $revB = null;
    foreach ($revenueRows as $revRow) {
        if ((string)$revRow['title'] === 'Revenue Class A') {
            $revA = $revRow;
        } elseif ((string)$revRow['title'] === 'Revenue Class B') {
            $revB = $revRow;
        }
    }
    $assert(is_array($revA) && (int)$revA['paid_count'] === 2 && (int)$revA['cents'] === 6000,
        'Class A revenue sums both paid snapshots at $60.00');
    $assert(is_array($revB) && (int)$revB['paid_count'] === 1 && (int)$revB['cents'] === 1250,
        'Class B revenue excludes the refunded purchase');
    $posA = -1;
    $posB = -1;
    foreach ($revenueRows as $revIdx => $revRow) {
        if ((string)$revRow['title'] === 'Revenue Class A') {
            $posA = $revIdx;
        } elseif ((string)$revRow['title'] === 'Revenue Class B') {
            $posB = $revIdx;
        }
    }
    $assert($posA !== -1 && $posB !== -1 && $posA < $posB, 'revenue list orders by cents descending');

    // Snapshots win: moving the live catalog price must not touch paid sums.
    $db->prepare('UPDATE sfb_offerings SET price_cents = 9900 WHERE id = ?')->execute([$offRevA]);
    $revenueAfterReprice = bakery_sfb_offering_revenue($db);
    $revAAfter = null;
    foreach ($revenueAfterReprice as $revRow) {
        if ((string)$revRow['title'] === 'Revenue Class A') {
            $revAAfter = $revRow;
        }
    }
    $assert(is_array($revAAfter) && (int)$revAAfter['cents'] === 6000, 'reprice never rewrites historical paid sums');
} catch (Throwable $e) {
    echo 'FAIL  unexpected (visibility): ' . $e->getMessage() . "\n";
    $fail++;
}

/* ── Portal bridge: enable helper + origin guard ───────────────────────── */

// Pre-clean bridge fixtures.
$db->prepare('DELETE FROM customers WHERE name IN (?, ?, ?, ?)')->execute([
    'SFB Bridge Baker',
    'SFB Bridge Sleeping',
    'SFB Bridge Locked',
    'SFB Bridge Synthetic',
]);

try {
    $insB = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 0, 1)'
    );
    $insB->execute(['SFB Bridge Baker', '555-0176', '6 Gate Way']);
    $bridgeCustomer = (int)$db->lastInsertId();

    $insSleep = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 0, 0)'
    );
    $insSleep->execute(['SFB Bridge Sleeping', '555-0177', '7 Gate Way']);
    $sleepingCustomer = (int)$db->lastInsertId();

    $insLocked = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 0, 0, 1)'
    );
    $insLocked->execute(['SFB Bridge Locked', '555-0178', '8 Gate Way']);
    $lockedCustomer = (int)$db->lastInsertId();

    $wholesaleTables = ['daily_orders', 'zones', 'routes', 'billing_invoice_sends', 'billing_export_invoices'];
    $countsBefore = [];
    foreach ($wholesaleTables as $wt) {
        $countsBefore[$wt] = table_exists($db, $wt)
            ? (int)$db->query("SELECT COUNT(*) FROM {$wt}")->fetchColumn()
            : -1;
    }

    // Refusals: unknown, inactive, portal-disabled.
    $assert(bakery_sfb_enable_for_customer($db, 0) === null, 'enable refuses a zero customer id');
    $assert(bakery_sfb_enable_for_customer($db, 99999999) === null, 'enable refuses an unknown customer');
    $assert(bakery_sfb_enable_for_customer($db, $sleepingCustomer) === null, 'enable refuses an inactive customer');
    $assert(bakery_sfb_enable_for_customer($db, $lockedCustomer) === null, 'enable refuses a portal-disabled customer');

    foreach ([$sleepingCustomer, $lockedCustomer] as $refusedId) {
        $stillOff = $db->prepare('SELECT sf_baker_enabled FROM customers WHERE id = ?');
        $stillOff->execute([$refusedId]);
        $assert((int)$stillOff->fetchColumn() === 0, 'refusal leaves sf_baker_enabled off');
    }

    // Enable flips the flag and is idempotent.
    $state = bakery_sfb_enable_for_customer($db, $bridgeCustomer);
    $assert(is_array($state) && (int)$state['sf_baker_enabled'] === 1, 'enable returns updated state with flag on');
    $again = bakery_sfb_enable_for_customer($db, $bridgeCustomer);
    $assert(is_array($again) && (int)$again['sf_baker_enabled'] === 1, 'second enable stays on (idempotent)');
    $rowCheck = $db->prepare('SELECT sf_baker_enabled FROM customers WHERE id = ?');
    $rowCheck->execute([$bridgeCustomer]);
    $assert((int)$rowCheck->fetchColumn() === 1, 'flag persisted for the bridge baker');

    // Flag flip is visible to the access gate via a simulated portal session.
    require_once __DIR__ . '/../includes/customer_portal.php';
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION['portal_customer_id'] = $bridgeCustomer;
    $sfbVisible = bakery_sfb_customer($db);
    $assert(is_array($sfbVisible) && (int)$sfbVisible['id'] === $bridgeCustomer,
        'enabled flag makes the baker visible to bakery_sfb_customer()');
    unset($_SESSION['portal_customer_id']);

    // Origin guard: a forced synthetic row is left completely alone.
    if (bakery_sfb_origin_column_ready($db)) {
        $insSyn = $db->prepare(
            'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active, sfb_origin)
             VALUES (?, ?, ?, 1, 0, 1, "synthetic")'
        );
        $insSyn->execute(['SFB Bridge Synthetic', '555-0179', '9 Gate Way']);
        $syntheticCustomer = (int)$db->lastInsertId();
        $assert(bakery_sfb_enable_for_customer($db, $syntheticCustomer) === null,
            'enable refuses a synthetic-origin baker');
        $synRow = $db->prepare('SELECT sf_baker_enabled, sfb_origin FROM customers WHERE id = ?');
        $synRow->execute([$syntheticCustomer]);
        $synState = $synRow->fetch();
        $assert($synState && (int)$synState['sf_baker_enabled'] === 0 && $synState['sfb_origin'] === 'synthetic',
            'synthetic row is left untouched (flag stays off, origin preserved)');
    } else {
        $assert(true, 'origin column absent: synthetic guard skipped (runtime-tolerant)');
    }

    // Education access is not an order: zero wholesale rows created.
    foreach ($wholesaleTables as $wt) {
        if ($countsBefore[$wt] < 0) {
            continue;
        }
        $countAfter = (int)$db->query("SELECT COUNT(*) FROM {$wt}")->fetchColumn();
        $assert($countAfter === $countsBefore[$wt], "no rows created in {$wt}");
    }
} catch (Throwable $e) {
    echo 'FAIL  unexpected (bridge): ' . $e->getMessage() . "\n";
    $fail++;
}

// Cleanup ------------------------------------------------------------------------
$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')->execute(['SFB Gate A', 'SFB Gate B']);
$db->prepare('DELETE FROM customers WHERE name = ?')->execute(['SFB Handoff Baker']);
$db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Gate Class']);
$db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Gate Course'))");
$db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title IN ('Gate Course', 'Handoff Course'))");
$db->prepare('DELETE FROM sfb_courses WHERE title IN (?, ?)')->execute(['Gate Course', 'Handoff Course']);
$db->prepare('DELETE FROM sfb_formulas WHERE name IN (?, ?)')->execute(['Handoff Standard', 'Baker Own Loaf']);
$db->prepare('DELETE FROM sfb_invites WHERE label IN (?, ?, ?)')
    ->execute(['Funnel Alpha', 'Funnel Beta', 'Funnel Gamma']);
$db->prepare('DELETE FROM customers WHERE name IN (?, ?, ?, ?)')->execute([
    'SFB Bridge Baker',
    'SFB Bridge Sleeping',
    'SFB Bridge Locked',
    'SFB Bridge Synthetic',
]);
$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')->execute(['SFB Funnel One', 'SFB Funnel Two']);
$db->prepare('DELETE FROM sfb_offerings WHERE title IN (?, ?)')->execute(['Revenue Class A', 'Revenue Class B']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
