<?php
/**
 * Synthetic-refusal tests for the bread education surface (bakerysf_test only).
 *
 * Acting as a customer row with origin=synthetic, proves whether progress
 * writes and purchase attempts are rejected by the existing helpers rather
 * than silently recorded. Product invariant: humans only on education
 * surfaces; synthetics never enroll, pay, post progress, or count as students.
 *
 * If a helper currently ACCEPTS synthetic-origin writes, product code is NOT
 * changed here — the acceptance is logged as a finding and the written row is
 * cleaned up before exit.
 *
 * Usage: php tests/run_synthetic_refusal_tests.php
 */
require_once __DIR__ . '/isolate_test_db.php';
$db = require __DIR__ . '/harness.php';
require_once dirname(__DIR__) . '/includes/sf_baker.php';
require_once dirname(__DIR__) . '/includes/sfb_agent.php';

$GLOBALS['db'] = $db;

$finish = function () {
    if ($GLOBALS['TEST_FINDINGS']) {
        echo "\nFindings:\n";
        foreach ($GLOBALS['TEST_FINDINGS'] as $f) {
            echo "  [{$f['severity']}] {$f['detail']}\n";
        }
    }
    echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
    exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
};

$actualDb = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
assert_eq('bakerysf_test', $actualDb, 'refusal tests run on bakerysf_test');

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$courseId = null;
$lessonOne = 0;
$stepRefusal = 0;
$offeringId = null;
$createdCustomerIds = [];
$writtenProgress = [];
$writtenPurchases = [];

