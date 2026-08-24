<?php
/**
 * Bread education demo data seeder (CLI only).
 *
 * Creates safe synthetic demo data for the bread education surface on a
 * disposable local/test database: one demo course with lessons and steps,
 * two synthetic demo bakers, and course progress rows attributed to those
 * synthetic bakers.
 *
 * Demo purchases are intentionally NOT written. sfb_offering_purchases has
 * no per-row origin column, so a purchase row cannot be labeled
 * origin=synthetic — and synthetics never pay on the education surface.
 *
 * Safety:
 *  - Refuses loudly unless the live connection is bakerysf_test or another
 *    explicitly local/test database name (--allow-db=NAME, name must contain
 *    "test"). Never bakerysf_local, never production.
 *  - Every business-table write goes through existing bakery_* helpers;
 *    no raw INSERT into business tables. (--reset uses targeted DELETEs by
 *    tracked ids only; no delete helpers exist for course content.)
 *  - Tracks its own ids in storage/education_demo_seeder_state.json so
 *    --reset removes ONLY rows this seeder created.
 *
 * Usage:
 *   php scripts/education_demo_seeder.php
 *   php scripts/education_demo_seeder.php --json
 *   php scripts/education_demo_seeder.php --reset
 */
define('ACCESS_ALLOWED', true);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/sf_baker.php';
require_once $root . '/includes/sfb_agent.php';

$json = in_array('--json', $argv, true);
$reset = in_array('--reset', $argv, true);

$allowedNames = ['bakerysf_test'];
foreach ($argv as $arg) {
    if (preg_match('/^--allow-db=([A-Za-z0-9_]+)$/', $arg, $m)) {
        if (stripos($m[1], 'test') === false) {
            fwrite(STDERR, "Refusing target database '{$m[1]}': only database names containing \"test\" may be allowed\n");
            exit(1);
        }
        $allowedNames[] = strtolower($m[1]);
    }
}

