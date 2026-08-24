<?php
/**
 * Export Bread Education content (courses -> lessons -> steps, plus offerings)
 * as pretty-printed JSON for safekeeping or migration between environments.
 *
 * Media FILES are not exported - media_path references are listed only.
 *
 * CLI only. Local/test databases ONLY (same guard as the test suites).
 * Pure SELECTs; writes nothing except the optional --out file.
 *
 * Usage:
 *   php scripts/export_education_content.php                 # stdout
 *   php scripts/export_education_content.php --out=<path>    # storage/exports/...
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

$outArg = '';
foreach (argv_or_empty() as $arg) {
    if (strpos($arg, '--out=') === 0) {
        $outArg = substr($arg, strlen('--out='));
    }
}

function argv_or_empty(): array {
    global $argv;
    return is_array($argv) ? $argv : [];
}

$coursesOut = [];
foreach (bakery_sfb_courses($db, true) as $course) {
    $lessonsOut = [];
    foreach (bakery_sfb_course_lessons($db, (int)$course['id'], true) as $lesson) {
        $stepsOut = [];
        foreach (bakery_sfb_lesson_steps($db, (int)$lesson['id']) as $step) {
            $stepsOut[] = [
                'body_text' => $step['body_text'],
                'media_path' => $step['media_path'],
                'media_kind' => $step['media_kind'],
                'sort_order' => (int)$step['sort_order'],
            ];
        }
        $lessonsOut[] = [
            'title' => $lesson['title'],
            'summary' => $lesson['summary'],
            'external_url' => $lesson['external_url'],
            'sort_order' => (int)$lesson['sort_order'],
            'is_active' => (int)$lesson['is_active'],
            'steps' => $stepsOut,
        ];
    }
    $coursesOut[] = [
        'title' => $course['title'],
        'description' => $course['description'],
        'sort_order' => (int)$course['sort_order'],
        'is_active' => (int)$course['is_active'],
        'required_offering_id' => isset($course['required_offering_id']) ? (int)$course['required_offering_id'] : null,
        'template_formula_id' => isset($course['template_formula_id']) ? (int)$course['template_formula_id'] : null,
        'lessons' => $lessonsOut,
    ];
}

$offeringsOut = [];
if (bakery_sfb_payments_ready($db)) {
    $rows = $db->query(
        'SELECT title, description, price_cents, currency, kind, units, entitlement_days, sort_order, is_active
         FROM sfb_offerings ORDER BY sort_order, id'
    )->fetchAll();
    foreach ($rows as $row) {
        $offeringsOut[] = [
            'title' => $row['title'],
            'description' => $row['description'],
            'price_cents' => (int)$row['price_cents'],
            'currency' => $row['currency'],
            'kind' => $row['kind'],
            'units' => $row['units'] !== null ? (int)$row['units'] : null,
            'entitlement_days' => $row['entitlement_days'] !== null ? (int)$row['entitlement_days'] : null,
            'sort_order' => (int)$row['sort_order'],
            'is_active' => (int)$row['is_active'],
        ];
    }
}

$export = [
    'exported_at' => date('c'),
    'source_db' => defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'unknown'),
    'note' => 'Media files are not exported; media_path references are listed per step.',
    'courses' => $coursesOut,
    'offerings' => $offeringsOut,
];
$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}

if ($outArg !== '') {
    $dir = dirname($outArg);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        fwrite(STDERR, "Could not create output directory: {$dir}\n");
        exit(1);
    }
    file_put_contents($outArg, $json . "\n");
    echo "Exported " . count($coursesOut) . " course(s), " . count($offeringsOut) . " offering(s) -> {$outArg}\n";
} else {
    echo $json . "\n";
}
