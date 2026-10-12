<?php
/**
 * Full Week Intensive — one baker's guided week on ordinary SF Baker batches.
 *
 * Customer-facing reads go through bakery_sfb_intensive_present() and
 * bakery_sfb_intensive_home_card(). Those arrays omit staff notes, purchase
 * rows, and internal status codes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_sfb_intensive_ready(PDO $db) {
    return table_exists($db, 'sfb_intensive_programs')
        && table_exists($db, 'sfb_intensive_enrollments')
        && table_exists($db, 'sfb_intensive_slots')
        && table_exists($db, 'sfb_intensive_slot_batches')
        && table_exists($db, 'sfb_intensive_checkpoints')
        && table_exists($db, 'sfb_intensive_messages');
}

function bakery_sfb_intensive_offering_title() {
    return 'Full Week Intensive Baking Course';
}

function bakery_sfb_intensive_course_title() {
    return 'Full Week Intensive';
}

function bakery_sfb_intensive_experience_levels() {
    return ['new', 'few', 'regular', 'experienced'];
}

function bakery_sfb_intensive_vessels() {
    return ['dutch', 'open', 'both', 'unsure'];
}

function bakery_sfb_intensive_stages() {
    return ['starter', 'mix', 'development', 'bulk', 'shape', 'bake', 'crumb'];
}

function bakery_sfb_intensive_stage_phase($stage) {
    $map = [
        'starter' => 'starter',
        'mix' => 'mix',
        'development' => 'development',
        'bulk' => 'development',
        'shape' => 'shape',
        'bake' => 'bake',
        'crumb' => 'final',
    ];
    return $map[(string)$stage] ?? '';
}

/** @return list<array{0:string,1:string,2:?string,3:string}> title, summary, url, step */
function bakery_sfb_intensive_lesson_plan() {
    $base = 'https://bakery.sourflour.org/breadeducation/';
    return [
        ['How your week works', 'Three guided bakes, with feedback while the dough is in front of you.', null,
            'Batch 1 shows where you are. Batch 2 changes one thing on purpose. Batch 3 aims at the goal you named. The lessons support the week. Your Intensive page tells you the next step.'],
        ['What helps your coach', 'Photos and a rough timeline are enough.', null,
            'When we ask for a check-in, add a photo on that bake: the dough, the shaped loaf, or the crumb. A sentence about how long it has been going, and about how warm the room is, is plenty.'],
        ['Starter readiness', 'Mix when the starter is ready, not when the clock says so.', $base . 'sourdough/starter-ready-to-mix.html',
            'Read the public note, then use your own starter log. Tell us if it smells sharp, sluggish, or just right before you mix Batch 1.'],
        ["Baker's percentages", 'One way to write a dough so the next bake can change on purpose.', $base . 'technique/formula.html',
            'You do not need to memorize this. Your formula in SF Baker already keeps the numbers. We will change one of them between bakes when it serves your goal.'],
        ['Dough temperature', 'Temperature is the clock you can actually steer.', $base . 'technique/dough-temperature.html',
            'Note the dough temperature on the bake when you can. Warmer dough moves faster. Cooler dough gives you more time.'],
        ['Reading fermentation', 'Look at the dough, not only the hour.', $base . 'sourdough/fermentation.html',
            'Bulk is done when the dough has grown and feels alive, not when a timer ends. We will name what to look for on your bake.'],
        ['Mixing and folds', 'Strength you can see in the dough.', $base . 'technique/mix-and-folds.html',
            'A photo after a fold tells us more than a minute count. Show us the dough when it feels smooth, or when it still tears.'],
        ['Shaping', 'A tight skin, without tearing what you built.', $base . 'technique/shaping-batards.html',
            'If shaping is part of your goal, we will ask for a photo just before it goes into the basket.'],
        ['Proofing and the cold rest', 'The overnight wait is a tool.', $base . 'technique/cold-retard.html',
            'Use the cold rest when your day needs it. Skipping a weekday is fine. Tell us when you plan to bake.'],
        ['Baking and the crumb', 'Oven spring, crust, and what the slice shows.', $base . 'technique/bake.html',
            'The loaf photo and a crumb photo are how Batch 1 teaches Batch 2. Cut the loaf when it has cooled.'],
        ['Going further', 'Higher hydration, whole grain, and the bake you actually have time for.', $base . 'technique/whole-grain.html',
            'Batch 3 can lean toward your goal: spring, an open crumb, a sturdier whole-grain loaf, or a schedule that fits your week. We will say which one.'],
    ];
}

function bakery_sfb_intensive_clip($value, $max) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (function_exists('mb_strlen') && mb_strlen($value) > $max) {
        throw new InvalidArgumentException('That note is too long');
    }
    if (!function_exists('mb_strlen') && strlen($value) > $max) {
        throw new InvalidArgumentException('That note is too long');
    }
    return $value;
}

