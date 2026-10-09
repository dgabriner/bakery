<?php
/**
 * Next sell day for the survey hub.
 *
 * Saturday market: a standing order (and a dated order) with no standing route
 * must still be the next sell day. Friday 2026-10-09 opens 2026-10-10, and the
 * hub says "tomorrow" only when that date is literally the next calendar day.
 *
 * Usage: php tests/run_survey_sell_day_tests.php
 * Database section: bakerysf_test only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/survey_store_verify.php';

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

$friday = '2026-10-09';
$saturday = '2026-10-10';
$sunday = '2026-10-11';

$assert(
    bakery_survey_next_sell_date($friday, [1, 2, 5, 7], [$saturday]) === $saturday,
    'Friday with a dated Saturday market opens 2026-10-10, not Sunday'
);
$assert(
    bakery_survey_next_sell_date($friday, [1, 2, 5, 7], []) === $sunday,
    'Friday without a Saturday market still opens Sunday when Sunday is a sell weekday'
);
$assert(
    bakery_survey_next_delivery_date($friday, [1, 2, 5, 7]) === $sunday,
    'weekday-only helper still skips Saturday when that weekday is not a sell day'
);

$satLabel = bakery_survey_sell_day_label($friday, $saturday);
$assert(
    $satLabel['literal_tomorrow'] === true && (int)$satLabel['iso_weekday'] === 6,
    'Saturday after Friday is literally tomorrow'
);
$sunLabel = bakery_survey_sell_day_label($friday, $sunday);
$assert(
    $sunLabel['literal_tomorrow'] === false && (int)$sunLabel['iso_weekday'] === 7,
    'Sunday after Friday is not tomorrow'
);
$assert(
    bakery_survey_hub_title_text(
        $friday,
        $saturday,
        'Tomorrow route surveys',
        [6 => 'Saturday', 7 => 'Sunday'],
        ':day route surveys'
    ) === 'Tomorrow route surveys',
    'hub title stays tomorrow when the sell day is the next calendar day'
);
$assert(
    bakery_survey_hub_title_text(
        $friday,
        $sunday,
        'Tomorrow route surveys',
        [6 => 'Saturday', 7 => 'Sunday'],
        ':day route surveys'
    ) === 'Sunday route surveys',
    'hub title uses the weekday name when the sell day is not tomorrow'
);
$assert(
    bakery_survey_hub_title_text(
        $friday,
        $sunday,
        'Encuestas de ruta de manana',
        [7 => 'domingo'],
        'Encuestas de ruta del :day'
    ) === 'Encuestas de ruta del domingo',
    'Spanish hub title names the weekday when it is not tomorrow'
);

require __DIR__ . '/isolate_test_db.php';
require $root . '/includes/config.php';
require $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$saturdayRoutes = (int)$db->query(
    'SELECT COUNT(*) FROM standing_routes
     WHERE CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END = 6'
)->fetchColumn();
$saturdayOrders = (int)$db->query(
    'SELECT COUNT(*) FROM standing_orders so
     JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
     WHERE CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END = 6'
)->fetchColumn();
$assert($saturdayRoutes === 0, 'fixture Saturday market has no standing route');
$assert($saturdayOrders > 0, 'fixture Saturday market has a standing order');

$weekdays = bakery_survey_delivery_weekdays($db);
$assert(in_array(6, $weekdays, true), 'Saturday market order is a sell weekday without a standing route');
$assert(
    bakery_survey_next_sell_date_from_db($db, $friday) === $saturday,
    'Friday 2026-10-09 next sell day is the Saturday market 2026-10-10'
);

$probeName = 'Survey Sell Day Probe';
$probeId = 0;
$probeOrderId = 0;
try {
    $db->prepare('DELETE do FROM daily_orders do JOIN customers c ON c.id = do.customer_id WHERE c.name = ?')
        ->execute([$probeName]);
    $db->prepare('DELETE FROM customers WHERE name = ?')->execute([$probeName]);
    $db->prepare('INSERT INTO customers (name) VALUES (?)')->execute([$probeName]);
    $probeId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status) VALUES (?, ?, ?)')
        ->execute([$probeId, $saturday, 'pending']);
    $probeOrderId = (int)$db->lastInsertId();

    $dated = bakery_survey_dated_sell_dates($db, $friday);
    $assert(in_array($saturday, $dated, true), 'dated Saturday market order is a sell date');
    $assert(
        bakery_survey_next_sell_date($friday, [7], $dated) === $saturday,
        'a dated Saturday order is the next sell day when the only standing weekday is Sunday'
    );
} finally {
    if ($probeOrderId > 0) {
        $db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$probeOrderId]);
    }
    if ($probeId > 0) {
        $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$probeId]);
    }
}

$surveyPhp = (string)file_get_contents($root . '/survey.php');
$assert(
    strpos($surveyPhp, 'bakery_survey_next_sell_date_from_db') !== false,
    'survey hub asks for the next sell day, not standing-route weekdays alone'
);
$assert(
    strpos($surveyPhp, 'bakery_survey_hub_page_title') !== false,
    'survey hub labels a non-tomorrow sell day with the weekday name'
);
$assert(
    strpos($surveyPhp, "includes/header.php") !== false && strpos($surveyPhp, "includes/nav.php") !== false,
    'logged-in survey hub uses the app header'
);
$assert(
    strpos($surveyPhp, 'background:#2c5aa0') === false,
    'survey hub no longer uses the one-off blue stylesheet'
);

echo "\nSurvey sell day: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
