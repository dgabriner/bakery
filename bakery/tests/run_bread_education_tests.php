<?php
/**
 * Bread Education Batch Builder tests (Prompt 23).
 * Covers: builder schema readiness, snapshot drift truth, phase-tagged batch
 * questions, resolved Q&A extraction, and formula remix with provenance.
 *
 * Runs against bakerysf_test. Never touches local/staging/live data.
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

// ---- Schema: migration 061 applied -----------------------------------------
$assert(bakery_sfb_builder_ready($db), '061 adds remixed_from_batch_id and message phase columns');
$assert(column_exists($db, 'sfb_formulas', 'remixed_from_batch_id'), 'sfb_formulas.remixed_from_batch_id exists');
$assert(column_exists($db, 'sfb_batch_messages', 'phase'), 'sfb_batch_messages.phase exists');

if (!bakery_sfb_builder_ready($db) || !bakery_sfb_community_ready($db)) {
    echo "NOTE  [blocker] run scripts/run_migrations.php against bakerysf_test first\n";
    echo "\n{$pass} passed, {$fail} failed\n";
    exit($fail > 0 ? 1 : 0);
}

// Deterministic fixture pre-clean (bug 4714 idempotency convention).
$db->prepare('DELETE FROM customers WHERE name IN (?, ?)')
    ->execute(['SFB Edu Customer A', 'SFB Edu Customer B']);
// Courses are global content rows; clean our named fixtures too.
$db->prepare('DELETE FROM sfb_courses WHERE title = ?')->execute(['First Loaf Course']);
// Demo seed rows (scripts/seed_education_demo.php) would break the exact
// course-count assertions below; sweep them like our own named fixtures.
$db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title LIKE 'Demo:%'))");
$db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title LIKE 'Demo:%')");
$db->exec("DELETE FROM sfb_courses WHERE title LIKE 'Demo:%'");
$db->exec('DELETE FROM sfb_invites WHERE label = "Saturday class"');
// Webhook ledger is global too; drop this suite's deterministic event ids.
$db->prepare('DELETE FROM square_webhook_events WHERE event_id LIKE ?')->execute(['edu-test-event-%']);
// Offerings are global as well; drop this suite's named fixtures.
$db->exec("DELETE FROM sfb_offerings WHERE title IN ('Edu Starter Workshop','Edu Credit Pack','Edu Donation')");

try {
    // ---- Fixture bakers --------------------------------------------------------
    $ins = $db->prepare(
        'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ins->execute(['SFB Edu Customer A', '555-0181', '1 Edu Way']);
    $customerA = (int)$db->lastInsertId();
    $ins->execute(['SFB Edu Customer B', '555-0182', '2 Edu Way']);
    $customerB = (int)$db->lastInsertId();
    $assert($customerA > 0 && $customerB > 0, 'fixture bakers created');

    // ---- Snapshot drift (pure function) ---------------------------------------
    $snap = [
        ['line_name' => 'Bread Flour', 'percentage' => 100.0],
        ['line_name' => 'Water', 'percentage' => 75.0],
        ['line_name' => 'Salt', 'percentage' => 2.0],
    ];
    $same = $snap;
    $drift = bakery_sfb_snapshot_drift($snap, $same);
    $assert(!$drift['drifted'], 'identical lines show no drift');

    $drift = bakery_sfb_snapshot_drift($snap, [
        ['line_name' => 'Bread Flour', 'percentage' => 100.0],
        ['line_name' => 'Water', 'percentage' => 80.0],
        ['line_name' => 'Salt', 'percentage' => 2.005],
    ]);
    $assert($drift['drifted'] && in_array('water', $drift['changed'], true), 'percentage change detected');
    $assert(!in_array('salt', $drift['changed'], true), 'sub-tolerance percentage change ignored');

    $drift = bakery_sfb_snapshot_drift($snap, array_merge($snap, [['line_name' => 'Rye Flour', 'percentage' => 20.0]]));
    $assert(in_array('rye flour', $drift['added'], true), 'added line detected');

    $drift = bakery_sfb_snapshot_drift($snap, [
        ['line_name' => 'Bread Flour', 'percentage' => 100.0],
        ['line_name' => 'Water', 'percentage' => 75.0],
    ]);
    $assert(in_array('salt', $drift['removed'], true), 'removed line detected');

    // ---- Phase-tagged questions -------------------------------------------------
    $template = bakery_sfb_template($db, 'Basic Sourdough');
    $assert($template !== null, 'Basic Sourdough template available');
    $formulaA = bakery_sfb_copy_template($db, $customerA, (int)$template['id']);
    $batchA = bakery_sfb_start_batch($db, $customerA, $formulaA, 'Edu Batch A', date('Y-m-d H:i:s'));
    $assert($batchA > 0, 'batch started with frozen snapshot');

    $msgId = bakery_sfb_add_batch_message(
        $db,
        $batchA,
        'baker',
        'Edu Baker A',
        'My dough feels slack after three folds — keep going?',
        'question',
        $customerA,
        null,
        null,
        'development'
    );
    $rows = bakery_sfb_batch_messages($db, $batchA);
    $phaseStored = null;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $msgId) {
            $phaseStored = $row['phase'];
        }
    }
    $assert($phaseStored === 'development', 'question stores its phase tag');

    try {
        bakery_sfb_add_batch_message($db, $batchA, 'baker', 'Edu Baker A', 'bad phase', 'comment', $customerA, null, null, 'midnight');
        $assert(false, 'unknown phase rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'unknown phase rejected');
    }

    // Unresolved question stays out of the worked examples.
    $assert(bakery_sfb_batch_resolved_qna($db, $batchA) === [], 'unresolved question not surfaced as Q&A');

    // Coach reply resolves the parent question.
    bakery_sfb_add_batch_message(
        $db,
        $batchA,
        'admin',
        'Sour Flour Coach',
        'Keep going — do one more coil fold and check again in 30 minutes.',
        'comment',
        null,
        null,
        $msgId
    );
    $qna = bakery_sfb_batch_resolved_qna($db, $batchA);
    $assert(count($qna) === 1, 'resolved question appears once');
    if (count($qna) === 1) {
        $assert(count($qna[0]['replies']) === 1, 'coach reply attached to the resolved question');
        $assert((int)$qna[0]['is_resolved'] === 1, 'root question marked resolved');
    }

    // ---- Remix a shared bake ----------------------------------------------------
    $formulaB = bakery_sfb_copy_template($db, $customerB, (int)$template['id']);
    $batchB = bakery_sfb_start_batch($db, $customerB, $formulaB, 'Shared Bake B', date('Y-m-d H:i:s'));
    bakery_sfb_share_batch($db, $customerB, $batchB);

    try {
        bakery_sfb_remix_shared_formula($db, $customerA, 999999);
        $assert(false, 'remix of unknown bake rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'remix of unknown bake rejected');
    }

    $unsharedBatch = bakery_sfb_start_batch($db, $customerB, $formulaB, 'Private Bake B', date('Y-m-d H:i:s'));

    try {
        bakery_sfb_remix_shared_formula($db, $customerA, $unsharedBatch);
        $assert(false, 'remix of unshared bake rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'remix of unshared bake rejected');
    }

    $remixedId = bakery_sfb_remix_shared_formula($db, $customerA, $batchB);
    $remixedFormula = bakery_sfb_formula($db, $customerA, $remixedId);
    $assert($remixedFormula !== null && (int)$remixedFormula['customer_id'] === $customerA, 'remixed formula owned by the remixer');
    $assert((int)$remixedFormula['remixed_from_batch_id'] === (int)$batchB, 'provenance points at the source bake');

    $starterA = bakery_sfb_default_starter($db, $customerA);
    if (!$starterA) {
        $starterRow = bakery_sfb_ensure_starter($db, $customerA);
        $starterA = $starterRow;
    }
    $remixLines = bakery_sfb_formula_lines($db, $remixedId);
    $starterMapped = false;
    $standardMatched = 0;
    foreach ($remixLines as $line) {
        if ($line['line_kind'] === 'starter') {
            $starterMapped = (int)$line['starter_id'] === (int)$starterA['id'];
        } else {
            $std = $db->prepare('SELECT customer_id FROM sfb_ingredients WHERE id = ?');
            $std->execute([(int)$line['ingredient_id']]);
            $owner = $std->fetchColumn();
            if ($owner === null) {
                $standardMatched++;
            }
        }
    }
    $assert($starterMapped, "original baker's starter replaced by the remixer's own");
    $assert($standardMatched >= 3, 'standard ingredients matched from the shared library');

    $grams = bakery_sfb_formula_grams($remixLines, 1000);
    $assert(round((float)$grams['total_pct'], 2) > 0.0 && (float)$grams['flour_g'] > 0.0, 'remixed formula still does baker math');

    // ---- Learning Center (Prompt 24) ---------------------------------------------
    $assert(bakery_sfb_learning_ready($db), '063 learning tables exist');

    // Media path safety and mime mapping.
    $assert(bakery_sfb_media_path_safe('2026/08/20260823_abc123.mp4'), 'normal media path accepted');
    $assert(!bakery_sfb_media_path_safe('../../.env'), 'traversal media path rejected');
    $assert(!bakery_sfb_media_path_safe('2026/08/evil.php'), 'non-media extension rejected');
    $assert(!bakery_sfb_media_path_safe(''), 'empty media path rejected');
    $assert(bakery_sfb_media_content_type('x/y/clip.mp4') === 'video/mp4', 'mp4 maps to video/mp4');
    $assert(bakery_sfb_media_content_type('shot.PNG') === 'image/png', 'png maps to image/png case-insensitively');
    $assert(bakery_sfb_media_content_type('thing.bin') === 'application/octet-stream', 'unknown extension falls back');
    $assert(strpos(bakery_sfb_media_url('2026/08/a b.png'), 'sfb_media.php?f=') !== false, 'media URL always goes through the gate');

    // Phase labels resolve through i18n keys for every composer phase.
    $assert(bakery_sfb_phase_label('starter') === 'Starter', 'starter phase resolves via key');
    $assert(bakery_sfb_phase_label('final') === 'Final', 'final phase resolves via key');
    $assert(bakery_sfb_phase_label('mix') === 'Mix', 'existing phase keys still resolve');

    try {
        bakery_sfb_save_education_media(['error' => UPLOAD_ERR_NO_FILE]);
        $assert(false, 'missing upload rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'missing upload rejected');
    }

    // Course → lesson → steps authoring.
    $courseId = bakery_sfb_create_course($db, 'First Loaf Course', 'From starter to slice.');
    $assert($courseId > 0, 'course created');
    try {
        bakery_sfb_create_course($db, '');
        $assert(false, 'empty course title rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'empty course title rejected');
    }
    $lessonOne = bakery_sfb_create_lesson($db, $courseId, 'Wake your starter', 'Feed it and wait for the peak.', '');
    $lessonTwo = bakery_sfb_create_lesson($db, $courseId, 'Mix and fold', '', 'https://bakery.sourflour.org/breadeducation/fresh-loaf.html');
    $assert($lessonOne > 0 && $lessonTwo > 0, 'lessons created in order');
    try {
        bakery_sfb_create_lesson($db, $courseId, 'Bad link', '', 'ftp://nope.example');
        $assert(false, 'non-http external url rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'non-http external url rejected');
    }

    $stepA = bakery_sfb_add_lesson_step($db, $lessonOne, 'Feed equal weights flour and water.', null, 'photo');
    $stepB = bakery_sfb_add_lesson_step($db, $lessonOne, 'Wait for the peak — domed top, sour-sweet smell.', null, 'photo');
    $stepC = bakery_sfb_add_lesson_step($db, $lessonTwo, 'Watch the folding motion:', null, 'photo');
    $assert($stepA > 0 && $stepB > 0 && $stepC > 0, 'steps appended with order');
    try {
        bakery_sfb_add_lesson_step($db, $lessonTwo, '', '');
        $assert(false, 'empty step rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'empty step rejected');
    }

    $stepsOne = bakery_sfb_lesson_steps($db, $lessonOne);
    $assert(count($stepsOne) === 2, 'lesson one holds two steps');
    bakery_sfb_move_lesson_step($db, $lessonOne, (int)$stepsOne[1]['id'], 'up');
    $reordered = array_values(bakery_sfb_lesson_steps($db, $lessonOne));
    $assert((int)$reordered[0]['id'] === (int)$stepsOne[1]['id'], 'step moved up swaps teaching order');

    // Progress checkmarks are per customer and idempotent.
    $doneNow = bakery_sfb_toggle_lesson_progress($db, $customerA, $lessonOne, (int)$stepsOne[1]['id']);
    $assert($doneNow === true, 'first toggle completes the step');
    $again = bakery_sfb_toggle_lesson_progress($db, $customerA, $lessonOne, (int)$stepsOne[1]['id']);
    $assert($again === false, 'second toggle reopens the step');
    bakery_sfb_toggle_lesson_progress($db, $customerA, $lessonOne, (int)$stepsOne[1]['id']);
    try {
        bakery_sfb_toggle_lesson_progress($db, $customerA, $lessonTwo, (int)$stepsOne[1]['id']);
        $assert(false, 'progress refuses step from another lesson');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'progress refuses step from another lesson');
    }

    [$doneCount, $totalCount] = bakery_sfb_course_progress($db, $customerA, $courseId);
    $assert([$doneCount, $totalCount] === [1, 3], 'course progress counts across lessons');

    // Bakers see only active content; admins see hidden rows too.
    $visibleCourses = bakery_sfb_courses($db);
    $allCourses = bakery_sfb_courses($db, true);
    bakery_sfb_toggle_course($db, $courseId);
    $afterHide = bakery_sfb_courses($db);
    $assert(count($visibleCourses) === 1 && count($afterHide) === 0 && count($allCourses) === 1, 'hidden course leaves the baker index but stays for staff');

    // ---- Home Base Onboarding (Prompt 25) ------------------------------------------
    $assert(bakery_sfb_invites_ready($db), '064 invites table exists');

    // A truly fresh baker: customer B already holds formulas from the remix tests.
    $db->prepare('DELETE FROM customers WHERE name = ?')->execute(['SFB Edu Customer C']);
    $ins->execute(['SFB Edu Customer C', '555-0183', '3 Edu Way']);
    $customerC = (int)$db->lastInsertId();

    // First-run actions for a brand-new baker: starter and formula pending.
    $freshActions = bakery_sfb_first_run_actions($db, $customerC);
    $starterAction = null;
    $formulaAction = null;
    $lessonAction = null;
    foreach ($freshActions as $action) {
        if ($action['key'] === 'starter') { $starterAction = $action; }
        if ($action['key'] === 'formula') { $formulaAction = $action; }
        if ($action['key'] === 'lesson') { $lessonAction = $action; }
    }
    $assert($starterAction !== null && $starterAction['done'] === false, 'new baker has a pending starter step');
    $assert($formulaAction !== null && $formulaAction['done'] === false, 'new baker has a pending formula step');
    $assert($lessonAction !== null && array_key_exists('lesson_id', $lessonAction), 'lesson step is present and course-optional');

    // Completing steps retires them from the strip.
    bakery_sfb_ensure_starter($db, $customerC);
    bakery_sfb_copy_template($db, $customerC, (int)$template['id']);
    $laterActions = bakery_sfb_first_run_actions($db, $customerC);
    foreach ($laterActions as $action) {
        if ($action['key'] === 'starter' || $action['key'] === 'formula') {
            $assert($action['done'], 'first-run step retires once done: ' . $action['key']);
        }
    }

    // The lesson step retires once any progress exists on it (Prompt 25 truth).
    bakery_sfb_toggle_course($db, $courseId); // bring the fixture course back
    $lessonActionBefore = null;
    foreach (bakery_sfb_first_run_actions($db, $customerC) as $action) {
        if ($action['key'] === 'lesson') {
            $lessonActionBefore = $action;
        }
    }
    $assert($lessonActionBefore !== null && $lessonActionBefore['done'] === false
        && (int)$lessonActionBefore['lesson_id'] > 0, 'lesson step pending while untouched');
    $stripLessonSteps = bakery_sfb_lesson_steps($db, (int)$lessonActionBefore['lesson_id']);
    bakery_sfb_toggle_lesson_progress(
        $db,
        $customerC,
        (int)$lessonActionBefore['lesson_id'],
        (int)$stripLessonSteps[0]['id']
    );
    $lessonActionAfter = null;
    foreach (bakery_sfb_first_run_actions($db, $customerC) as $action) {
        if ($action['key'] === 'lesson') {
            $lessonActionAfter = $action;
        }
    }
    $assert($lessonActionAfter !== null && $lessonActionAfter['done'] === true, 'lesson step retires once progress exists');

    // Invites: mint, normalize on lookup, claim exactly once.
    $invite = bakery_sfb_create_invite($db, 'share', 'Saturday class');
    $assert($invite !== null && strpos((string)$invite['code'], 'SFB-') === 0, 'invite minted with SFB- prefix');
    $messy = bakery_sfb_invite_lookup($db, strtolower(str_replace('-', '', (string)$invite['code'])) . ' ');
    $assert($messy !== null && (int)$messy['id'] === (int)$invite['id'], 'invite lookup normalizes case, dashes, spaces');
    $assert((string)$messy['intent'] === 'share' && (string)$messy['label'] === 'Saturday class', 'invite carries intent and label');
    $assert(bakery_sfb_invite_lookup($db, 'SFB-ZZZZZZ') === null, 'unknown invite code is null');

    $claimed = bakery_sfb_mark_invite_used($db, (int)$invite['id'], $customerB);
    $secondClaim = bakery_sfb_mark_invite_used($db, (int)$invite['id'], $customerA);
    $assert($claimed === true && $secondClaim === false, 'invite claims exactly one customer');
    $assert(bakery_sfb_invite_lookup($db, (string)$invite['code']) === null, 'used invite no longer resolves');

    try {
        bakery_sfb_create_invite($db, 'learn', str_repeat('x', 151));
        $assert(false, 'over-long invite label rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'over-long invite label rejected');
    }

    // ---- Education Payments (Prompt 26) ---------------------------------------------
    $assert(bakery_sfb_payments_ready($db), '066 offerings and purchases tables exist');

    try {
        bakery_sfb_create_offering($db, '', 10);
        $assert(false, 'empty offering title rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'empty offering title rejected');
    }
    try {
        bakery_sfb_create_offering($db, 'Bad price', -5);
        $assert(false, 'negative offering price rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'negative offering price rejected');
    }
    try {
        bakery_sfb_create_offering($db, 'Bad kind', 10, 'bootcamp');
        $assert(false, 'unknown offering kind rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'unknown offering kind rejected');
    }

    // Deterministic pre-clean of our global fixture rows.
    $db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Sourdough Start Class']);

    $offeringId = bakery_sfb_create_offering($db, 'Sourdough Start Class', 45.00, 'class', 'A three-hour hands-on class.', null);
    $assert($offeringId > 0, 'offering created with cents snapshot math');
    $offeringRow = bakery_sfb_offering($db, $offeringId);
    $assert((int)$offeringRow['price_cents'] === 4500, 'dollars stored as cents');

    // Recorded intent without Square: one row per attempt, never a paid state.
    $GLOBALS['bakery_sfb_payments_disabled'] = true;
    $buyOne = bakery_sfb_buy_offering($db, $customerB, $offeringId);
    $buyTwo = bakery_sfb_buy_offering($db, $customerB, $offeringId);
    unset($GLOBALS['bakery_sfb_payments_disabled']);
    $assert($buyOne['configured'] === false && $buyTwo['configured'] === false, 'no-credentials buy records honest intents');
    $assert($buyOne['purchase_id'] !== $buyTwo['purchase_id'], 'each attempt leaves its own row');
    $attemptOne = bakery_sfb_purchase($db, $buyOne['purchase_id']);
    $assert((string)$attemptOne['status'] === 'intent', 'attempt stays intent without checkout');
    $assert((int)$attemptOne['price_cents_snapshot'] === 4500 && (string)$attemptOne['offering_title_snapshot'] === 'Sourdough Start Class', 'intent freezes title and price');

    // Mocked checkout: intent -> pending with a hosted link.
    // Square's real create/retrieve response carries payment_link.order_id
    // (singular) — proven against the sandbox API on staging 2026-08-24.
    $GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null): array {
        return ['payment_link' => [
            'id' => 'PL-TEST-1',
            'url' => 'https://sandbox.square.link/u/test-checkout',
            'order_id' => 'ORDER-TEST-1',
        ]];
    };
    $checkout = bakery_sfb_create_purchase_checkout($db, $buyOne['purchase_id']);
    $pending = bakery_sfb_purchase($db, $buyOne['purchase_id']);
    $assert(strpos($checkout['url'], 'square.link') !== false, 'mocked checkout returns hosted link');
    $assert((string)$pending['status'] === 'pending' && (string)$pending['square_order_id'] === 'ORDER-TEST-1', 'checkout moves attempt to pending with order id');
    unset($GLOBALS['bakery_square_api_handler']);

    // Webhook truth: payment.completed flips to paid exactly once.
    $payload = [
        'event_id' => 'edu-test-event-1',
        'type' => 'payment.updated',
        'data' => ['object' => ['payment' => [
            'id' => 'PAY-TEST-1',
            'order_id' => 'ORDER-TEST-1',
            'status' => 'COMPLETED',
        ]]],
    ];
    $firstPass = bakery_sfb_handle_education_webhook($db, $payload);
    $replay = bakery_sfb_handle_education_webhook($db, $payload);
    $assert(($firstPass['changed'] ?? false) === true && ($replay['duplicate'] ?? false) === true, 'webhook applies once; replay dedupes on event id');
    $paid = bakery_sfb_purchase($db, $buyOne['purchase_id']);
    $assert((string)$paid['status'] === 'paid' && (string)$paid['square_payment_id'] === 'PAY-TEST-1', 'completed payment marks purchase paid');

    // Entitlements follow paid state and die on refund.
    $assert(bakery_sfb_customer_entitled_to($db, $customerB, $offeringId), 'paid purchase grants entitlement');
    $entitlements = bakery_sfb_customer_entitlements($db, $customerB);
    $assert(count($entitlements) === 1, 'entitlement set holds one row');
    $refundPayload = [
        'event_id' => 'edu-test-event-2',
        'type' => 'refund.updated',
        'data' => ['object' => ['refund' => ['payment_id' => 'PAY-TEST-1', 'status' => 'COMPLETED']]],
    ];
    bakery_sfb_handle_education_webhook($db, $refundPayload);
    $refunded = bakery_sfb_purchase($db, $buyOne['purchase_id']);
    $assert((string)$refunded['status'] === 'refunded', 'refund event flips paid to refunded');
    $assert(!bakery_sfb_customer_entitled_to($db, $customerB, $offeringId), 'refunded purchase loses entitlement');

    // Guard rails: unknown statuses rejected; refund of unpaid refused.
    try {
        bakery_sfb_set_purchase_status($db, $buyTwo['purchase_id'], 'shipped');
        $assert(false, 'unknown status rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'unknown status rejected');
    }
    $illegalRefund = bakery_sfb_set_purchase_status($db, $buyTwo['purchase_id'], 'refunded');
    $assert($illegalRefund === false, 'refund from non-paid state refused');

    // Unknown/unmatched webhook events are ignored, never guessed onto purchases.
    $ignored = bakery_sfb_handle_education_webhook($db, [
        'event_id' => 'edu-test-event-3',
        'type' => 'payment.updated',
        'data' => ['object' => ['payment' => ['id' => 'PAY-NOWHERE', 'order_id' => 'ORDER-NOWHERE', 'status' => 'COMPLETED']]],
    ]);
    $assert(($ignored['unmatched'] ?? false) === true, 'unmatched payment ignored');

    // ---- Offerings v2: donations, credits, Starter Workshop (067) -------------------
    try {
        bakery_sfb_create_offering($db, 'Sourdough Start Class', 10);
        $assert(false, 'duplicate offering title rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'duplicate offering title rejected');
    }

    $workshopId = bakery_sfb_create_offering($db, 'Edu Starter Workshop', 80.00, 'class', 'Hands-on starter class.');
    $packId = bakery_sfb_create_offering($db, 'Edu Credit Pack', 60.00, 'credits', 'Four credits.', null, 4);
    $donateId = bakery_sfb_create_offering($db, 'Edu Donation', 25.00, 'donation', 'Keep classes free.');
    $assert($workshopId > 0 && $packId > 0 && $donateId > 0, 'class, credits, and donation kinds accepted');
    $packRow = bakery_sfb_offering($db, $packId);
    $assert((int)$packRow['units'] === 4, 'credit pack stores its units');
    try {
        bakery_sfb_create_offering($db, 'Broken Pack', 10.00, 'credits', '', null, null);
        $assert(false, 'credit pack without units rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'credit pack without units rejected');
    }

    // Buy a credit pack with mocked Square; webhook truth grants units once.
    $GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null): array {
        return ['payment_link' => ['id' => 'PL-PACK-1', 'url' => 'https://sandbox.square.link/u/pack', 'order_ids' => ['ORDER-PACK-1']]];
    };
    $packBuy = bakery_sfb_buy_offering($db, $customerB, $packId);
    unset($GLOBALS['bakery_square_api_handler']);
    $assert(($packBuy['configured'] ?? false) === true, 'credit pack checkout created');
    $packPurchase = bakery_sfb_purchase($db, $packBuy['purchase_id']);
    $assert((string)$packPurchase['status'] === 'pending', 'credit pack attempt pending after checkout');
    bakery_sfb_handle_education_webhook($db, [
        'event_id' => 'edu-test-event-pack',
        'type' => 'payment.updated',
        'data' => ['object' => ['payment' => ['id' => 'PAY-PACK-1', 'order_id' => 'ORDER-PACK-1', 'status' => 'COMPLETED']]],
    ]);
    $paidPack = bakery_sfb_purchase($db, $packBuy['purchase_id']);
    $assert((string)$paidPack['paid_with'] === 'square', 'square channel stamped on pack purchase');
    $assert(bakery_sfb_credit_balance($db, $customerB) === 4, 'webhook grant gives four credits');

    // Replay must not double-grant.
    bakery_sfb_handle_education_webhook($db, ['event_id' => 'edu-test-event-pack-2', 'type' => 'payment.updated',
        'data' => ['object' => ['payment' => ['id' => 'PAY-PACK-1', 'order_id' => 'ORDER-PACK-1', 'status' => 'COMPLETED']]]]);
    // (different event id so dedupe passes; grant dedupe must hold)
    $assert(bakery_sfb_credit_balance($db, $customerB) === 4, 'grant replay does not double-issue credits');

    // Spend one credit on the workshop.
    $spendId = bakery_sfb_pay_with_credit($db, $customerB, $workshopId);
    $spentPurchase = bakery_sfb_purchase($db, $spendId);
    $assert((string)$spentPurchase['status'] === 'paid' && (string)$spentPurchase['paid_with'] === 'credit', 'credit spend marks purchase paid with credit channel');
    $assert(bakery_sfb_credit_balance($db, $customerB) === 3, 'credit balance drops by one');
    $assert(bakery_sfb_customer_entitled_to($db, $customerB, $workshopId), 'credit-paid purchase grants entitlement');

    // Credits never buy credits or donations.
    try {
        bakery_sfb_pay_with_credit($db, $customerB, $donateId);
        $assert(false, 'credit spend on donation rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'credit spend on donation rejected');
    }
    try {
        bakery_sfb_pay_with_credit($db, $customerA, $workshopId);
        $assert(false, 'credit spend without balance rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'credit spend without balance rejected');
    }

    // Manual staff recording stamps its own channel.
    $manualBuy = bakery_sfb_buy_offering($db, $customerB, $donateId);
    $GLOBALS['bakery_square_api_handler'] = static function (string $method, string $path, ?array $body = null): array {
        return ['payment_link' => ['id' => 'PL-DON-1', 'url' => 'https://sandbox.square.link/u/don', 'order_ids' => ['ORDER-DON-1']]];
    };
    unset($GLOBALS['bakery_square_api_handler']);
    bakery_sfb_set_purchase_status($db, $manualBuy['purchase_id'], 'canceled');
    $rebuy = bakery_sfb_buy_offering($db, $customerB, $donateId);
    bakery_sfb_set_purchase_status($db, $rebuy['purchase_id'], 'paid', null, 'cash at class', 0, 'manual');
    $manualRow = bakery_sfb_purchase($db, $rebuy['purchase_id']);
    $assert((string)$manualRow['paid_with'] === 'manual', 'manual recording stamps manual channel');
    $assert((string)$manualRow['status'] === 'paid', 'manual mark reaches paid');

    // ---- Course gating lifecycle (migration 068 / bug 6835) ------------------------
    // Deterministic pre-clean of our global gate fixtures.
    $db->prepare('DELETE FROM customers WHERE name = ?')->execute(['SFB Edu Gate Stranger']);
    $db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Edu Gate Class']);
    $db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Edu Gate Course'))");
    $db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Edu Gate Course')");
    $db->prepare('DELETE FROM sfb_courses WHERE title = ?')->execute(['Edu Gate Course']);

    if (bakery_sfb_gating_ready($db)) {
        try {
            $insGate = $db->prepare(
                'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
                 VALUES (?, ?, ?, 1, 1, 1)'
            );
            $insGate->execute(['SFB Edu Gate Stranger', '555-0184', '4 Edu Way']);
            $gateStranger = (int)$db->lastInsertId();

            // Legacy shape: a course with no required offering stays free.
            $gateCourseId = bakery_sfb_create_course($db, 'Edu Gate Course', 'Gating lifecycle fixture.');
            $legacyLock = bakery_sfb_course_lock($db, $gateStranger, bakery_sfb_course($db, $gateCourseId));
            $assert($legacyLock['locked'] === false && $legacyLock['offering'] === null, 'no-offering legacy course stays free');

            $gateLessonId = bakery_sfb_create_lesson($db, $gateCourseId, 'Gate Lesson One', '', '');
            bakery_sfb_add_lesson_step($db, $gateLessonId, 'Watch the shaping:', 'edu-gate/2026/shaping.mp4', 'video');
            $gateOfferingId = bakery_sfb_create_offering($db, 'Edu Gate Class', 45.00, 'class', 'Gating lifecycle class.', null);

            // Assigning the gate locks non-entitled bakers and their media.
            bakery_sfb_set_course_offering($db, $gateCourseId, $gateOfferingId);
            $gatedCourseRow = bakery_sfb_course($db, $gateCourseId);
            $strangerLock = bakery_sfb_course_lock($db, $gateStranger, $gatedCourseRow);
            $assert($strangerLock['locked'] === true && (int)$strangerLock['offering']['id'] === $gateOfferingId,
                'unentitled baker sees the paywall lock with the offering attached');
            $assert(bakery_sfb_media_path_locked($db, 'edu-gate/2026/shaping.mp4', $gateStranger) === true,
                'unentitled baker has lesson media reverse-locked (no content leak)');
            $assert(count(bakery_sfb_courses_requiring($db, $gateOfferingId)) === 1,
                'unlock list shows exactly the gated course');

            // Paying unlocks: intent -> paid flips entitlement and opens everything.
            $gatePurchaseId = bakery_sfb_record_purchase_intent($db, $customerC, $gateOfferingId);
            $prePaidLock = bakery_sfb_course_lock($db, $customerC, $gatedCourseRow);
            $assert($prePaidLock['locked'] === true, 'intent-only attempt stays locked');
            bakery_sfb_set_purchase_status($db, $gatePurchaseId, 'paid', null, 'gating fixture', null);
            $paidLock = bakery_sfb_course_lock($db, $customerC, $gatedCourseRow);
            $assert($paidLock['locked'] === false && $paidLock['offering'] !== null, 'entitled customer passes the lock');
            $assert(bakery_sfb_media_path_locked($db, 'edu-gate/2026/shaping.mp4', $customerC) === false,
                'entitled customer unlocks lesson media');

            // Detaching the offering returns the course to free for everyone.
            bakery_sfb_set_course_offering($db, $gateCourseId, 0);
            $freedGateCourse = bakery_sfb_course($db, $gateCourseId);
            $assert(bakery_sfb_course_lock($db, $gateStranger, $freedGateCourse)['locked'] === false,
                'offering detach returns the course to free');
            $assert(bakery_sfb_media_path_locked($db, 'edu-gate/2026/shaping.mp4', $gateStranger) === false,
                'detached-course media is free too');
        } finally {
            $db->prepare('DELETE FROM customers WHERE name = ?')->execute(['SFB Edu Gate Stranger']);
            $db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Edu Gate Class']);
            $db->exec("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Edu Gate Course'))");
            $db->exec("DELETE FROM sfb_course_lessons WHERE course_id IN (SELECT id FROM sfb_courses WHERE title = 'Edu Gate Course')");
            $db->prepare('DELETE FROM sfb_courses WHERE title = ?')->execute(['Edu Gate Course']);
        }
    } else {
        echo "NOTE  [skip] gating column not applied; migration 068 lifecycle asserts skipped\n";
    }

    // ---- Cleanup ----------------------------------------------------------------
    $db->prepare('DELETE FROM customers WHERE id IN (?, ?, ?)')->execute([$customerA, $customerB, $customerC]);
    $db->prepare('DELETE FROM sfb_offerings WHERE title IN (?, ?, ?)')
        ->execute(['Edu Starter Workshop', 'Edu Credit Pack', 'Edu Donation']);
    $db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute(['Sourdough Start Class']);
} catch (Throwable $e) {
    echo 'FAIL  unexpected: ' . $e->getMessage() . "\n";
    $fail++;
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