function bakery_sfb_intensive_program(PDO $db, $programId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_programs WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$programId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_program_by_slug(PDO $db, $slug = 'full-week') {
    if (!bakery_sfb_intensive_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_programs WHERE slug = ? LIMIT 1');
    $stmt->execute([(string)$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_program_for_course(PDO $db, $courseId) {
    if (!bakery_sfb_intensive_ready($db) || (int)$courseId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_programs WHERE course_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int)$courseId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_program_for_offering(PDO $db, $offeringId) {
    if (!bakery_sfb_intensive_ready($db) || (int)$offeringId <= 0) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_programs WHERE offering_id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int)$offeringId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Create the shop class, the lesson spine, and the program row once.
 * Safe to call on every Intensive page.
 */
function bakery_sfb_intensive_ensure_catalog(PDO $db) {
    if (!bakery_sfb_intensive_ready($db) || !bakery_sfb_learning_ready($db) || !bakery_sfb_payments_ready($db)) {
        return null;
    }
    $offeringTitle = bakery_sfb_intensive_offering_title();
    $found = $db->prepare('SELECT id FROM sfb_offerings WHERE title = ? LIMIT 1');
    $found->execute([$offeringTitle]);
    $offeringId = (int)$found->fetchColumn();
    if ($offeringId <= 0) {
        $offeringId = bakery_sfb_create_offering(
            $db,
            $offeringTitle,
            300,
            'class',
            'A personalized virtual week with Sour Flour. You set a goal, bake three guided loaves, and get feedback while you bake. About seven days of coaching. You will cover the ground of the Introduction to Sourdough Baking course and go further. The lessons stay available after the coaching week.',
            null
        );
        $db->prepare('UPDATE sfb_offerings SET sort_order = 8 WHERE id = ?')->execute([$offeringId]);
    }

    $courseTitle = bakery_sfb_intensive_course_title();
    $courseStmt = $db->prepare('SELECT id FROM sfb_courses WHERE title = ? ORDER BY id LIMIT 1');
    $courseStmt->execute([$courseTitle]);
    $courseId = (int)$courseStmt->fetchColumn();
    if ($courseId <= 0) {
        $courseId = bakery_sfb_create_course(
            $db,
            $courseTitle,
            'Reading for your three guided bakes. Your week moves with your dough, not with lesson checkmarks.'
        );
        $db->prepare('UPDATE sfb_courses SET sort_order = 200 WHERE id = ?')->execute([$courseId]);
    }
    if (bakery_sfb_gating_ready($db)) {
        $current = bakery_sfb_course($db, $courseId);
        if ($current && (int)($current['required_offering_id'] ?? 0) !== $offeringId) {
            bakery_sfb_set_course_offering($db, $courseId, $offeringId);
        }
    }
    $lessonCount = $db->prepare('SELECT COUNT(*) FROM sfb_course_lessons WHERE course_id = ?');
    $lessonCount->execute([$courseId]);
    if ((int)$lessonCount->fetchColumn() === 0) {
        foreach (bakery_sfb_intensive_lesson_plan() as $plan) {
            $lessonId = bakery_sfb_create_lesson($db, $courseId, $plan[0], $plan[1], (string)($plan[2] ?? ''));
            bakery_sfb_add_lesson_step($db, $lessonId, $plan[3], null, 'photo');
        }
    }

    $program = bakery_sfb_intensive_program_by_slug($db, 'full-week');
    if (!$program) {
        $ins = $db->prepare(
            'INSERT INTO sfb_intensive_programs (slug, title, offering_id, course_id, coaching_days, material_access_days, is_active)
             VALUES ("full-week", ?, ?, ?, 7, NULL, 1)'
        );
        $ins->execute([$courseTitle, $offeringId, $courseId]);
        $program = bakery_sfb_intensive_program_by_slug($db, 'full-week');
    } elseif ((int)$program['offering_id'] !== $offeringId || (int)$program['course_id'] !== $courseId) {
        $db->prepare('UPDATE sfb_intensive_programs SET offering_id = ?, course_id = ? WHERE id = ?')
            ->execute([$offeringId, $courseId, (int)$program['id']]);
        $program = bakery_sfb_intensive_program_by_slug($db, 'full-week');
    }
    return $program;
}

function bakery_sfb_intensive_update_windows(PDO $db, $programId, $coachingDays, $materialAccessDays) {
    $program = bakery_sfb_intensive_program($db, $programId);
    if (!$program) {
        throw new InvalidArgumentException('Intensive program not found');
    }
    $coachingDays = (int)$coachingDays;
    if ($coachingDays < 1 || $coachingDays > 90) {
        throw new InvalidArgumentException('Coaching length must be between 1 and 90 days');
    }
    $material = null;
    if ($materialAccessDays !== null && $materialAccessDays !== '') {
        $material = (int)$materialAccessDays;
        if ($material < 1 || $material > 3650) {
            throw new InvalidArgumentException('Lesson access must be between 1 and 3650 days, or left open');
        }
    }
    $db->prepare('UPDATE sfb_intensive_programs SET coaching_days = ?, material_access_days = ? WHERE id = ?')
        ->execute([$coachingDays, $material, (int)$program['id']]);
    return bakery_sfb_intensive_program($db, $programId);
}

function bakery_sfb_intensive_add_days($startDate, $days) {
    $days = max(1, (int)$days);
    $start = strtotime((string)$startDate . ' 00:00:00');
    if ($start === false) {
        return null;
    }
    return date('Y-m-d', strtotime('+' . ($days - 1) . ' days', $start));
}

function bakery_sfb_intensive_terminal_statuses() {
    return ['completed', 'cancelled'];
}

function bakery_sfb_intensive_enrollment(PDO $db, $enrollmentId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_enrollments WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$enrollmentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_open_enrollment(PDO $db, $customerId, $programId) {
    $stmt = $db->prepare(
        'SELECT * FROM sfb_intensive_enrollments
         WHERE customer_id = ? AND program_id = ? AND status NOT IN ("completed", "cancelled")
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, (int)$programId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_for_customer(PDO $db, $customerId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return null;
    }
    $program = bakery_sfb_intensive_program_by_slug($db, 'full-week');
    if (!$program) {
        return null;
    }
    $open = bakery_sfb_intensive_open_enrollment($db, $customerId, (int)$program['id']);
    if ($open) {
        return $open;
    }
    $stmt = $db->prepare(
        'SELECT * FROM sfb_intensive_enrollments
         WHERE customer_id = ? AND program_id = ? AND status = "completed"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, (int)$program['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_intensive_material_open_for_customer(PDO $db, $customerId, array $program) {
    if (!bakery_sfb_intensive_ready($db)) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT * FROM sfb_intensive_enrollments
         WHERE customer_id = ? AND program_id = ? AND status <> "cancelled"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, (int)$program['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    if ($program['material_access_days'] === null || $program['material_access_days'] === '') {
        return true;
    }
    if (empty($row['start_date'])) {
        return true;
    }
    $until = bakery_sfb_intensive_add_days($row['start_date'], (int)$program['material_access_days']);
    return $until !== null && date('Y-m-d') <= $until;
}

function bakery_sfb_intensive_slots(PDO $db, $enrollmentId) {
    $stmt = $db->prepare(
        'SELECT s.*, f.name AS formula_name, b.name AS batch_name, b.status AS batch_status
         FROM sfb_intensive_slots s
         LEFT JOIN sfb_formulas f ON f.id = s.formula_id
         LEFT JOIN sfb_batches b ON b.id = s.batch_id
         WHERE s.enrollment_id = ?
         ORDER BY s.sequence_number, s.id'
    );
    $stmt->execute([(int)$enrollmentId]);
    return $stmt->fetchAll();
}

function bakery_sfb_intensive_create_slots(PDO $db, $enrollmentId) {
    $ins = $db->prepare(
        'INSERT INTO sfb_intensive_slots (enrollment_id, sequence_number, title, status)
         VALUES (?, ?, ?, "upcoming")'
    );
    foreach ([1 => 'Batch 1', 2 => 'Batch 2', 3 => 'Batch 3'] as $n => $title) {
        $ins->execute([(int)$enrollmentId, $n, $title]);
    }
}

function bakery_sfb_intensive_enroll(PDO $db, $customerId, $programId, array $options = []) {
    if (!bakery_sfb_intensive_ready($db)) {
        throw new RuntimeException('The Intensive needs a database update (migration 083)');
    }
    $customerId = (int)$customerId;
    bakery_sfb_assert_human_customer($db, $customerId, 'join the Intensive');
    $customer = $db->prepare('SELECT id, name FROM customers WHERE id = ? AND is_active = 1 LIMIT 1');
    $customer->execute([$customerId]);
    $customerRow = $customer->fetch();
    if (!$customerRow) {
        throw new InvalidArgumentException('Choose an active customer');
    }
    $program = bakery_sfb_intensive_program($db, $programId);
    if (!$program || (int)$program['is_active'] !== 1) {
        throw new InvalidArgumentException('That Intensive is not open');
    }
    if (bakery_sfb_intensive_open_enrollment($db, $customerId, (int)$program['id'])) {
        throw new InvalidArgumentException('This baker already has an Intensive in progress');
    }

    $purchaseId = (int)($options['purchase_id'] ?? 0);
    if ($purchaseId > 0) {
        $purchase = bakery_sfb_purchase($db, $purchaseId);
        if (!$purchase || (int)$purchase['customer_id'] !== $customerId || (string)$purchase['status'] !== 'paid') {
            throw new InvalidArgumentException('That payment is not a paid purchase for this baker');
        }
        if ((int)$program['offering_id'] > 0 && (int)$purchase['offering_id'] !== (int)$program['offering_id']) {
            throw new InvalidArgumentException('That payment is for a different class');
        }
    }
    $staffId = (int)($options['assigned_user_id'] ?? 0);
    $start = trim((string)($options['start_date'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        throw new InvalidArgumentException('Start date must be a calendar date');
    }
    $end = null;
    if ($start !== '') {
        $end = trim((string)($options['coaching_end_date'] ?? ''));
        if ($end === '') {
            $end = bakery_sfb_intensive_add_days($start, (int)$program['coaching_days']);
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            throw new InvalidArgumentException('End date must be a calendar date');
        }
    }
    $note = bakery_sfb_intensive_clip($options['internal_note'] ?? '', 4000);

    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $ins = $db->prepare(
            'INSERT INTO sfb_intensive_enrollments
                (program_id, customer_id, purchase_id, assigned_user_id, start_date, coaching_end_date, status, internal_notes)
             VALUES (?, ?, ?, ?, ?, ?, "pending", ?)'
        );
        $ins->execute([
            (int)$program['id'],
            $customerId,
            $purchaseId > 0 ? $purchaseId : null,
            $staffId > 0 ? $staffId : null,
            $start !== '' ? $start : null,
            $end,
            $note !== '' ? '[' . date('Y-m-d H:i') . '] ' . $note : null,
        ]);
        $enrollmentId = (int)$db->lastInsertId();
        bakery_sfb_intensive_create_slots($db, $enrollmentId);
        $msg = $db->prepare(
            'INSERT INTO sfb_intensive_messages
                (enrollment_id, author_type, author_user_id, author_name, body)
             VALUES (?, "admin", ?, "Sour Flour", ?)'
        );
        $msg->execute([
            $enrollmentId,
            $staffId > 0 ? $staffId : null,
            'Your Full Week Intensive is ready. Tell us what you most want to improve, then we will set up your first bake.',
        ]);
        if ($owns) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    bakery_sfb_intensive_notify(
        $db,
        $customerId,
        'intensive_ready',
        'Your Full Week Intensive is ready',
        'Sign in and tell us what you want to work on this week.',
        'intensive-ready:' . $enrollmentId
    );
    return $enrollmentId;
}

function bakery_sfb_intensive_enroll_from_purchase(PDO $db, $purchaseId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return 0;
    }
    $program = bakery_sfb_intensive_ensure_catalog($db);
    if (!$program) {
        return 0;
    }
    $purchase = bakery_sfb_purchase($db, $purchaseId);
    if (!$purchase || (string)$purchase['status'] !== 'paid') {
        return 0;
    }
    if ((int)$purchase['offering_id'] !== (int)$program['offering_id']) {
        return 0;
    }
    $open = bakery_sfb_intensive_open_enrollment($db, (int)$purchase['customer_id'], (int)$program['id']);
    if ($open) {
        if (empty($open['purchase_id'])) {
            $db->prepare('UPDATE sfb_intensive_enrollments SET purchase_id = ? WHERE id = ?')
                ->execute([(int)$purchase['id'], (int)$open['id']]);
        }
        return (int)$open['id'];
    }
    return bakery_sfb_intensive_enroll($db, (int)$purchase['customer_id'], (int)$program['id'], [
        'purchase_id' => (int)$purchase['id'],
        'start_date' => date('Y-m-d'),
    ]);
}

function bakery_sfb_intensive_claim_paid(PDO $db, $customerId) {
    if (!bakery_sfb_intensive_ready($db) || !bakery_sfb_payments_ready($db)) {
        return 0;
    }
    $program = bakery_sfb_intensive_ensure_catalog($db);
    if (!$program) {
        return 0;
    }
    $existing = bakery_sfb_intensive_for_customer($db, $customerId);
    if ($existing && !in_array((string)$existing['status'], bakery_sfb_intensive_terminal_statuses(), true)) {
        return (int)$existing['id'];
    }
    if ($existing && (string)$existing['status'] === 'completed') {
        return (int)$existing['id'];
    }
    $stmt = $db->prepare(
        'SELECT id FROM sfb_offering_purchases
         WHERE customer_id = ? AND offering_id = ? AND status = "paid"
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId, (int)$program['offering_id']]);
    $purchaseId = (int)$stmt->fetchColumn();
    if ($purchaseId <= 0) {
        return 0;
    }
    return bakery_sfb_intensive_enroll_from_purchase($db, $purchaseId);
}

function bakery_sfb_intensive_on_purchase_status(PDO $db, $purchaseId, $status) {
    if (!bakery_sfb_intensive_ready($db) || !bakery_sfb_payments_ready($db)) {
        return;
    }
    $purchase = bakery_sfb_purchase($db, $purchaseId);
    if (!$purchase) {
        return;
    }
    $program = bakery_sfb_intensive_program_for_offering($db, (int)$purchase['offering_id']);
    $offering = bakery_sfb_offering($db, (int)$purchase['offering_id']);
    $isIntensive = $program
        || ($offering && (string)$offering['title'] === bakery_sfb_intensive_offering_title());
    if (!$isIntensive) {
        return;
    }
    if ($status === 'paid') {
        bakery_sfb_intensive_enroll_from_purchase($db, $purchaseId);
        return;
    }
    if ($status !== 'refunded') {
        return;
    }
    $stmt = $db->prepare('SELECT * FROM sfb_intensive_enrollments WHERE purchase_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$purchaseId]);
    $row = $stmt->fetch();
    if (!$row || (string)$row['status'] === 'cancelled') {
        return;
    }
    if ((string)$row['status'] === 'pending' && empty($row['intake_completed_at'])) {
        $db->prepare('UPDATE sfb_intensive_enrollments SET status = "cancelled" WHERE id = ?')->execute([(int)$row['id']]);
        return;
    }
    $note = trim((string)$row['internal_notes']);
    $line = '[' . date('Y-m-d H:i') . '] Payment was refunded. The Intensive was left in place.';
    $db->prepare('UPDATE sfb_intensive_enrollments SET internal_notes = ? WHERE id = ?')
        ->execute([$note === '' ? $line : $note . "\n" . $line, (int)$row['id']]);
}

function bakery_sfb_intensive_notify(PDO $db, $customerId, $event, $title, $message, $dedupe) {
    if (!function_exists('bakery_customer_notify')) {
        require_once __DIR__ . '/customer_notifications.php';
    }
    if (!function_exists('bakery_customer_notify')) {
        return;
    }
    try {
        bakery_customer_notify($db, (int)$customerId, (string)$event, (string)$title, (string)$message, [
            'always_in_app' => true,
            'category' => 'education',
            'dedupe_key' => (string)$dedupe,
            'link_url' => 'sfb_intensive.php',
            'related_entity_type' => 'sfb_intensive',
        ]);
    } catch (Throwable $e) {
        error_log('Intensive notice skipped: ' . $e->getMessage());
    }
}

function bakery_sfb_intensive_sync_slots(PDO $db, $enrollmentId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return;
    }
    $db->prepare(
        'UPDATE sfb_intensive_slots s
         JOIN sfb_batches b ON b.id = s.batch_id
         SET s.status = "awaiting_review"
         WHERE s.enrollment_id = ? AND s.status = "in_progress" AND b.status = "completed"'
    )->execute([(int)$enrollmentId]);
}

function bakery_sfb_intensive_save_intake(PDO $db, $customerId, array $input) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], bakery_sfb_intensive_terminal_statuses(), true)) {
        throw new InvalidArgumentException('Your Intensive is not open');
    }
    if ((string)$enrollment['status'] === 'paused') {
        throw new InvalidArgumentException('Your week is paused');
    }
    bakery_sfb_assert_human_customer($db, $customerId, 'update Intensive setup');
    $goal = bakery_sfb_intensive_clip($input['primary_goal'] ?? '', 2000);
    if ($goal === '') {
        throw new InvalidArgumentException('Tell us what you most want to improve');
    }
    $level = (string)($input['experience_level'] ?? '');
    if (!in_array($level, bakery_sfb_intensive_experience_levels(), true)) {
        throw new InvalidArgumentException('Choose the experience that fits you best');
    }
    $vessel = (string)($input['bake_vessel'] ?? '');
    if ($vessel !== '' && !in_array($vessel, bakery_sfb_intensive_vessels(), true)) {
        throw new InvalidArgumentException('Choose how you usually bake');
    }
    $program = bakery_sfb_intensive_program($db, (int)$enrollment['program_id']);
    $start = (string)($enrollment['start_date'] ?? '');
    $end = (string)($enrollment['coaching_end_date'] ?? '');
    if ($start === '') {
        $start = date('Y-m-d');
        $end = bakery_sfb_intensive_add_days($start, (int)($program['coaching_days'] ?? 7));
    } elseif ($end === '') {
        $end = bakery_sfb_intensive_add_days($start, (int)($program['coaching_days'] ?? 7));
    }
    $status = (string)$enrollment['status'];
    if ($status === 'pending') {
        $status = 'awaiting_staff';
    }
    $db->prepare(
        'UPDATE sfb_intensive_enrollments
         SET primary_goal = ?, experience_level = ?, customer_background = ?, availability = ?,
             room_temperature = ?, oven_type = ?, bake_vessel = ?, flour_used = ?,
             start_date = ?, coaching_end_date = ?, status = ?,
             intake_completed_at = COALESCE(intake_completed_at, NOW())
         WHERE id = ? AND customer_id = ?'
    )->execute([
        $goal,
        $level,
        bakery_sfb_intensive_clip($input['customer_background'] ?? '', 2000) ?: null,
        bakery_sfb_intensive_clip($input['availability'] ?? '', 500) ?: null,
        bakery_sfb_intensive_clip($input['room_temperature'] ?? '', 40) ?: null,
        bakery_sfb_intensive_clip($input['oven_type'] ?? '', 80) ?: null,
        $vessel !== '' ? $vessel : null,
        bakery_sfb_intensive_clip($input['flour_used'] ?? '', 150) ?: null,
        $start,
        $end,
        $status,
        (int)$enrollment['id'],
        (int)$customerId,
    ]);
    return (int)$enrollment['id'];
}

function bakery_sfb_intensive_checkpoints(PDO $db, $slotId) {
    $stmt = $db->prepare(
        'SELECT id, slot_id, stage, instruction, status, requested_at, satisfied_at
         FROM sfb_intensive_checkpoints WHERE slot_id = ? ORDER BY id'
    );
    $stmt->execute([(int)$slotId]);
    return $stmt->fetchAll();
}

function bakery_sfb_intensive_messages(PDO $db, $enrollmentId) {
    $stmt = $db->prepare(
        'SELECT id, author_type, author_name, body, customer_read_at, staff_read_at, created_at
         FROM sfb_intensive_messages WHERE enrollment_id = ? ORDER BY id'
    );
    $stmt->execute([(int)$enrollmentId]);
    return $stmt->fetchAll();
}

function bakery_sfb_intensive_present(PDO $db, array $enrollment, $markRead = false) {
    bakery_sfb_intensive_sync_slots($db, (int)$enrollment['id']);
    $enrollment = bakery_sfb_intensive_enrollment($db, (int)$enrollment['id']) ?: $enrollment;
    $slots = bakery_sfb_intensive_slots($db, (int)$enrollment['id']);
    $messages = bakery_sfb_intensive_messages($db, (int)$enrollment['id']);
    if ($markRead) {
        $db->prepare(
            'UPDATE sfb_intensive_messages
             SET customer_read_at = NOW()
             WHERE enrollment_id = ? AND author_type = "admin" AND customer_read_at IS NULL'
        )->execute([(int)$enrollment['id']]);
    }

    $unread = 0;
    $visibleMessages = [];
    foreach ($messages as $message) {
        if ((string)$message['author_type'] === 'admin' && empty($message['customer_read_at']) && !$markRead) {
            $unread++;
        }
        $visibleMessages[] = [
            'author' => (string)$message['author_name'],
            'body' => (string)$message['body'],
            'mine' => (string)$message['author_type'] === 'baker',
            'when' => (string)$message['created_at'],
        ];
    }

    $current = null;
    $ready = null;
    $reviewedCount = 0;
    $publicSlots = [];
    $openCheckpoint = null;
    foreach ($slots as $slot) {
        $seq = (int)$slot['sequence_number'];
        if ((string)$slot['status'] === 'reviewed') {
            $reviewedCount++;
        }
        if ($current === null && in_array((string)$slot['status'], ['in_progress', 'awaiting_review'], true)) {
            $current = $slot;
        }
        if ($ready === null && (string)$slot['status'] === 'ready') {
            $ready = $slot;
        }
        $checks = bakery_sfb_intensive_checkpoints($db, (int)$slot['id']);
        foreach ($checks as $check) {
            if ($openCheckpoint === null && (string)$check['status'] === 'requested') {
                $openCheckpoint = $check;
                $openCheckpoint['sequence_number'] = $seq;
                $openCheckpoint['batch_id'] = (int)$slot['batch_id'];
            }
        }
        $state = 'ahead';
        if ((string)$slot['status'] === 'reviewed') {
            $state = 'done';
        } elseif (in_array((string)$slot['status'], ['in_progress', 'awaiting_review', 'ready'], true)) {
            $state = 'now';
        }
        $publicSlots[] = [
            'sequence' => $seq,
            'title' => (string)$slot['title'],
            'state' => $state,
            'focus' => (string)($slot['objective'] ?? ''),
            'feedback' => (string)($slot['customer_feedback'] ?? ''),
            'href' => (int)$slot['batch_id'] > 0 ? 'sfb_batch.php?batch=' . (int)$slot['batch_id'] : '',
        ];
    }

    $working = $current ?: $ready;
    if (!$working) {
        foreach ($slots as $slot) {
            if ((string)$slot['status'] === 'upcoming') {
                $working = $slot;
                break;
            }
        }
    }
    if ($working && (int)($working['formula_id'] ?? 0) <= 0
        && !in_array((string)$enrollment['status'], ['completed', 'cancelled', 'paused'], true)) {
        bakery_sfb_intensive_ensure_slot_formula($db, (int)$working['id']);
        foreach (bakery_sfb_intensive_slots($db, (int)$enrollment['id']) as $reloaded) {
            if ((int)$reloaded['id'] === (int)$working['id']) {
                $working = $reloaded;
                break;
            }
        }
    }

    $status = (string)$enrollment['status'];
    $showIntake = false;
    $n = 1;
    if ($current) {
        $n = (int)$current['sequence_number'];
    } elseif ($ready) {
        $n = (int)$ready['sequence_number'];
    } elseif ($reviewedCount > 0) {
        $n = min(3, $reviewedCount + 1);
    }

    $nextKey = 'wait_coach';
    $nextHref = 'sfb_intensive.php';
    $nextAction = 'wait';
    if ($status === 'paused') {
        $nextKey = 'paused';
        $nextAction = 'none';
    } elseif ($status === 'completed' || ($status === 'final_review' && $reviewedCount >= 3)) {
        $nextKey = 'read_summary';
        $nextAction = 'summary';
    } elseif ($openCheckpoint && (int)($openCheckpoint['batch_id'] ?? 0) > 0) {
        $nextKey = 'checkin';
        $nextAction = 'checkin';
        $nextHref = 'sfb_intensive.php';
    } elseif ($current && (string)$current['status'] === 'awaiting_review' && (string)($current['customer_feedback'] ?? '') !== '') {
        $nextKey = 'read_feedback';
        $nextAction = 'feedback';
        $nextHref = 'sfb_intensive.php';
    } elseif ($current && (string)$current['status'] === 'in_progress' && (int)$current['batch_id'] > 0) {
        $nextKey = 'continue_bake';
        $nextAction = 'continue';
        $nextHref = 'sfb_intensive.php';
    } elseif ($current && (string)$current['status'] === 'awaiting_review') {
        $nextKey = 'wait_coach';
        $nextAction = 'wait';
        $nextHref = 'sfb_intensive.php';
    } elseif ($ready && (int)$ready['formula_id'] > 0) {
        $nextKey = 'start_bake';
        $nextAction = 'start';
    } elseif ($working && (int)($working['batch_id'] ?? 0) <= 0 && in_array((string)($working['status'] ?? ''), ['upcoming', 'ready'], true)) {
        $nextKey = 'start_bake';
        $nextAction = 'start';
    } elseif ($reviewedCount > 0 && $ready === null && $current === null && $status !== 'final_review') {
        $nextKey = 'read_feedback';
        $nextAction = 'feedback';
    } elseif ($status === 'final_review') {
        $nextKey = 'read_summary';
        $nextAction = 'summary';
    }

    $stateKey = 'getting_started';
    if ($status === 'paused') {
        $stateKey = 'paused';
    } elseif ($status === 'completed') {
        $stateKey = 'complete';
    } elseif ($status === 'final_review') {
        $stateKey = 'final';
    } elseif ($showIntake) {
        $stateKey = 'getting_started';
    } elseif ($current && (string)$current['status'] === 'awaiting_review') {
        $stateKey = 'feedback';
    } elseif ($current && (string)$current['status'] === 'in_progress') {
        $stateKey = 'baking';
    } elseif ($ready) {
        $stateKey = 'preparing';
    } elseif ($reviewedCount > 0) {
        $stateKey = 'feedback';
    }

    $dates = '';
    if (!empty($enrollment['start_date']) && !empty($enrollment['coaching_end_date'])) {
        $dates = date('M j', strtotime((string)$enrollment['start_date']))
            . ' – ' . date('M j', strtotime((string)$enrollment['coaching_end_date']));
    }
    $coachingClosed = !empty($enrollment['coaching_end_date'])
        && (string)$enrollment['status'] !== 'completed'
        && date('Y-m-d') > (string)$enrollment['coaching_end_date'];

    $summary = null;
    if (in_array($status, ['completed', 'final_review'], true)) {
        $summary = [
            'goal' => (string)($enrollment['primary_goal'] ?? ''),
            'changed' => (string)($enrollment['summary_changed'] ?? ''),
            'lessons' => (string)($enrollment['summary_lessons'] ?? ''),
            'process' => (string)($enrollment['summary_process'] ?? ''),
            'next' => (string)($enrollment['summary_next'] ?? ''),
        ];
    }

    $instruction = '';
    $step = 'formula';
    if ($working && isset($working['workshop_step']) && (string)$working['workshop_step'] !== '') {
        $step = (string)$working['workshop_step'];
    }
    $stepCopy = [
        'formula' => 'Set the dough. Flour is always 100%.',
        'mix' => 'Mix until the dough comes together.',
        'bulk' => 'Let the dough rise.',
        'shape' => 'Shape the loaf.',
        'bake' => 'Bake it.',
        'done' => 'This loaf is done.',
    ];
    if ($openCheckpoint) {
        $instruction = (string)$openCheckpoint['instruction'];
    } elseif ($step === 'done' && $working && trim((string)($working['customer_feedback'] ?? '')) !== '') {
        $instruction = (string)$working['customer_feedback'];
    } else {
        $instruction = $stepCopy[$step] ?? $stepCopy['formula'];
    }
    $formulaView = ['id' => 0, 'name' => '', 'editable' => false, 'lines' => []];
    if ($working && (int)($working['formula_id'] ?? 0) > 0) {
        $formulaRow = bakery_sfb_formula($db, (int)$enrollment['customer_id'], (int)$working['formula_id']);
        $editable = (int)($working['batch_id'] ?? 0) <= 0
            || (string)($working['batch_status'] ?? '') === 'in_progress'
            || (string)($working['status'] ?? '') === 'upcoming'
            || (string)($working['status'] ?? '') === 'ready';
        if ((string)($working['status'] ?? '') === 'reviewed' || (string)($working['batch_status'] ?? '') === 'completed') {
            $editable = false;
        }
        $lines = [];
        if ($formulaRow && $editable && $status !== 'completed') {
            bakery_sfb_intensive_balance_flours($db, (int)$formulaRow['id']);
        }
        if ($formulaRow) {
            $grams = bakery_sfb_formula_grams(
                bakery_sfb_formula_lines($db, (int)$formulaRow['id']),
                (float)($formulaRow['target_dough_g'] ?? 1000) > 0 ? (float)$formulaRow['target_dough_g'] : 1000
            );
            foreach ($grams['lines'] as $line) {
                $lines[] = [
                    'id' => (int)$line['id'],
                    'name' => (string)($line['line_name'] ?? ''),
                    'kind' => (string)($line['line_kind'] ?? 'other'),
                    'percentage' => (float)$line['percentage'],
                    'grams' => (float)($line['grams'] ?? 0),
                ];
            }
        }
        $formulaView = [
            'id' => (int)($working['formula_id'] ?? 0),
            'name' => (string)($formulaRow['name'] ?? ($working['formula_name'] ?? '')),
            'editable' => $editable && $status !== 'completed',
            'lines' => $lines,
        ];
    }
    if ($working) {
        $n = (int)$working['sequence_number'];
    }

    $lessonHref = '';
    $program = bakery_sfb_intensive_program($db, (int)$enrollment['program_id']);
    if ($program && (int)$program['course_id'] > 0 && function_exists('bakery_sfb_course_lessons')) {
        foreach (bakery_sfb_course_lessons($db, (int)$program['course_id']) as $lesson) {
            $lessonHref = 'sfb_lesson.php?lesson=' . (int)$lesson['id'];
            break;
        }
    }

    return [
        'goal' => (string)($enrollment['primary_goal'] ?? ''),
        'focus' => (string)($enrollment['staff_objective'] ?? ''),
        'dates' => $dates,
        'coaching_closed' => $coachingClosed,
        'progress_n' => $n,
        'reviewed_count' => $reviewedCount,
        'state_key' => $stateKey,
        'next_key' => $nextKey,
        'next_href' => $nextHref,
        'next_action' => $nextAction,
        'show_intake' => $showIntake,
        'paused' => $status === 'paused',
        'complete' => $status === 'completed',
        'checkpoint' => $openCheckpoint ? [
            'id' => (int)$openCheckpoint['id'],
            'stage' => (string)$openCheckpoint['stage'],
            'instruction' => (string)$openCheckpoint['instruction'],
            'sequence' => (int)$openCheckpoint['sequence_number'],
        ] : null,
        'slots' => $publicSlots,
        'messages' => $visibleMessages,
        'summary' => $summary,
        'lessons_href' => $lessonHref,
        'unread' => $unread,
        'experience_level' => (string)($enrollment['experience_level'] ?? ''),
        'background' => (string)($enrollment['customer_background'] ?? ''),
        'availability' => (string)($enrollment['availability'] ?? ''),
        'room_temperature' => (string)($enrollment['room_temperature'] ?? ''),
        'oven_type' => (string)($enrollment['oven_type'] ?? ''),
        'bake_vessel' => (string)($enrollment['bake_vessel'] ?? ''),
        'flour_used' => (string)($enrollment['flour_used'] ?? ''),
        'instruction' => $instruction,
        'step' => $step,
        'batch_id' => $working ? (int)($working['batch_id'] ?? 0) : 0,
        'formula' => $formulaView,
        'dough_temp_f' => ($working && isset($working['dough_temp_f']) && $working['dough_temp_f'] !== null && $working['dough_temp_f'] !== '')
            ? (string)$working['dough_temp_f'] : '',
        'facts_editable' => !empty($formulaView['editable']),
    ];
}

function bakery_sfb_intensive_home_card(PDO $db, $customerId) {
    if (!bakery_sfb_intensive_ready($db)) {
        return null;
    }
    bakery_sfb_intensive_claim_paid($db, $customerId);
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment) {
        return null;
    }
    $view = bakery_sfb_intensive_present($db, $enrollment, false);
    return [
        'prominence' => !empty($view['complete']) ? 'quiet' : 'primary',
        'goal' => $view['goal'],
        'dates' => $view['dates'],
        'progress_n' => $view['progress_n'],
        'reviewed_count' => $view['reviewed_count'],
        'state_key' => $view['state_key'],
        'next_key' => $view['next_key'],
        'instruction' => (string)($view['instruction'] ?? ''),
        'href' => 'sfb_intensive.php',
        'complete' => !empty($view['complete']),
    ];
}

function bakery_sfb_intensive_batch_context(PDO $db, $customerId, $batchId) {
    if (!bakery_sfb_intensive_ready($db) || (int)$batchId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT s.sequence_number, s.id AS slot_id, e.status, e.customer_id
         FROM sfb_intensive_slots s
         JOIN sfb_intensive_enrollments e ON e.id = s.enrollment_id
         WHERE s.batch_id = ? AND e.customer_id = ? AND e.status <> "cancelled"
         LIMIT 1'
    );
    $stmt->execute([(int)$batchId, (int)$customerId]);
    $row = $stmt->fetch();
    if (!$row) {
        $stmt = $db->prepare(
            'SELECT s.sequence_number, s.id AS slot_id, e.status, e.customer_id
             FROM sfb_intensive_slot_batches sb
             JOIN sfb_intensive_slots s ON s.id = sb.slot_id
             JOIN sfb_intensive_enrollments e ON e.id = s.enrollment_id
             WHERE sb.batch_id = ? AND e.customer_id = ? AND e.status <> "cancelled"
             LIMIT 1'
        );
        $stmt->execute([(int)$batchId, (int)$customerId]);
        $row = $stmt->fetch();
    }
    if (!$row) {
        return null;
    }
    $checkpoint = null;
    if (!in_array((string)$row['status'], ['completed', 'cancelled'], true)) {
        foreach (bakery_sfb_intensive_checkpoints($db, (int)$row['slot_id']) as $check) {
            if ((string)$check['status'] === 'requested') {
                $checkpoint = [
                    'id' => (int)$check['id'],
                    'stage' => (string)$check['stage'],
                    'instruction' => (string)$check['instruction'],
                ];
                break;
            }
        }
    }
    return [
        'sequence' => (int)$row['sequence_number'],
        'checkpoint' => $checkpoint,
    ];
}

function bakery_sfb_intensive_require_slot(PDO $db, $slotId) {
    $stmt = $db->prepare(
        'SELECT s.*, e.customer_id, e.status AS enrollment_status, e.id AS enrollment_id
         FROM sfb_intensive_slots s
         JOIN sfb_intensive_enrollments e ON e.id = s.enrollment_id
         WHERE s.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$slotId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('That guided bake was not found');
    }
    return $row;
}

function bakery_sfb_intensive_assign_formula(PDO $db, $slotId, $formulaId) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if (in_array((string)$slot['enrollment_status'], ['cancelled', 'completed'], true)) {
        throw new InvalidArgumentException('This Intensive is already closed');
    }
    $formulaId = (int)$formulaId;
    $owned = bakery_sfb_formula($db, (int)$slot['customer_id'], $formulaId);
    if ($owned && (int)$owned['customer_id'] === (int)$slot['customer_id']) {
        $useId = (int)$owned['id'];
    } else {
        $useId = bakery_sfb_copy_template($db, (int)$slot['customer_id'], $formulaId);
    }
    $db->prepare('UPDATE sfb_intensive_slots SET formula_id = ? WHERE id = ?')->execute([$useId, (int)$slot['id']]);
    return $useId;
}

function bakery_sfb_intensive_release_slot(PDO $db, $slotId, $objective) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if (in_array((string)$slot['enrollment_status'], ['cancelled', 'completed', 'paused'], true)) {
        throw new InvalidArgumentException('This Intensive is not ready for the next bake');
    }
    if ((int)$slot['formula_id'] <= 0) {
        throw new InvalidArgumentException('Assign a formula before opening this bake');
    }
    if ((int)$slot['batch_id'] > 0 && (string)$slot['status'] === 'in_progress') {
        throw new InvalidArgumentException('This bake is already underway');
    }
    $prev = $db->prepare(
        'SELECT id, status FROM sfb_intensive_slots
         WHERE enrollment_id = ? AND sequence_number = ? LIMIT 1'
    );
    $prev->execute([(int)$slot['enrollment_id'], (int)$slot['sequence_number'] - 1]);
    $previous = $prev->fetch();
    if ($previous && (string)$previous['status'] === 'in_progress') {
        throw new InvalidArgumentException('Finish or set aside the bake that is already going');
    }
    $objective = bakery_sfb_intensive_clip($objective, 2000);
    $db->prepare('UPDATE sfb_intensive_slots SET status = "ready", objective = ? WHERE id = ?')
        ->execute([$objective !== '' ? $objective : null, (int)$slot['id']]);
    $db->prepare('UPDATE sfb_intensive_enrollments SET status = "awaiting_customer" WHERE id = ? AND status NOT IN ("completed", "cancelled", "paused")')
        ->execute([(int)$slot['enrollment_id']]);
    bakery_sfb_intensive_notify(
        $db,
        (int)$slot['customer_id'],
        'intensive_next_bake',
        'Your next guided bake is ready',
        'Open your Intensive and start Batch ' . (int)$slot['sequence_number'] . '.',
        'intensive-release:' . (int)$slot['id'] . ':' . date('YmdHis')
    );
}

function bakery_sfb_intensive_attach_batch(PDO $db, array $slot, $batchId, $current = true) {
    $batch = bakery_sfb_batch($db, (int)$slot['customer_id'], $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('That bake is not in this baker\'s journal');
    }
    $taken = $db->prepare('SELECT id FROM sfb_intensive_slot_batches WHERE batch_id = ? LIMIT 1');
    $taken->execute([(int)$batchId]);
    if ($taken->fetchColumn()) {
        throw new InvalidArgumentException('That bake is already part of an Intensive');
    }
    $db->prepare('UPDATE sfb_intensive_slot_batches SET is_current = 0 WHERE slot_id = ?')->execute([(int)$slot['id']]);
    $db->prepare('INSERT INTO sfb_intensive_slot_batches (slot_id, batch_id, is_current) VALUES (?, ?, ?)')
        ->execute([(int)$slot['id'], (int)$batchId, $current ? 1 : 0]);
    $status = (string)$batch['status'] === 'completed' ? 'awaiting_review' : 'in_progress';
    $db->prepare('UPDATE sfb_intensive_slots SET batch_id = ?, status = ? WHERE id = ?')
        ->execute([(int)$batchId, $status, (int)$slot['id']]);
    $db->prepare('UPDATE sfb_intensive_enrollments SET status = "active" WHERE id = ? AND status NOT IN ("completed", "cancelled", "paused")')
        ->execute([(int)$slot['enrollment_id']]);
    return (int)$batchId;
}

function bakery_sfb_intensive_save_dough_temp(PDO $db, $customerId, $temp) {
    if (!column_exists($db, 'sfb_intensive_slots', 'dough_temp_f')) {
        throw new RuntimeException('Temperature needs a database update (migration 084)');
    }
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled'], true)) {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot || (string)$slot['status'] === 'reviewed' || (string)($slot['batch_status'] ?? '') === 'completed') {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $temp = trim((string)$temp);
    $stored = null;
    if ($temp !== '') {
        if (!is_numeric($temp)) {
            throw new InvalidArgumentException('Enter the dough temperature in degrees');
        }
        $value = (float)$temp;
        if ($value < 40 || $value > 120) {
            throw new InvalidArgumentException('Dough temperature should be between 40 and 120');
        }
        $stored = $value;
    }
    $db->prepare('UPDATE sfb_intensive_slots SET dough_temp_f = ? WHERE id = ?')->execute([$stored, (int)$slot['id']]);
}

function bakery_sfb_intensive_spawn(PDO $db, $name, $pin, $phone = '') {
    if (!bakery_sfb_intensive_ready($db)) {
        throw new RuntimeException('The Intensive needs a database update (migration 083)');
    }
    if (!function_exists('bakery_portal_code_available')) {
        require_once __DIR__ . '/customer_portal.php';
    }
    $program = bakery_sfb_intensive_ensure_catalog($db);
    if (!$program) {
        throw new RuntimeException('The Intensive is not ready');
    }
    $name = trim((string)$name);
    if ($name === '' || mb_strlen($name) > 120) {
        throw new InvalidArgumentException('Enter a name');
    }
    $requestedPin = trim((string)$pin);
    if ($requestedPin === '') {
        $pin = '';
        for ($try = 0; $try < 40; $try++) {
            $candidate = (string)random_int(1000, 9999);
            if (bakery_portal_code_available($db, $candidate)) {
                $pin = $candidate;
                break;
            }
        }
        if ($pin === '') {
            throw new RuntimeException('Could not choose a sign-in code');
        }
    } else {
        $pin = bakery_normalize_login_code($requestedPin);
        if (strlen($pin) !== 4) {
            throw new InvalidArgumentException('The sign-in code needs 4 digits');
        }
        if (!bakery_portal_code_available($db, $pin)) {
            throw new InvalidArgumentException('That sign-in code is already in use');
        }
    }
    $phone = preg_replace('/\D+/', '', (string)$phone);
    if ($phone !== '' && strlen($phone) === 11 && $phone[0] === '1') {
        $phone = substr($phone, 1);
    }
    if ($phone === '') {
        for ($try = 0; $try < 30; $try++) {
            $candidate = '415555' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            if (!bakery_portal_find_by_phone($db, $candidate)) {
                $phone = $candidate;
                break;
            }
        }
    } elseif (strlen($phone) !== 10) {
        throw new InvalidArgumentException('Use a 10-digit phone number, or leave it blank');
    } elseif (bakery_portal_find_by_phone($db, $phone)) {
        throw new InvalidArgumentException('That phone number already has an account');
    }
    if ($phone === '') {
        throw new RuntimeException('Could not reserve a phone number for this baker');
    }
    $fields = [
        'name' => $name,
        'phone' => '+1' . $phone,
        'portal_phone' => '+1' . $phone,
        'portal_phone_key' => $phone,
        'portal_code' => $pin,
        'portal_code_hash' => password_hash($pin, PASSWORD_DEFAULT),
        'portal_enabled' => 1,
        'pricing_tier' => 'retail',
        'is_active' => 1,
    ];
    if (column_exists($db, 'customers', 'sf_baker_enabled')) {
        $fields['sf_baker_enabled'] = 1;
    }
    if (column_exists($db, 'customers', 'sfb_origin')) {
        $fields['sfb_origin'] = 'human';
    }
    $columns = array_keys($fields);
    $db->prepare('INSERT INTO customers (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($fields));
    $customerId = (int)$db->lastInsertId();
    $enrollmentId = bakery_sfb_intensive_enroll($db, $customerId, (int)$program['id'], [
        'start_date' => date('Y-m-d'),
    ]);
    return [
        'enrollment_id' => $enrollmentId,
        'customer_id' => $customerId,
        'name' => $name,
        'pin' => $pin,
    ];
}

function bakery_sfb_intensive_workshop_steps() {
    return ['formula', 'mix', 'bulk', 'shape', 'bake', 'done'];
}

function bakery_sfb_intensive_balance_flours(PDO $db, $formulaId) {
    $flours = [];
    foreach (bakery_sfb_formula_lines($db, $formulaId) as $line) {
        if ((string)$line['line_kind'] === 'flour') {
            $flours[] = $line;
        }
    }
    if (!$flours) {
        return;
    }
    if (count($flours) === 1) {
        $db->prepare('UPDATE sfb_formula_ingredients SET percentage = 100 WHERE id = ?')
            ->execute([(int)$flours[0]['id']]);
        return;
    }
    $sum = 0.0;
    foreach ($flours as $line) {
        $sum += (float)$line['percentage'];
    }
    $running = 0.0;
    $last = count($flours) - 1;
    foreach ($flours as $index => $line) {
        if ($index === $last) {
            $percent = round(100 - $running, 1);
        } elseif ($sum <= 0) {
            $percent = round(100 / count($flours), 1);
            $running += $percent;
        } else {
            $percent = round(100 * ((float)$line['percentage'] / $sum), 1);
            $running += $percent;
        }
        if ($percent < 0.1) {
            $percent = 0.1;
        }
        $db->prepare('UPDATE sfb_formula_ingredients SET percentage = ? WHERE id = ?')
            ->execute([$percent, (int)$line['id']]);
    }
}

function bakery_sfb_intensive_working_formula_id(PDO $db, $customerId) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled'], true)) {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot || (string)$slot['status'] === 'reviewed' || (string)($slot['batch_status'] ?? '') === 'completed') {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $formulaId = (int)$slot['formula_id'] > 0
        ? (int)$slot['formula_id']
        : bakery_sfb_intensive_ensure_slot_formula($db, (int)$slot['id']);
    return [$enrollment, $slot, $formulaId];
}

function bakery_sfb_intensive_add_formula_line(PDO $db, $customerId, $name, $kind, $percent) {
    [$enrollment, $slot, $formulaId] = bakery_sfb_intensive_working_formula_id($db, $customerId);
    $kind = (string)$kind;
    if (!in_array($kind, ['flour', 'water', 'salt', 'starter', 'other'], true)) {
        throw new InvalidArgumentException('Choose flour, water, salt, starter, or other');
    }
    $name = trim((string)$name);
    if ($kind === 'starter' && $name === '') {
        $name = 'Sourdough starter';
    }
    if ($name === '' || mb_strlen($name) > 100) {
        throw new InvalidArgumentException('Name the ingredient');
    }
    $percent = (float)$percent;
    if ($kind === 'flour') {
        $flourCount = 0;
        foreach (bakery_sfb_formula_lines($db, $formulaId) as $line) {
            if ((string)$line['line_kind'] === 'flour') {
                $flourCount++;
            }
        }
        if ($flourCount === 0) {
            $percent = 100;
        } elseif ($percent <= 0 || $percent >= 100) {
            $percent = 20;
        }
    } elseif ($percent <= 0 || $percent > 500) {
        throw new InvalidArgumentException('Enter a percentage for that ingredient');
    }
    $sort = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_formula_ingredients WHERE formula_id = ?');
    $sort->execute([$formulaId]);
    $sortOrder = (int)$sort->fetchColumn();
    if ($kind === 'starter') {
        $starter = bakery_sfb_ensure_starter($db, $customerId, $name);
        $db->prepare(
            'INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, starter_id, percentage, sort_order)
             VALUES (?, NULL, ?, ?, ?)'
        )->execute([$formulaId, (int)$starter['id'], $percent, $sortOrder]);
    } else {
        $ingredientId = null;
        foreach (bakery_sfb_ingredient_options($db, $customerId) as $option) {
            if (mb_strtolower((string)$option['name']) === mb_strtolower($name) && (string)$option['category'] === $kind) {
                $ingredientId = (int)$option['id'];
                break;
            }
        }
        if ($ingredientId === null) {
            $ingredientId = (int)bakery_sfb_create_ingredient($db, $customerId, $name, $kind);
        }
        $db->prepare(
            'INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, starter_id, percentage, sort_order)
             VALUES (?, ?, NULL, ?, ?)'
        )->execute([$formulaId, $ingredientId, $percent, $sortOrder]);
    }
    if ($kind === 'flour') {
        bakery_sfb_intensive_balance_flours($db, $formulaId);
    }
    if ((int)$slot['batch_id'] > 0) {
        bakery_sfb_intensive_refresh_open_snapshot($db, $customerId, (int)$slot['batch_id'], $formulaId);
    }
}

function bakery_sfb_intensive_remove_formula_line(PDO $db, $customerId, $lineId) {
    [$enrollment, $slot, $formulaId] = bakery_sfb_intensive_working_formula_id($db, $customerId);
    $line = null;
    $flourCount = 0;
    foreach (bakery_sfb_formula_lines($db, $formulaId) as $row) {
        if ((string)$row['line_kind'] === 'flour') {
            $flourCount++;
        }
        if ((int)$row['id'] === (int)$lineId) {
            $line = $row;
        }
    }
    if (!$line) {
        throw new InvalidArgumentException('That ingredient is not on this loaf');
    }
    if ((string)$line['line_kind'] === 'flour' && $flourCount <= 1) {
        throw new InvalidArgumentException('The loaf needs flour');
    }
    $db->prepare('DELETE FROM sfb_formula_ingredients WHERE id = ? AND formula_id = ?')
        ->execute([(int)$lineId, $formulaId]);
    bakery_sfb_intensive_balance_flours($db, $formulaId);
    if ((int)$slot['batch_id'] > 0) {
        bakery_sfb_intensive_refresh_open_snapshot($db, $customerId, (int)$slot['batch_id'], $formulaId);
    }
}

function bakery_sfb_intensive_set_workshop_step(PDO $db, $customerId, $step) {
    if (!in_array((string)$step, bakery_sfb_intensive_workshop_steps(), true)) {
        throw new InvalidArgumentException('Unknown step');
    }
    if (!column_exists($db, 'sfb_intensive_slots', 'workshop_step')) {
        throw new RuntimeException('The loaf steps need a database update (migration 085)');
    }
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled', 'paused'], true)) {
        throw new InvalidArgumentException('This week is not open');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot) {
        throw new InvalidArgumentException('There is no loaf to continue');
    }
    if ((string)$step !== 'formula' && (int)$slot['batch_id'] <= 0) {
        bakery_sfb_intensive_start_next_bake($db, $customerId);
        $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    }
    if ((string)$step === 'formula' && (string)($slot['status'] ?? '') === 'reviewed') {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $db->prepare('UPDATE sfb_intensive_slots SET workshop_step = ? WHERE id = ?')
        ->execute([(string)$step, (int)$slot['id']]);
}

function bakery_sfb_intensive_advance_workshop(PDO $db, $customerId) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment) {
        throw new InvalidArgumentException('This week is not open');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot) {
        throw new InvalidArgumentException('There is no loaf to continue');
    }
    $steps = bakery_sfb_intensive_workshop_steps();
    $current = isset($slot['workshop_step']) && (string)$slot['workshop_step'] !== ''
        ? (string)$slot['workshop_step'] : 'formula';
    $index = array_search($current, $steps, true);
    if ($index === false) {
        $index = 0;
    }
    if ($current === 'done') {
        if ((int)$slot['batch_id'] > 0 && (string)($slot['batch_status'] ?? '') === 'in_progress') {
            bakery_sfb_complete_batch($db, $customerId, (int)$slot['batch_id'], 1, '', '');
        }
        $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
        if ($slot && (string)$slot['status'] !== 'reviewed') {
            bakery_sfb_intensive_review_slot($db, (int)$slot['id'], 'This loaf is done.', '');
        }
        return 'done';
    }
    $next = $steps[$index + 1] ?? 'done';
    bakery_sfb_intensive_set_workshop_step($db, $customerId, $next);
    return $next;
}

function bakery_sfb_intensive_customer_batch(PDO $db, $customerId) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled', 'paused'], true)) {
        throw new InvalidArgumentException('This week is not open');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot || (int)$slot['batch_id'] <= 0) {
        throw new InvalidArgumentException('Start the loaf before recording it');
    }
    $batch = bakery_sfb_batch($db, $customerId, (int)$slot['batch_id']);
    if (!$batch) {
        throw new InvalidArgumentException('That loaf is not yours');
    }
    return $batch;
}