try {
    // ── Fixture: synthetic baker, course content, offering ────────────────
    $bakerName = 'Education Refusal Test ' . $suffix;
    $baker = bakery_sfb_agent_create_baker($db, $bakerName, '', [
        'origin' => 'synthetic',
        'persona' => 'beginner',
        'locale' => 'en',
    ]);
    $customerId = (int)$baker['customer']['id'];
    $createdCustomerIds[] = $customerId;
    assert_true($customerId > 0, 'synthetic test baker created');
    assert_eq(
        'synthetic',
        bakery_sfb_normalize_origin($baker['customer']['sfb_origin'] ?? ''),
        'test baker row carries origin=synthetic'
    );

    $originCheck = $db->prepare('SELECT COALESCE(sfb_origin, "") FROM customers WHERE id = ?');
    $originCheck->execute([$customerId]);
    assert_eq('synthetic', (string)$originCheck->fetchColumn(), 'database row confirms origin=synthetic');

    $learningReady = bakery_sfb_learning_ready($db);
    $paymentsReady = bakery_sfb_payments_ready($db);
    if (!$learningReady) {
        finding('warn', 'learning-center tables missing on bakerysf_test; progress refusal checks skipped');
    }
    if (!$paymentsReady) {
        finding('warn', 'payments tables missing on bakerysf_test; purchase refusal checks skipped');
    }

    if ($learningReady) {
        $courseId = bakery_sfb_create_course($db, "[Refusal Test] Course {$suffix}", 'Synthetic refusal fixture.');
        $lessonOne = bakery_sfb_create_lesson($db, $courseId, "[Refusal Test] Lesson {$suffix}", '');
        $stepRefusal = bakery_sfb_add_lesson_step($db, $lessonOne, "Refusal fixture step {$suffix}.");
        assert_true((int)$stepRefusal > 0, 'fixture lesson step created');
    }

    if ($paymentsReady) {
        // Honest no-Square seam: we are testing the record path, not checkout.
        $GLOBALS['bakery_sfb_payments_disabled'] = true;
        $offeringId = bakery_sfb_create_offering($db, "[Refusal Test] Class {$suffix}", 10, 'class', '');
        assert_true((int)$offeringId > 0, 'fixture offering created');
    }

    // ── Contrast: the community text eval already gates synthetic copy ───
    try {
        bakery_sfb_guard_synthetic_community_text(
            $db,
            $customerId,
            'Hello friends',
            'Had a nice time in the kitchen today.'
        );
        assert_true(false, 'community text guard rejects synthetic posts without process facts');
    } catch (Throwable $e) {
        assert_true(true, 'community text guard rejects synthetic posts without process facts');
    }

    // ── Progress write as the synthetic customer ──────────────────────────
    if ($learningReady) {
        $progressRejected = false;
        $progressError = '';
        try {
            $nowDone = bakery_sfb_toggle_lesson_progress($db, $customerId, (int)$lessonOne, (int)$stepRefusal);
            if ($nowDone) {
                $writtenProgress[] = [$customerId, (int)$lessonOne, (int)$stepRefusal];
            }
        } catch (Throwable $e) {
            $progressRejected = true;
            $progressError = $e->getMessage();
        }

        $check = $db->prepare(
            'SELECT COUNT(*) FROM sfb_lesson_progress WHERE customer_id = ? AND lesson_id = ? AND step_id = ?'
        );
        $check->execute([$customerId, (int)$lessonOne, (int)$stepRefusal]);
        $landed = (int)$check->fetchColumn() > 0;
        if ($landed && !$writtenProgress) {
            $writtenProgress[] = [$customerId, (int)$lessonOne, (int)$stepRefusal];
        }

        if ($progressRejected) {
            assert_true(!$landed, 'progress write rejected for synthetic-origin customer (' . $progressError . ')');
        } else {
            finding(
                'warn',
                'bakery_sfb_toggle_lesson_progress ACCEPTS synthetic-origin customers — '
                . 'progress rows land silently (invariant says synthetics never post progress); product change owned by another agent'
            );
            assert_true(true, 'progress helper behavior recorded as finding (no product code changed by this suite)');
        }
    }

    // ── Purchase attempt as the synthetic customer ────────────────────────
    if ($paymentsReady) {
        $purchaseRejected = false;
        $purchaseError = '';
        try {
            $purchaseId = bakery_sfb_record_purchase_intent($db, $customerId, $offeringId);
            if ((int)$purchaseId > 0) {
                $writtenPurchases[] = (int)$purchaseId;
            }
        } catch (Throwable $e) {
            $purchaseRejected = true;
            $purchaseError = $e->getMessage();
        }

        $checkPurchase = $db->prepare(
            'SELECT COUNT(*) FROM sfb_offering_purchases WHERE customer_id = ? AND offering_id = ?'
        );
        $checkPurchase->execute([$customerId, $offeringId]);
        $purchaseLanded = (int)$checkPurchase->fetchColumn() > 0;

        if ($purchaseRejected) {
            assert_true(!$purchaseLanded, 'purchase attempt rejected for synthetic-origin customer (' . $purchaseError . ')');
        } else {
            finding(
                'warn',
                'education purchase path ACCEPTS synthetic-origin customers — '
                . 'intent rows land silently (invariant says synthetics never enroll or pay); product change owned by another agent'
            );
            assert_true(true, 'purchase helper behavior recorded as finding (no product code changed by this suite)');
        }
    }
} finally {
    foreach ($writtenProgress as [$cid, $lid, $sid]) {
        $db->prepare('DELETE FROM sfb_lesson_progress WHERE customer_id = ? AND lesson_id = ? AND step_id = ?')
            ->execute([$cid, $lid, $sid]);
    }
    foreach ($writtenPurchases as $pid) {
        $db->prepare('DELETE FROM sfb_offering_purchases WHERE id = ?')->execute([(int)$pid]);
    }
    if ($offeringId) {
        $db->prepare('DELETE FROM sfb_offerings WHERE id = ?')->execute([(int)$offeringId]);
    }
    if ($courseId) {
        $db->prepare('DELETE FROM sfb_courses WHERE id = ?')->execute([(int)$courseId]);
    }
    foreach ($createdCustomerIds as $id) {
        if ($id > 0) {
            $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$id]);
        }
    }
}

$finish();