try {
    $db = check_mysql_connection();
    $GLOBALS['db'] = $db;
    try {
        bakery_assert_local_connection($db, $allowedNames);
    } catch (Throwable $e) {
        throw new RuntimeException(
            'education_demo_seeder refuses to run: ' . $e->getMessage()
            . ' — this seeder writes demo rows, so it only ever targets a disposable local/test database'
        );
    }
    $actualDb = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if (!in_array($actualDb, $allowedNames, true)) {
        throw new RuntimeException("education_demo_seeder refuses to run: connected to '{$actualDb}', which is not an allowed local/test database");
    }

    $stateFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'education_demo_seeder_state.json';
    $state = is_file($stateFile) ? json_decode((string)file_get_contents($stateFile), true) : [];
    if (!is_array($state)) {
        $state = [];
    }
    foreach (['course_id' => 0, 'lesson_ids' => [], 'step_ids' => [], 'customer_ids' => [], 'progress' => []] as $field => $default) {
        if (!isset($state[$field]) || !is_array($state[$field]) && !is_int($default)) {
            $state[$field] = $default;
        }
        if ($field === 'course_id' && (!isset($state[$field]) || !is_numeric($state[$field]))) {
            $state[$field] = 0;
        }
    }

    $actions = [];

    if ($reset) {
        $delProgress = $db->prepare('DELETE FROM sfb_lesson_progress WHERE customer_id = ? AND lesson_id = ?');
        foreach ($state['progress'] as $pair) {
            $delProgress->execute([(int)$pair[0], (int)$pair[1]]);
        }
        $delProgressByCustomer = $db->prepare('DELETE FROM sfb_lesson_progress WHERE customer_id = ?');
        $progressRemoved = 0;
        foreach ($state['customer_ids'] as $customerId) {
            $delProgressByCustomer->execute([(int)$customerId]);
            $progressRemoved += $delProgressByCustomer->rowCount();
        }
        if ($progressRemoved > 0) {
            $actions[] = "removed {$progressRemoved} demo progress row(s)";
        }

        $delStep = $db->prepare('DELETE FROM sfb_lesson_steps WHERE id = ?');
        foreach ($state['step_ids'] as $stepId) {
            $delStep->execute([(int)$stepId]);
        }
        if ($state['step_ids']) {
            $actions[] = 'removed ' . count($state['step_ids']) . " demo step(s)";
        }

        $delLesson = $db->prepare('DELETE FROM sfb_course_lessons WHERE id = ?');
        foreach ($state['lesson_ids'] as $lessonId) {
            $delLesson->execute([(int)$lessonId]);
        }
        if ($state['lesson_ids']) {
            $actions[] = 'removed ' . count($state['lesson_ids']) . ' demo lesson(s)';
        }

        if ((int)$state['course_id'] > 0) {
            $delCourse = $db->prepare('DELETE FROM sfb_courses WHERE id = ?');
            $delCourse->execute([(int)$state['course_id']]);
            if ($delCourse->rowCount() > 0) {
                $actions[] = 'removed demo course #' . (int)$state['course_id'];
            }
        }

        $delCustomer = $db->prepare('DELETE FROM customers WHERE id = ?');
        foreach ($state['customer_ids'] as $customerId) {
            $delCustomer->execute([(int)$customerId]);
        }
        if ($state['customer_ids']) {
            $actions[] = 'removed ' . count($state['customer_ids']) . ' demo baker(s)';
        }

        @unlink($stateFile);
        $summary = ['ok' => true, 'mode' => 'reset', 'database' => $actualDb, 'actions' => $actions];
        if ($json) {
            echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "Reset complete on {$actualDb}\n";
            foreach ($actions as $line) {
                echo "  - {$line}\n";
            }
            if (!$actions) {
                echo "Nothing tracked to remove\n";
            }
        }
        exit(0);
    }

    // ── Demo content: courses → lessons → steps via admin authoring helpers ──
    $demoCourseTitle = '[Demo] Sourdough Starter Basics';
    $courseId = 0;
    foreach (bakery_sfb_courses($db, true) as $course) {
        if ((string)$course['title'] === $demoCourseTitle) {
            $courseId = (int)$course['id'];
            break;
        }
    }
    if ($courseId === 0) {
        $courseId = bakery_sfb_create_course(
            $db,
            $demoCourseTitle,
            'Synthetic demo course seeded by scripts/education_demo_seeder.php on a disposable test database.'
        );
        $actions[] = "created demo course #{$courseId} ({$demoCourseTitle})";
    }
    $state['course_id'] = $courseId;

    $lessonPlan = [
        [
            'title' => '[Demo] Feeding rhythm',
            'steps' => [
                'Feed your starter once a day at the same time so the rise becomes predictable.',
                'A ripe starter doubles within 4-6 hours of feeding at room temperature.',
            ],
        ],
        [
            'title' => '[Demo] Reading readiness',
            'steps' => [
                'Ready dough smells lightly sour and shows bubbles across the surface.',
                'The float test: a small piece floats in water when the starter is at peak.',
            ],
        ],
    ];

    foreach ($lessonPlan as $plan) {
        $lessonId = 0;
        foreach (bakery_sfb_course_lessons($db, $courseId, true) as $lesson) {
            if ((string)$lesson['title'] === $plan['title']) {
                $lessonId = (int)$lesson['id'];
                break;
            }
        }
        if ($lessonId === 0) {
            $lessonId = bakery_sfb_create_lesson($db, $courseId, $plan['title'], 'Demo lesson seeded for the bread education surface.');
            $actions[] = "created demo lesson #{$lessonId} ({$plan['title']})";
        }
        if (!in_array($lessonId, array_map('intval', $state['lesson_ids']), true)) {
            $state['lesson_ids'][] = $lessonId;
        }

        $knownSteps = [];
        foreach (bakery_sfb_lesson_steps($db, $lessonId) as $step) {
            $knownSteps[(string)$step['body_text']] = (int)$step['id'];
        }
        foreach ($plan['steps'] as $stepText) {
            if (isset($knownSteps[$stepText])) {
                continue;
            }
            $stepId = bakery_sfb_add_lesson_step($db, $lessonId, $stepText);
            $actions[] = "added demo step #{$stepId}";
            $knownSteps[$stepText] = $stepId;
        }
        if (!in_array($lessonId, array_map('intval', $state['lesson_ids']), true)) {
            continue;
        }
        foreach (array_values($knownSteps) as $stepId) {
            if (!in_array($stepId, array_map('intval', $state['step_ids']), true)) {
                $state['step_ids'][] = $stepId;
            }
        }
    }

    // ── Synthetic demo bakers via the SFAdmin agent helper ──
    $bakerIds = [];
    foreach (['Education Demo Baker A', 'Education Demo Baker B'] as $name) {
        $created = null;
        // The test database is shared; a concurrent writer can win the unique
        // name race between lookup and insert. Retry once after a pause.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $created = bakery_sfb_agent_create_baker($db, $name, '', [
                    'origin' => 'synthetic',
                    'persona' => 'beginner',
                    'locale' => 'en',
                ]);
                break;
            } catch (Throwable $e) {
                $isDuplicate = strpos($e->getMessage(), '1062') !== false
                    || stripos($e->getMessage(), 'duplicate') !== false;
                if (!$isDuplicate || $attempt === 1) {
                    throw $e;
                }
                sleep(1);
            }
        }
        $customerId = (int)$created['customer']['id'];
        $bakerIds[] = $customerId;
        if (!in_array($customerId, array_map('intval', $state['customer_ids']), true)) {
            $state['customer_ids'][] = $customerId;
        }
        $actions[] = $created['created']
            ? "created synthetic demo baker #{$customerId} ({$name}, origin=synthetic)"
            : "reused synthetic demo baker #{$customerId} ({$name}, origin=synthetic)";
    }

    // ── Course progress rows via the portal progress helper ──
    // Attribution rides on customers.sfb_origin=synthetic; the progress table
    // itself has no origin column, so these rows stay traceable through the baker.
    foreach (bakery_sfb_course_lessons($db, $courseId) as $lesson) {
        $steps = bakery_sfb_lesson_steps($db, (int)$lesson['id']);
        foreach ($bakerIds as $index => $customerId) {
            $doneIds = bakery_sfb_lesson_progress($db, $customerId, (int)$lesson['id']);
            foreach (array_slice($steps, 0, count($doneIds) + 1) as $step) {
                if (in_array((int)$step['id'], array_map('intval', $doneIds), true)) {
                    continue;
                }
                try {
                    $nowDone = bakery_sfb_toggle_lesson_progress($db, $customerId, (int)$lesson['id'], (int)$step['id']);
                    if (!$nowDone) {
                        continue;
                    }
                    $state['progress'][] = [$customerId, (int)$lesson['id']];
                    $actions[] = "marked step #{$step['id']} done for baker #{$customerId}";
                    $doneIds[] = (int)$step['id'];
                } catch (Throwable $e) {
                    // Humans only on education surfaces: the helper refuses
                    // synthetic-origin progress writes. Record that honestly.
                    $actions[] = "progress refused for baker #{$customerId} step #{$step['id']} ({$e->getMessage()})";
                    break;
                }
            }
        }
    }

    // ── Demo purchases: skipped on purpose ──
    $purchaseNote = 'sfb_offering_purchases has no origin column, so zero demo purchases were written '
        . '(a purchase cannot be labeled origin=synthetic, and synthetics never pay on the education surface).';

    file_put_contents(
        $stateFile,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $summary = [
        'ok' => true,
        'mode' => 'seed',
        'database' => $actualDb,
        'course_id' => (int)$state['course_id'],
        'lesson_ids' => array_map('intval', $state['lesson_ids']),
        'step_ids' => array_map('intval', $state['step_ids']),
        'customer_ids' => array_map('intval', $state['customer_ids']),
        'purchases_written' => 0,
        'purchase_note' => $purchaseNote,
        'manifest' => 'storage/education_demo_seeder_state.json',
        'actions' => $actions,
    ];
    if ($json) {
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "Education demo seed complete on {$actualDb}\n";
        foreach ($actions as $line) {
            echo "  - {$line}\n";
        }
        echo "Purchases skipped: {$purchaseNote}\n";
        echo "Tracked ids saved to storage/education_demo_seeder_state.json (--reset removes exactly these rows)\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Education demo seeder failed: ' . $e->getMessage() . "\n");
    exit(1);
}