function bakery_sfb_intensive_record_bake(PDO $db, $customerId, $action, array $post, array $files = [], $authorName = '') {
    $batch = bakery_sfb_intensive_customer_batch($db, $customerId);
    $batchId = (int)$batch['id'];
    $action = (string)$action;
    if ($action === 'add_discussion') {
        $type = (string)($post['message_type'] ?? 'comment');
        bakery_sfb_add_batch_message(
            $db,
            $batchId,
            'baker',
            (string)$authorName,
            (string)($post['body'] ?? ''),
            $type === 'question' ? 'question' : 'comment',
            $customerId,
            null,
            null,
            (string)($post['phase'] ?? '')
        );
        return;
    }
    if ((string)$batch['status'] !== 'in_progress') {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    if ($action === 'save_mix') {
        bakery_sfb_save_batch_mix(
            $db,
            $customerId,
            $batchId,
            $post['mix_minutes'] ?? 0,
            $post['mix_speed'] ?? '',
            $post['mix_notes'] ?? '',
            $post['mix_completed_at'] ?? ''
        );
        return;
    }
    if ($action === 'save_development') {
        bakery_sfb_save_batch_bulk(
            $db,
            $customerId,
            $batchId,
            $post['bulk_started_at'] ?? '',
            $post['bulk_ended_at'] ?? ''
        );
        return;
    }
    if ($action === 'add_turn') {
        bakery_sfb_add_batch_turn(
            $db,
            $customerId,
            $batchId,
            $post['turn_type'] ?? 'stretch_fold',
            $post['dough_temp_f'] ?? '',
            $post['occurred_at'] ?? '',
            $post['notes'] ?? ''
        );
        return;
    }
    if ($action === 'delete_turn') {
        bakery_sfb_require_editable_batch($db, $customerId, $batchId);
        $db->prepare('DELETE FROM sfb_batch_turns WHERE id = ? AND batch_id = ?')
            ->execute([(int)($post['turn_id'] ?? 0), $batchId]);
        return;
    }
    if ($action === 'save_shape') {
        bakery_sfb_save_batch_shape(
            $db,
            $customerId,
            $batchId,
            $post['shaped_at'] ?? '',
            $post['shape_notes'] ?? ''
        );
        return;
    }
    if ($action === 'save_bake') {
        bakery_sfb_save_batch_bake(
            $db,
            $customerId,
            $batchId,
            $post['oven_temp_f'] ?? '',
            $post['bake_started_at'] ?? '',
            $post['bake_ended_at'] ?? '',
            $post['bake_notes'] ?? ''
        );
        return;
    }
    if ($action === 'add_temp') {
        bakery_sfb_add_batch_temp(
            $db,
            $customerId,
            $batchId,
            $post['temp_f'] ?? 0,
            $post['phase'] ?? 'development',
            $post['measured_at'] ?? '',
            $post['notes'] ?? ''
        );
        return;
    }
    if ($action === 'delete_temp') {
        bakery_sfb_require_editable_batch($db, $customerId, $batchId);
        $db->prepare('DELETE FROM sfb_batch_temps WHERE id = ? AND batch_id = ?')
            ->execute([(int)($post['temp_id'] ?? 0), $batchId]);
        return;
    }
    if ($action === 'upload_photo' || $action === 'delete_photo') {
        require_once __DIR__ . '/sfb_photo_handler.php';
        $handler = new SfbPhotoHandler();
        if ($action === 'upload_photo') {
            $result = $handler->processUpload(
                $db,
                $files['photo'] ?? [],
                $batchId,
                $customerId,
                (string)($post['phase'] ?? 'final'),
                $post['caption'] ?? null
            );
            if (empty($result['success'])) {
                throw new RuntimeException((string)($result['error'] ?? 'Upload failed'));
            }
            return;
        }
        $result = $handler->deletePhoto($db, $batchId, $customerId, (int)($post['photo_id'] ?? 0));
        if (empty($result['success'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Photo not found'));
        }
        return;
    }
    throw new InvalidArgumentException('Unknown loaf action');
}

function bakery_sfb_intensive_ensure_slot_formula(PDO $db, $slotId) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if ((int)$slot['formula_id'] > 0) {
        return (int)$slot['formula_id'];
    }
    $template = bakery_sfb_template($db, 'Basic Sourdough');
    if (!$template) {
        $templates = bakery_sfb_templates($db);
        $template = $templates[0] ?? null;
    }
    if (!$template) {
        throw new RuntimeException('No starting formula is available yet');
    }
    $formulaId = bakery_sfb_copy_template($db, (int)$slot['customer_id'], (int)$template['id']);
    $db->prepare('UPDATE sfb_formulas SET name = ? WHERE id = ?')->execute(['Our loaf', $formulaId]);
    $db->prepare('UPDATE sfb_intensive_slots SET formula_id = ? WHERE id = ?')->execute([$formulaId, (int)$slot['id']]);
    return $formulaId;
}

function bakery_sfb_intensive_working_slot_row(PDO $db, $enrollmentId) {
    bakery_sfb_intensive_sync_slots($db, $enrollmentId);
    $current = null;
    $ready = null;
    $upcoming = null;
    foreach (bakery_sfb_intensive_slots($db, $enrollmentId) as $slot) {
        if ($current === null && in_array((string)$slot['status'], ['in_progress', 'awaiting_review'], true)) {
            $current = $slot;
        }
        if ($ready === null && (string)$slot['status'] === 'ready') {
            $ready = $slot;
        }
        if ($upcoming === null && (string)$slot['status'] === 'upcoming') {
            $upcoming = $slot;
        }
    }
    return $current ?: $ready ?: $upcoming;
}

function bakery_sfb_intensive_refresh_open_snapshot(PDO $db, $customerId, $batchId, $formulaId) {
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    if (!$batch || (string)$batch['status'] !== 'in_progress') {
        return;
    }
    $formula = bakery_sfb_formula($db, $customerId, $formulaId);
    if (!$formula || (int)$formula['customer_id'] !== (int)$customerId) {
        return;
    }
    $db->prepare('DELETE FROM sfb_batch_formula_snapshot_lines WHERE batch_id = ?')->execute([(int)$batchId]);
    $db->prepare('DELETE FROM sfb_batch_formula_snapshots WHERE batch_id = ?')->execute([(int)$batchId]);
    bakery_sfb_capture_batch_formula_snapshot($db, $batchId, $formula);
}

function bakery_sfb_intensive_save_working_formula(PDO $db, $customerId, array $percents) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled'], true)) {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot) {
        throw new InvalidArgumentException('There is no loaf to change');
    }
    if ((string)$slot['status'] === 'reviewed' || (string)($slot['batch_status'] ?? '') === 'completed') {
        throw new InvalidArgumentException('This loaf is already finished');
    }
    $formulaId = (int)$slot['formula_id'] > 0
        ? (int)$slot['formula_id']
        : bakery_sfb_intensive_ensure_slot_formula($db, (int)$slot['id']);
    $changed = false;
    foreach ($percents as $lineId => $percent) {
        $lineId = (int)$lineId;
        $percent = (float)$percent;
        if ($lineId <= 0) {
            continue;
        }
        if ($percent <= 0 || $percent > 500) {
            throw new InvalidArgumentException('Percentages need to be between 0 and 500');
        }
        $stmt = $db->prepare(
            'UPDATE sfb_formula_ingredients SET percentage = ? WHERE id = ? AND formula_id = ?'
        );
        $stmt->execute([$percent, $lineId, $formulaId]);
        if ($stmt->rowCount() > 0) {
            $changed = true;
        }
    }
    bakery_sfb_intensive_balance_flours($db, $formulaId);
    if ((int)$slot['batch_id'] > 0) {
        bakery_sfb_intensive_refresh_open_snapshot($db, $customerId, (int)$slot['batch_id'], $formulaId);
    }
    return $changed;
}

