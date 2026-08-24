<?php
/**
 * Seed demo Bread Education content: one labeled course with lessons and
 * steps, plus one sample class offering.
 *
 * CLI only. Local/test databases ONLY - refuses anything else via the same
 * guard the test suites use. Idempotent: Demo:* fixtures are removed by
 * exact title and re-inserted through the bakery_sfb_* helpers, never raw
 * SQL writes.
 *
 * Usage: php scripts/seed_education_demo.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require __DIR__ . '/../tests/isolate_test_db.php';
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

require_once dirname(__DIR__) . '/includes/sf_baker.php';

if (!bakery_sfb_learning_ready($db)) {
    echo "NOTE  learning tables missing - run scripts/run_migrations.php against this database first\n";
    exit(1);
}

const DEMO_COURSE_TITLE = 'Demo: First Loaf Walkthrough';
const DEMO_OFFERING_TITLE = 'Demo: Sourdough Start Class';

// -- Deterministic pre-clean (063 tables carry no FKs; cascade manually) -----
$oldCourseIds = [];
$find = $db->prepare('SELECT id FROM sfb_courses WHERE title = ?');
$find->execute([DEMO_COURSE_TITLE]);
foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $oldCourseId) {
    $oldCourseIds[] = (int)$oldCourseId;
}
if ($oldCourseIds) {
    $in = implode(',', array_fill(0, count($oldCourseIds), '?'));
    $db->prepare("DELETE FROM sfb_lesson_steps WHERE lesson_id IN (SELECT id FROM sfb_course_lessons WHERE course_id IN ($in))")
        ->execute($oldCourseIds);
    $db->prepare("DELETE FROM sfb_course_lessons WHERE course_id IN ($in)")->execute($oldCourseIds);
    $db->prepare("DELETE FROM sfb_courses WHERE id IN ($in)")->execute($oldCourseIds);
}
$db->prepare('DELETE FROM sfb_offerings WHERE title = ?')->execute([DEMO_OFFERING_TITLE]);

// -- Demo course -------------------------------------------------------------
$courseId = bakery_sfb_create_course(
    $db,
    DEMO_COURSE_TITLE,
    'Sample content for demos and tests. Seeded by scripts/seed_education_demo.php.'
);
$lessonOne = bakery_sfb_create_lesson($db, $courseId, 'Wake your starter', 'Feed it, keep it warm, wait for the peak.', '');
bakery_sfb_add_lesson_step($db, $lessonOne, 'Feed equal weights flour and water - a 1:1:1 jar at 70-75 F peaks in 4-6 hours.', null, 'photo');
bakery_sfb_add_lesson_step($db, $lessonOne, 'Peak looks domed and smells sour-sweet; that is your mix window.', null, 'photo');
$lessonTwo = bakery_sfb_create_lesson($db, $courseId, 'Mix and fold', 'Build strength without a machine.', '');
bakery_sfb_add_lesson_step($db, $lessonTwo, 'Mix to 75% hydration: 100% flour, 75% water, 2% salt, 20% levain.', null, 'photo');
bakery_sfb_add_lesson_step($db, $lessonTwo, 'Four coil folds in the first two hours of bulk; dough should jiggle, not spread.', null, 'photo');

// -- Demo offering -----------------------------------------------------------
$offeringId = bakery_sfb_create_offering(
    $db,
    DEMO_OFFERING_TITLE,
    45.00,
    'class',
    'Sample three-hour hands-on class used for demos, staging checks, and gating tests.',
    null
);

echo "Seeded demo content:\n";
echo "  - course '" . DEMO_COURSE_TITLE . "' (#{$courseId}) with 2 lessons / 4 steps\n";
echo "  - offering '" . DEMO_OFFERING_TITLE . "' (#{$offeringId}) at \$45.00\n";
