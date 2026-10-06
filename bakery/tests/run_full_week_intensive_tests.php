<?php
/**
 * Full Week Intensive: enrollment, course access, three linked bakes,
 * checkpoints on the existing batch thread, and a customer view that
 * does not carry staff notes.
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
require_once __DIR__ . '/../includes/customer_notifications.php';

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

$names = ['SFB Intensive A', 'SFB Intensive B', 'SFB Intensive Synthetic'];
$db->prepare('DELETE FROM customers WHERE name IN (?, ?, ?)')->execute($names);

$assert(bakery_sfb_intensive_ready($db), '083 intensive tables exist');
if (!bakery_sfb_intensive_ready($db)) {
    echo "FAIL  migration 083 is not on bakerysf_test\n";
    exit(1);
}

$program = bakery_sfb_intensive_ensure_catalog($db);
$assert(is_array($program) && (int)$program['offering_id'] > 0 && (int)$program['course_id'] > 0, 'catalog creates the class, course, and program');
$assert((int)$program['coaching_days'] === 7, 'coaching window defaults to 7 days');
$assert($program['material_access_days'] === null, 'lesson access stays open by default');

$offering = bakery_sfb_offering($db, (int)$program['offering_id']);
$assert($offering && (int)$offering['price_cents'] === 30000 && (string)$offering['kind'] === 'class', 'shop class is $300');
$course = bakery_sfb_course($db, (int)$program['course_id']);
$assert($course && (int)$course['required_offering_id'] === (int)$program['offering_id'], 'course is gated to the Intensive class');
$assert(count(bakery_sfb_course_lessons($db, (int)$program['course_id'])) >= 7, 'lesson spine is seeded');

$ins = $db->prepare(
    'INSERT INTO customers (name, phone, address, portal_enabled, sf_baker_enabled, is_active)
     VALUES (?, ?, ?, 1, 1, 1)'
);
$ins->execute(['SFB Intensive A', '555-0191', '1 Intensive Way']);
$customerA = (int)$db->lastInsertId();
$ins->execute(['SFB Intensive B', '555-0192', '2 Intensive Way']);
$customerB = (int)$db->lastInsertId();

$locked = bakery_sfb_course_lock($db, $customerA, $course);
$assert($locked['locked'] === true, 'a baker without the Intensive cannot open the lessons');

$enrollmentId = bakery_sfb_intensive_enroll($db, $customerA, (int)$program['id'], [
    'start_date' => date('Y-m-d'),
    'internal_note' => 'SECRET-NOTE-TOKEN staff only',
]);
$assert($enrollmentId > 0, 'staff can enroll without a new purchase');
$slots = bakery_sfb_intensive_slots($db, $enrollmentId);
$assert(count($slots) === 3, 'enrollment opens Batch 1, 2, and 3');
$assert(bakery_sfb_course_lock($db, $customerA, $course)['locked'] === false, 'enrollment opens the lessons without a purchase row');

$again = false;
try {
    bakery_sfb_intensive_enroll($db, $customerA, (int)$program['id'], []);
} catch (InvalidArgumentException $e) {
    $again = true;
}
$assert($again, 'a second open Intensive is refused');

if (function_exists('bakery_sfb_origin_column_ready') && bakery_sfb_origin_column_ready($db)) {
    $ins->execute(['SFB Intensive Synthetic', '555-0193', '3 Intensive Way']);
    $syntheticId = (int)$db->lastInsertId();
    $db->prepare('UPDATE customers SET sfb_origin = "synthetic" WHERE id = ?')->execute([$syntheticId]);
    $refused = false;
    try {
        bakery_sfb_intensive_enroll($db, $syntheticId, (int)$program['id'], []);
    } catch (InvalidArgumentException $e) {
        $refused = true;
    }
    $assert($refused, 'synthetic bakers cannot enroll');
}

$enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
$view = bakery_sfb_intensive_present($db, $enrollment, false);
$encoded = json_encode($view);
$assert(is_string($encoded) && strpos($encoded, 'SECRET-NOTE-TOKEN') === false, 'customer view hides the private note');
$assert(!array_key_exists('internal_notes', $view) && !array_key_exists('status', $view) && !array_key_exists('purchase_id', $view), 'customer view has no staff fields');
$assert($view['next_key'] === 'start_bake' && $view['instruction'] !== '', 'the first step is the loaf');
$assert(!empty($view['formula']['lines']), 'a formula is on the loaf before anyone assigns one');
$customerPage = (string)file_get_contents(dirname(__DIR__) . '/sfb_intensive.php');
$assert(strpos($customerPage, 'internal_notes') === false && strpos($customerPage, 'purchase_id') === false, 'customer page does not render staff fields');
$assert(strpos($customerPage, 'sfb.intensive_goal_optional') === false, 'the loaf question is gone');
$assert(strpos($customerPage, 'sfb.intensive_ask') === false, 'the ask box is gone');
$assert(strpos($customerPage, 'sfb.intensive_thermometer') !== false, 'temperature stays behind a disclosure');
$assert(strpos($customerPage, 'sfb.intensive_continue') !== false, 'the loaf has a continue action');
$assert(strpos($customerPage, 'sfb.intensive_add_ingredient') !== false, 'the formula can take another ingredient');
$assert(strpos($customerPage, 'css/sfb_workshop.css') !== false && strpos($customerPage, 'ws-dock') !== false, 'the workshop stays usable on a phone and a computer');
$bakePanel = (string)file_get_contents(dirname(__DIR__) . '/includes/sfb_workshop_bake.php');
$assert(strpos($customerPage, 'sfb_workshop_bake.php') !== false, 'the workshop keeps the bake record');
$assert(strpos($bakePanel, 'save_mix') !== false && strpos($bakePanel, 'upload_photo') !== false && strpos($bakePanel, 'add_discussion') !== false, 'timings, photos, and notes stay on the loaf');

bakery_sfb_intensive_save_intake($db, $customerA, [
    'primary_goal' => 'Improve oven spring',
    'experience_level' => 'few',
    'customer_background' => 'Loaves spread flat.',
    'availability' => 'Tuesday and Saturday evenings',
    'room_temperature' => '70F',
    'oven_type' => 'home oven',
    'bake_vessel' => 'dutch',
    'flour_used' => 'bread flour',
]);
$afterIntake = bakery_sfb_intensive_enrollment($db, $enrollmentId);
$assert((string)$afterIntake['status'] === 'awaiting_staff' && (string)$afterIntake['primary_goal'] === 'Improve oven spring', 'setup lands with staff');

$template = bakery_sfb_template($db, 'Basic Sourdough');
$assert(is_array($template), 'a standard formula exists to assign');
$slot1 = $slots[0];
$formulaId = bakery_sfb_intensive_assign_formula($db, (int)$slot1['id'], (int)$template['id']);
$owned = bakery_sfb_formula($db, $customerA, $formulaId);
$assert($owned && (int)$owned['customer_id'] === $customerA, 'assigning a standard formula copies it into the journal');
bakery_sfb_intensive_release_slot($db, (int)$slot1['id'], 'Watch how far the dough grows before shaping.');
$batchId = bakery_sfb_intensive_start_next_bake($db, $customerA);
$linked = bakery_sfb_intensive_slots($db, $enrollmentId)[0];
$assert((int)$linked['batch_id'] === $batchId && (string)$linked['status'] === 'in_progress', 'Batch 1 is a normal journal bake');
$context = bakery_sfb_intensive_batch_context($db, $customerA, $batchId);
$assert($context && (int)$context['sequence'] === 1, 'the bake carries Batch 1 of 3');

$lines = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
$assert(count($lines) > 0, 'the bake keeps a formula snapshot');
$db->prepare('UPDATE sfb_formula_ingredients SET percentage = percentage + 1 WHERE formula_id = ? LIMIT 1')->execute([$formulaId]);
$afterEdit = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
$assert($lines == $afterEdit, 'a direct formula edit does not rewrite a finished record');
$formulaLines = bakery_sfb_formula_lines($db, $formulaId);
$liveLine = null;
$flourLine = null;
foreach ($formulaLines as $candidate) {
    if ((string)$candidate['line_kind'] === 'flour' && $flourLine === null) {
        $flourLine = $candidate;
    }
    if ((string)$candidate['line_kind'] !== 'flour' && $liveLine === null) {
        $liveLine = $candidate;
    }
}
$assert($liveLine !== null && $flourLine !== null, 'the loaf formula has flour and another ingredient');
bakery_sfb_intensive_save_working_formula($db, $customerA, [(int)$liveLine['id'] => 55, (int)$flourLine['id'] => 40]);
$liveSnap = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
$foundLive = false;
foreach ($liveSnap as $snapLine) {
    if ((string)$snapLine['line_name'] === (string)$liveLine['line_name'] && abs((float)$snapLine['percentage'] - 55.0) < 0.01) {
        $foundLive = true;
    }
}
$assert($foundLive, 'saving the shared formula updates the loaf still in progress');
$flourStill = 0.0;
foreach (bakery_sfb_formula_lines($db, $formulaId) as $row) {
    if ((int)$row['id'] === (int)$flourLine['id']) {
        $flourStill = (float)$row['percentage'];
    }
}
$assert(abs($flourStill - 100.0) < 0.01, 'a single flour stays at 100 percent');
bakery_sfb_intensive_add_formula_line($db, $customerA, 'Whole Wheat Flour', 'flour', 30);
$flourSum = 0.0;
$flourN = 0;
foreach (bakery_sfb_formula_lines($db, $formulaId) as $row) {
    if ((string)$row['line_kind'] === 'flour') {
        $flourSum += (float)$row['percentage'];
        $flourN++;
    }
}
$assert($flourN === 2 && abs($flourSum - 100.0) < 0.05, 'two flours add up to 100 percent');
bakery_sfb_intensive_add_formula_line($db, $customerA, 'Honey', 'other', 5);
$hasHoney = false;
foreach (bakery_sfb_formula_lines($db, $formulaId) as $row) {
    if ((string)$row['line_name'] === 'Honey') {
        $hasHoney = true;
    }
}
$assert($hasHoney, 'another ingredient can be added');
if (column_exists($db, 'sfb_intensive_slots', 'workshop_step')) {
    bakery_sfb_intensive_advance_workshop($db, $customerA);
    $stepped = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
    $assert(($stepped['step'] ?? '') === 'mix', 'continue leaves the formula for the mix');
    bakery_sfb_intensive_set_workshop_step($db, $customerA, 'formula');
    bakery_sfb_intensive_record_bake($db, $customerA, 'save_mix', [
        'mix_minutes' => 8,
        'mix_speed' => 'hands',
        'mix_notes' => 'shaggy',
    ], [], 'SFB Intensive A');
    $mixed = bakery_sfb_batch($db, $customerA, $batchId);
    $assert((int)$mixed['mix_minutes'] === 8 && (string)$mixed['mix_notes'] === 'shaggy', 'mix timing stays on the loaf');
    bakery_sfb_intensive_record_bake($db, $customerA, 'add_discussion', [
        'body' => 'Is the dough too wet?',
        'message_type' => 'question',
        'phase' => 'mix',
    ], [], 'SFB Intensive A');
    $foundQuestion = false;
    foreach (bakery_sfb_batch_messages($db, $batchId) as $message) {
        if ((string)$message['body'] === 'Is the dough too wet?' && (string)$message['message_type'] === 'question') {
            $foundQuestion = true;
        }
    }
    $assert($foundQuestion, 'a question stays on the loaf');
} else {
    $assert(false, '085 workshop_step column exists');
}
if (column_exists($db, 'sfb_intensive_slots', 'dough_temp_f')) {
    bakery_sfb_intensive_save_dough_temp($db, $customerA, '78.5');
    $withTemp = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
    $assert(abs((float)$withTemp['dough_temp_f'] - 78.5) < 0.01, 'dough temperature can be saved and edited');
    bakery_sfb_intensive_save_dough_temp($db, $customerA, '');
    $cleared = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
    $assert($cleared['dough_temp_f'] === '', 'dough temperature can be cleared');
} else {
    $assert(false, '084 dough_temp_f column exists');
}

$checkpointId = bakery_sfb_intensive_request_checkpoint(
    $db,
    (int)$slot1['id'],
    'bulk',
    'When the dough looks halfway through bulk, add a photo.',
    0,
    'Danny'
);
$messages = bakery_sfb_batch_messages($db, $batchId);
$foundCheckin = false;
foreach ($messages as $message) {
    if (strpos((string)$message['body'], 'halfway through bulk') !== false && (string)$message['author_type'] === 'admin') {
        $foundCheckin = true;
    }
}
$assert($foundCheckin, 'a check-in lands in the existing bake thread');
$mid = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
$assert($mid['next_key'] === 'checkin' && (int)$mid['checkpoint']['id'] === $checkpointId, 'the customer is asked for that check-in');
$foreign = false;
try {
    bakery_sfb_intensive_satisfy_checkpoint($db, $checkpointId, $customerB);
} catch (InvalidArgumentException $e) {
    $foreign = true;
}
$assert($foreign, 'another baker cannot close the check-in');
bakery_sfb_intensive_satisfy_checkpoint($db, $checkpointId, $customerA);

bakery_sfb_intensive_review_slot($db, (int)$slot1['id'], 'The dough needed more time before shaping.', 'Private: underfermented.');
$reviewed = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
$assert(strpos(json_encode($reviewed), 'Private: underfermented') === false, 'private bake notes stay off the customer view');
$assert($reviewed['slots'][0]['feedback'] === 'The dough needed more time before shaping.', 'the baker sees the coaching note');

$slot2 = bakery_sfb_intensive_slots($db, $enrollmentId)[1];
bakery_sfb_intensive_assign_formula($db, (int)$slot2['id'], $formulaId);
bakery_sfb_intensive_release_slot($db, (int)$slot2['id'], 'Let it reach a clearer rise before shaping.');
$batch2 = bakery_sfb_intensive_start_next_bake($db, $customerA);
$assert($batch2 !== $batchId, 'Batch 2 is its own journal bake');

bakery_sfb_intensive_retry_slot($db, (int)$slot2['id']);
$retried = bakery_sfb_intensive_slots($db, $enrollmentId)[1];
$assert((int)$retried['batch_id'] === 0 && (string)$retried['status'] === 'ready', 'a hard bake can be tried again');
$kept = $db->prepare('SELECT COUNT(*) FROM sfb_intensive_slot_batches WHERE batch_id = ?');
$kept->execute([$batch2]);
$assert((int)$kept->fetchColumn() === 1, 'the earlier bake stays linked');
$batch2b = bakery_sfb_intensive_start_next_bake($db, $customerA);
$assert($batch2b !== $batch2, 'the retry is a new bake');

$purchaseId = bakery_sfb_record_purchase_intent($db, $customerB, (int)$program['offering_id']);
bakery_sfb_set_purchase_status($db, $purchaseId, 'paid', null, 'intensive fixture', null, 'manual');
$enrolledB = bakery_sfb_intensive_for_customer($db, $customerB);
$assert($enrolledB && (int)$enrolledB['purchase_id'] === $purchaseId, 'a paid class opens the Intensive');
$assert(bakery_sfb_course_lock($db, $customerB, $course)['locked'] === false, 'the paid baker can read the lessons');

bakery_sfb_intensive_save_intake($db, $customerB, [
    'primary_goal' => 'Consistency',
    'experience_level' => 'regular',
]);
bakery_sfb_set_purchase_status($db, $purchaseId, 'refunded', null, 'intensive refund', null);
$still = bakery_sfb_intensive_enrollment($db, (int)$enrolledB['id']);
$assert((string)$still['status'] !== 'cancelled', 'a refund after setup does not erase the week');
$assert(bakery_sfb_course_lock($db, $customerB, $course)['locked'] === false, 'lessons stay open after the refund once the week has started');

bakery_sfb_intensive_update_windows($db, (int)$program['id'], 7, 1);
$past = $db->prepare('UPDATE sfb_intensive_enrollments SET start_date = ? WHERE id = ?');
$past->execute([date('Y-m-d', strtotime('-30 days')), (int)$enrolledB['id']]);
$programLimited = bakery_sfb_intensive_program($db, (int)$program['id']);
$assert(bakery_sfb_course_lock($db, $customerB, bakery_sfb_course($db, (int)$program['course_id']))['locked'] === true, 'a short lesson window can close without deleting the bakes');
bakery_sfb_intensive_update_windows($db, (int)$program['id'], 7, null);

bakery_sfb_intensive_complete($db, $enrollmentId, [
    'changed' => 'Oven spring improved once the dough had more time.',
    'lessons' => 'Judge the rise, not the clock.',
    'process' => 'Mix, folds, shape at a clear rise, cold overnight.',
    'next' => 'Repeat Batch 3 twice on your own.',
]);
$done = bakery_sfb_intensive_present($db, bakery_sfb_intensive_enrollment($db, $enrollmentId), false);
$assert($done['complete'] === true && $done['summary']['changed'] !== '', 'the baker gets a summary of the week');
$card = bakery_sfb_intensive_home_card($db, $customerA);
$assert($card && $card['prominence'] === 'quiet', 'a finished Intensive steps back on Home');

$notice = $db->prepare('SELECT COUNT(*) FROM customer_notifications WHERE customer_id = ? AND event_type = "intensive_ready"');
$notice->execute([$customerA]);
$assert((int)$notice->fetchColumn() >= 1, 'the baker is told the Intensive is ready in the existing notification center');

$spawned = bakery_sfb_intensive_spawn($db, 'SFB Intensive Spawn', '9182');
$assert((int)$spawned['enrollment_id'] > 0 && $spawned['pin'] === '9182', 'a new intensive can be spawned with a sign-in code');
$spawnedRow = bakery_sfb_intensive_for_customer($db, (int)$spawned['customer_id']);
$assert($spawnedRow && (string)$spawnedRow['status'] !== 'cancelled', 'the spawned baker has an open week');
$taken = false;
try {
    bakery_sfb_intensive_spawn($db, 'SFB Intensive Spawn Duplicate', '9182');
} catch (InvalidArgumentException $e) {
    $taken = true;
}
$assert($taken, 'a sign-in code cannot be reused');
$db->prepare('DELETE FROM customers WHERE id = ?')->execute([(int)$spawned['customer_id']]);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