function bakery_sfb_intensive_set_instruction(PDO $db, $enrollmentId, $text) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $text = bakery_sfb_intensive_clip($text, 1000);
    if ($text === '') {
        throw new InvalidArgumentException('Write what they should do now');
    }
    $slot = bakery_sfb_intensive_working_slot_row($db, (int)$enrollment['id']);
    if (!$slot) {
        throw new InvalidArgumentException('There is no loaf to guide');
    }
    $db->prepare('UPDATE sfb_intensive_slots SET objective = ? WHERE id = ?')->execute([$text, (int)$slot['id']]);
    bakery_sfb_intensive_notify(
        $db,
        (int)$enrollment['customer_id'],
        'intensive_instruction',
        'Your next step',
        $text,
        'intensive-instruction:' . (int)$slot['id'] . ':' . date('YmdHis')
    );
}

function bakery_sfb_intensive_start_next_bake(PDO $db, $customerId) {
    $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
    if (!$enrollment || in_array((string)$enrollment['status'], ['completed', 'cancelled', 'paused'], true)) {
        throw new InvalidArgumentException('Your Intensive is not ready for a bake');
    }
    bakery_sfb_assert_human_customer($db, $customerId, 'start an Intensive bake');
    bakery_sfb_intensive_sync_slots($db, (int)$enrollment['id']);
    $ready = null;
    foreach (bakery_sfb_intensive_slots($db, (int)$enrollment['id']) as $slot) {
        if (in_array((string)$slot['status'], ['in_progress'], true)) {
            throw new InvalidArgumentException('This loaf is already open');
        }
    }
    foreach (bakery_sfb_intensive_slots($db, (int)$enrollment['id']) as $slot) {
        if ((int)$slot['batch_id'] > 0) {
            continue;
        }
        if (!in_array((string)$slot['status'], ['ready', 'upcoming'], true)) {
            continue;
        }
        if ((int)$slot['formula_id'] <= 0) {
            bakery_sfb_intensive_ensure_slot_formula($db, (int)$slot['id']);
            $slot = bakery_sfb_intensive_require_slot($db, (int)$slot['id']);
        }
        if ((string)$slot['status'] === 'upcoming') {
            $db->prepare('UPDATE sfb_intensive_slots SET status = "ready" WHERE id = ?')->execute([(int)$slot['id']]);
            $slot['status'] = 'ready';
        }
        $ready = $slot;
        break;
    }
    if (!$ready || (int)($ready['formula_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('The next loaf is not ready yet');
    }
    $attempts = $db->prepare('SELECT COUNT(*) FROM sfb_intensive_slot_batches WHERE slot_id = ?');
    $attempts->execute([(int)$ready['id']]);
    $again = (int)$attempts->fetchColumn() > 0;
    $name = 'Batch ' . (int)$ready['sequence_number'] . ($again ? ' again' : '');
    $formulaId = (int)$ready['formula_id'];
    $owned = bakery_sfb_formula($db, $customerId, $formulaId);
    if (!$owned || (int)$owned['customer_id'] !== (int)$customerId) {
        $formulaId = bakery_sfb_copy_template($db, $customerId, $formulaId);
        $db->prepare('UPDATE sfb_intensive_slots SET formula_id = ? WHERE id = ?')->execute([$formulaId, (int)$ready['id']]);
    }
    $batchId = bakery_sfb_start_batch($db, $customerId, $formulaId, $name, '');
    $ready['enrollment_id'] = (int)$enrollment['id'];
    $ready['customer_id'] = (int)$customerId;
    bakery_sfb_intensive_attach_batch($db, $ready, $batchId, true);
    return $batchId;
}

function bakery_sfb_intensive_link_batch(PDO $db, $slotId, $batchId) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if ((int)$slot['batch_id'] > 0 && (string)$slot['status'] === 'in_progress') {
        throw new InvalidArgumentException('Set this bake aside before linking a different one');
    }
    return bakery_sfb_intensive_attach_batch($db, $slot, $batchId, true);
}

function bakery_sfb_intensive_retry_slot(PDO $db, $slotId) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if ((int)$slot['batch_id'] <= 0) {
        throw new InvalidArgumentException('There is no bake to set aside');
    }
    $db->prepare('UPDATE sfb_intensive_slot_batches SET is_current = 0 WHERE slot_id = ?')->execute([(int)$slot['id']]);
    $db->prepare('UPDATE sfb_intensive_slots SET batch_id = NULL, status = "ready" WHERE id = ?')->execute([(int)$slot['id']]);
    $db->prepare('UPDATE sfb_intensive_enrollments SET status = "awaiting_customer" WHERE id = ? AND status NOT IN ("completed", "cancelled", "paused")')
        ->execute([(int)$slot['enrollment_id']]);
    bakery_sfb_intensive_notify(
        $db,
        (int)$slot['customer_id'],
        'intensive_retry',
        'Ready for another try',
        'Batch ' . (int)$slot['sequence_number'] . ' can start again. The earlier bake stays in your journal.',
        'intensive-retry:' . (int)$slot['id'] . ':' . date('YmdHis')
    );
}

function bakery_sfb_intensive_review_slot(PDO $db, $slotId, $feedback, $internalSummary) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    $feedback = bakery_sfb_intensive_clip($feedback, 4000);
    $internal = bakery_sfb_intensive_clip($internalSummary, 4000);
    if ($feedback === '') {
        throw new InvalidArgumentException('Write the note your baker should see');
    }
    $db->prepare(
        'UPDATE sfb_intensive_slots
         SET customer_feedback = ?, internal_summary = ?, status = "reviewed", reviewed_at = NOW()
         WHERE id = ?'
    )->execute([$feedback, $internal !== '' ? $internal : null, (int)$slot['id']]);
    $status = (int)$slot['sequence_number'] >= 3 ? 'final_review' : 'awaiting_customer';
    $db->prepare('UPDATE sfb_intensive_enrollments SET status = ? WHERE id = ? AND status NOT IN ("completed", "cancelled", "paused")')
        ->execute([$status, (int)$slot['enrollment_id']]);
    bakery_sfb_intensive_notify(
        $db,
        (int)$slot['customer_id'],
        'intensive_feedback',
        'Feedback on Batch ' . (int)$slot['sequence_number'],
        'Your coach left notes on your latest guided bake.',
        'intensive-feedback:' . (int)$slot['id'] . ':' . date('YmdHis')
    );
}

function bakery_sfb_intensive_request_checkpoint(PDO $db, $slotId, $stage, $instruction, $userId, $userName) {
    $slot = bakery_sfb_intensive_require_slot($db, $slotId);
    if (!in_array((string)$stage, bakery_sfb_intensive_stages(), true)) {
        throw new InvalidArgumentException('Choose a moment in the bake');
    }
    $instruction = bakery_sfb_intensive_clip($instruction, 1000);
    if ($instruction === '') {
        throw new InvalidArgumentException('Tell the baker what to show you');
    }
    $db->prepare(
        'INSERT INTO sfb_intensive_checkpoints (slot_id, stage, instruction, status, requested_at, requested_by_user_id)
         VALUES (?, ?, ?, "requested", NOW(), ?)'
    )->execute([(int)$slot['id'], $stage, $instruction, (int)$userId > 0 ? (int)$userId : null]);
    $checkpointId = (int)$db->lastInsertId();
    if ((int)$slot['batch_id'] > 0) {
        $phase = bakery_sfb_intensive_stage_phase($stage);
        bakery_sfb_add_batch_message(
            $db,
            (int)$slot['batch_id'],
            'admin',
            $userName !== '' ? $userName : 'Sour Flour',
            $instruction,
            'comment',
            null,
            (int)$userId > 0 ? (int)$userId : null,
            null,
            $phase
        );
    }
    bakery_sfb_intensive_notify(
        $db,
        (int)$slot['customer_id'],
        'intensive_checkin',
        'A check-in on Batch ' . (int)$slot['sequence_number'],
        $instruction,
        'intensive-checkin:' . $checkpointId
    );
    return $checkpointId;
}

function bakery_sfb_intensive_satisfy_checkpoint(PDO $db, $checkpointId, $customerId = 0) {
    $stmt = $db->prepare(
        'SELECT c.id, c.status, e.customer_id
         FROM sfb_intensive_checkpoints c
         JOIN sfb_intensive_slots s ON s.id = c.slot_id
         JOIN sfb_intensive_enrollments e ON e.id = s.enrollment_id
         WHERE c.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$checkpointId]);
    $row = $stmt->fetch();
    if (!$row || (string)$row['status'] !== 'requested') {
        throw new InvalidArgumentException('That check-in is already done');
    }
    if ((int)$customerId > 0 && (int)$row['customer_id'] !== (int)$customerId) {
        throw new InvalidArgumentException('That check-in is not yours');
    }
    $db->prepare('UPDATE sfb_intensive_checkpoints SET status = "satisfied", satisfied_at = NOW() WHERE id = ?')
        ->execute([(int)$checkpointId]);
}

function bakery_sfb_intensive_add_message(PDO $db, $enrollmentId, $authorType, $authorName, $body, $customerId = null, $userId = null) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment || (string)$enrollment['status'] === 'cancelled') {
        throw new InvalidArgumentException('This Intensive is not open');
    }
    if (!in_array($authorType, ['baker', 'admin'], true)) {
        throw new InvalidArgumentException('Unknown author');
    }
    if ($authorType === 'baker') {
        bakery_sfb_assert_human_customer($db, (int)$enrollment['customer_id'], 'write on the Intensive');
        if ((int)$customerId !== (int)$enrollment['customer_id']) {
            throw new InvalidArgumentException('That note is not yours to write');
        }
    }
    $body = bakery_sfb_intensive_clip($body, 4000);
    $authorName = trim((string)$authorName);
    if ($body === '' || $authorName === '') {
        throw new InvalidArgumentException('Write a note before sending');
    }
    $db->prepare(
        'INSERT INTO sfb_intensive_messages
            (enrollment_id, author_type, author_customer_id, author_user_id, author_name, body, staff_read_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        (int)$enrollment['id'],
        $authorType,
        $authorType === 'baker' ? (int)$customerId : null,
        $authorType === 'admin' && (int)$userId > 0 ? (int)$userId : null,
        mb_substr($authorName, 0, 120),
        $body,
        $authorType === 'admin' ? date('Y-m-d H:i:s') : null,
    ]);
    if ($authorType === 'admin') {
        bakery_sfb_intensive_notify(
            $db,
            (int)$enrollment['customer_id'],
            'intensive_note',
            'A note on your Intensive',
            $body,
            'intensive-note:' . (int)$db->lastInsertId()
        );
    }
    return (int)$db->lastInsertId();
}

function bakery_sfb_intensive_add_internal_note(PDO $db, $enrollmentId, $note, $authorName) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $note = bakery_sfb_intensive_clip($note, 2000);
    if ($note === '') {
        throw new InvalidArgumentException('Write the private note first');
    }
    $line = '[' . date('Y-m-d H:i') . ' ' . trim((string)$authorName) . '] ' . $note;
    $existing = trim((string)$enrollment['internal_notes']);
    $db->prepare('UPDATE sfb_intensive_enrollments SET internal_notes = ? WHERE id = ?')
        ->execute([$existing === '' ? $line : $existing . "\n" . $line, (int)$enrollment['id']]);
}

function bakery_sfb_intensive_update_plan(PDO $db, $enrollmentId, array $input) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $start = trim((string)($input['start_date'] ?? ''));
    $end = trim((string)($input['coaching_end_date'] ?? ''));
    if ($start !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        throw new InvalidArgumentException('Start date must be a calendar date');
    }
    if ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
        throw new InvalidArgumentException('End date must be a calendar date');
    }
    $staffId = (int)($input['assigned_user_id'] ?? 0);
    $goal = array_key_exists('primary_goal', $input)
        ? bakery_sfb_intensive_clip($input['primary_goal'], 2000)
        : (string)$enrollment['primary_goal'];
    $objective = array_key_exists('staff_objective', $input)
        ? bakery_sfb_intensive_clip($input['staff_objective'], 2000)
        : (string)$enrollment['staff_objective'];
    $db->prepare(
        'UPDATE sfb_intensive_enrollments
         SET start_date = ?, coaching_end_date = ?, assigned_user_id = ?, primary_goal = ?, staff_objective = ?
         WHERE id = ?'
    )->execute([
        $start !== '' ? $start : null,
        $end !== '' ? $end : null,
        $staffId > 0 ? $staffId : null,
        $goal !== '' ? $goal : null,
        $objective !== '' ? $objective : null,
        (int)$enrollment['id'],
    ]);
}

function bakery_sfb_intensive_set_status(PDO $db, $enrollmentId, $status) {
    $allowed = ['pending', 'awaiting_staff', 'active', 'paused', 'awaiting_customer', 'final_review', 'completed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('Unknown Intensive state');
    }
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
    if ($status !== 'completed') {
        $db->prepare('UPDATE sfb_intensive_enrollments SET status = ?, completed_at = NULL WHERE id = ?')
            ->execute([$status, (int)$enrollment['id']]);
        return;
    }
    $db->prepare('UPDATE sfb_intensive_enrollments SET status = "completed", completed_at = COALESCE(completed_at, ?) WHERE id = ?')
        ->execute([$completedAt, (int)$enrollment['id']]);
}

function bakery_sfb_intensive_complete(PDO $db, $enrollmentId, array $summary) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $changed = bakery_sfb_intensive_clip($summary['changed'] ?? '', 4000);
    $lessons = bakery_sfb_intensive_clip($summary['lessons'] ?? '', 4000);
    $process = bakery_sfb_intensive_clip($summary['process'] ?? '', 4000);
    $next = bakery_sfb_intensive_clip($summary['next'] ?? '', 4000);
    if ($changed === '' || $lessons === '') {
        throw new InvalidArgumentException('Write what changed and the lessons to keep');
    }
    $db->prepare(
        'UPDATE sfb_intensive_enrollments
         SET summary_changed = ?, summary_lessons = ?, summary_process = ?, summary_next = ?,
             status = "completed", completed_at = COALESCE(completed_at, NOW())
         WHERE id = ?'
    )->execute([
        $changed,
        $lessons,
        $process !== '' ? $process : null,
        $next !== '' ? $next : null,
        (int)$enrollment['id'],
    ]);
    bakery_sfb_intensive_notify(
        $db,
        (int)$enrollment['customer_id'],
        'intensive_complete',
        'Your Intensive summary is ready',
        'Open your Intensive to read what changed and what to bake next.',
        'intensive-complete:' . (int)$enrollment['id']
    );
}

function bakery_sfb_intensive_search_customers(PDO $db, $query) {
    $query = trim((string)$query);
    if ($query === '') {
        return [];
    }
    $like = '%' . str_replace(['%', '_'], '', $query) . '%';
    $digits = preg_replace('/\D+/', '', $query);
    $sql = 'SELECT id, name, phone FROM customers WHERE is_active = 1 AND (name LIKE ? OR phone LIKE ?';
    $params = [$like, $like];
    if ($digits !== '') {
        $sql .= ' OR phone LIKE ?';
        $params[] = '%' . $digits . '%';
    }
    $sql .= ') ORDER BY name LIMIT 15';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function bakery_sfb_intensive_staff_users(PDO $db) {
    if (!table_exists($db, 'users') || !table_exists($db, 'roles')) {
        return [];
    }
    return $db->query(
        'SELECT u.id, u.display_name
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.slug = "administrator"
         ORDER BY u.display_name, u.id'
    )->fetchAll();
}

function bakery_sfb_intensive_attach_purchase(PDO $db, $enrollmentId, $purchaseId) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        throw new InvalidArgumentException('Intensive not found');
    }
    $purchase = bakery_sfb_purchase($db, $purchaseId);
    if (!$purchase || (int)$purchase['customer_id'] !== (int)$enrollment['customer_id'] || (string)$purchase['status'] !== 'paid') {
        throw new InvalidArgumentException('That payment is not a paid purchase for this baker');
    }
    $program = bakery_sfb_intensive_program($db, (int)$enrollment['program_id']);
    if ($program && (int)$program['offering_id'] > 0 && (int)$purchase['offering_id'] !== (int)$program['offering_id']) {
        throw new InvalidArgumentException('That payment is for a different class');
    }
    $db->prepare('UPDATE sfb_intensive_enrollments SET purchase_id = ? WHERE id = ?')
        ->execute([(int)$purchase['id'], (int)$enrollment['id']]);
}

function bakery_sfb_intensive_paid_purchases(PDO $db, $customerId, $offeringId) {
    if (!bakery_sfb_payments_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, offering_title_snapshot, price_cents_snapshot, paid_at
         FROM sfb_offering_purchases
         WHERE customer_id = ? AND offering_id = ? AND status = "paid"
         ORDER BY id DESC'
    );
    $stmt->execute([(int)$customerId, (int)$offeringId]);
    return $stmt->fetchAll();
}

function bakery_sfb_intensive_staff_rows(PDO $db, $includeClosed = false) {
    if (!bakery_sfb_intensive_ready($db)) {
        return [];
    }
    $sql = 'SELECT e.*, c.name AS customer_name, c.phone AS customer_phone, u.display_name AS staff_name
            FROM sfb_intensive_enrollments e
            JOIN customers c ON c.id = e.customer_id
            LEFT JOIN users u ON u.id = e.assigned_user_id';
    if (!$includeClosed) {
        $sql .= ' WHERE e.status NOT IN ("completed", "cancelled")';
    }
    $sql .= ' ORDER BY e.status = "completed", e.updated_at DESC, e.id DESC LIMIT 100';
    $rows = $db->query($sql)->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        bakery_sfb_intensive_sync_slots($db, (int)$row['id']);
        $slots = bakery_sfb_intensive_slots($db, (int)$row['id']);
        $needs = in_array((string)$row['status'], ['awaiting_staff', 'final_review'], true);
        $stage = 'Getting started';
        $next = 'Wait for setup';
        $currentSeq = 0;
        foreach ($slots as $slot) {
            if ((string)$slot['status'] === 'awaiting_review') {
                $needs = true;
                $stage = 'Batch ' . (int)$slot['sequence_number'] . ' needs a look';
                $next = 'Review Batch ' . (int)$slot['sequence_number'];
                $currentSeq = (int)$slot['sequence_number'];
            }
            $openCheck = $db->prepare(
                'SELECT COUNT(*) FROM sfb_intensive_checkpoints WHERE slot_id = ? AND status = "requested"'
            );
            $openCheck->execute([(int)$slot['id']]);
            if ((int)$openCheck->fetchColumn() > 0 && (string)$slot['status'] === 'in_progress') {
                $stage = 'Batch ' . (int)$slot['sequence_number'] . ' in progress';
                $next = 'Check-in requested';
                $currentSeq = (int)$slot['sequence_number'];
            }
        }
        if ($currentSeq === 0) {
            foreach ($slots as $slot) {
                if ((string)$slot['status'] === 'in_progress') {
                    $stage = 'Batch ' . (int)$slot['sequence_number'] . ' in progress';
                    $next = 'Watch the bake';
                    $currentSeq = (int)$slot['sequence_number'];
                    break;
                }
                if ((string)$slot['status'] === 'ready') {
                    $stage = 'Ready for Batch ' . (int)$slot['sequence_number'];
                    $next = 'Waiting on the baker';
                    $currentSeq = (int)$slot['sequence_number'];
                    break;
                }
            }
        }
        if ((string)$row['status'] === 'pending') {
            $stage = 'Getting started';
            $next = 'Waiting on setup';
        } elseif ((string)$row['status'] === 'awaiting_staff' && $currentSeq === 0) {
            $stage = 'Setup to review';
            $next = 'Open Batch 1';
            $needs = true;
        } elseif ((string)$row['status'] === 'paused') {
            $stage = 'Paused';
            $next = 'Resume when ready';
            $needs = false;
        } elseif ((string)$row['status'] === 'completed') {
            $stage = 'Complete';
            $next = 'Summary sent';
            $needs = false;
        } elseif ((string)$row['status'] === 'cancelled') {
            $stage = 'Cancelled';
            $next = 'Closed';
            $needs = false;
        }
        $unread = $db->prepare(
            'SELECT COUNT(*) FROM sfb_intensive_messages
             WHERE enrollment_id = ? AND author_type = "baker" AND staff_read_at IS NULL'
        );
        $unread->execute([(int)$row['id']]);
        if ((int)$unread->fetchColumn() > 0) {
            $needs = true;
            $next = 'New note from the baker';
        }
        $day = '—';
        if (!empty($row['start_date'])) {
            $start = strtotime((string)$row['start_date'] . ' 00:00:00');
            if ($start !== false) {
                $day = (string)max(1, (int)floor((strtotime(date('Y-m-d')) - $start) / 86400) + 1);
            }
        }
        $out[] = [
            'id' => (int)$row['id'],
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
            'customer_phone' => (string)($row['customer_phone'] ?? ''),
            'staff_name' => (string)($row['staff_name'] ?? ''),
            'status' => (string)$row['status'],
            'day' => $day,
            'stage' => $stage,
            'next' => $next,
            'needs' => $needs,
            'updated_at' => (string)$row['updated_at'],
            'coaching_end_date' => (string)($row['coaching_end_date'] ?? ''),
        ];
    }
    return $out;
}

function bakery_sfb_intensive_staff_detail(PDO $db, $enrollmentId) {
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    if (!$enrollment) {
        return null;
    }
    bakery_sfb_intensive_sync_slots($db, (int)$enrollment['id']);
    $enrollment = bakery_sfb_intensive_enrollment($db, $enrollmentId);
    $db->prepare(
        'UPDATE sfb_intensive_messages SET staff_read_at = NOW()
         WHERE enrollment_id = ? AND author_type = "baker" AND staff_read_at IS NULL'
    )->execute([(int)$enrollment['id']]);
    $customer = $db->prepare('SELECT id, name, phone FROM customers WHERE id = ? LIMIT 1');
    $customer->execute([(int)$enrollment['customer_id']]);
    $slots = [];
    foreach (bakery_sfb_intensive_slots($db, (int)$enrollment['id']) as $slot) {
        $attempts = $db->prepare(
            'SELECT sb.batch_id, sb.is_current, b.name, b.status
             FROM sfb_intensive_slot_batches sb
             JOIN sfb_batches b ON b.id = sb.batch_id
             WHERE sb.slot_id = ?
             ORDER BY sb.id'
        );
        $attempts->execute([(int)$slot['id']]);
        $slot['attempts'] = $attempts->fetchAll();
        $slot['checkpoints'] = bakery_sfb_intensive_checkpoints($db, (int)$slot['id']);
        $slots[] = $slot;
    }
    $batches = bakery_sfb_batches($db, (int)$enrollment['customer_id'], 20);
    $program = bakery_sfb_intensive_program($db, (int)$enrollment['program_id']);
    return [
        'enrollment' => $enrollment,
        'customer' => $customer->fetch() ?: ['id' => (int)$enrollment['customer_id'], 'name' => '', 'phone' => ''],
        'program' => $program,
        'slots' => $slots,
        'messages' => bakery_sfb_intensive_messages($db, (int)$enrollment['id']),
        'templates' => bakery_sfb_templates($db),
        'formulas' => bakery_sfb_formulas($db, (int)$enrollment['customer_id']),
        'batches' => $batches,
        'staff' => bakery_sfb_intensive_staff_users($db),
        'purchases' => $program ? bakery_sfb_intensive_paid_purchases($db, (int)$enrollment['customer_id'], (int)$program['offering_id']) : [],
    ];
}
