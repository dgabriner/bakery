<?php
/**
 * Driver QA DQ-59 through DQ-65.
 *
 * Usage: php tests/run_driver_qa_dq59_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';

/** @var PDO $db */
$db = require __DIR__ . '/harness.php';
bakery_assert_local_test_target($db);
require_once $root . '/includes/pack_list.php';
require_once $root . '/includes/survey_store_verify.php';
require_once $root . '/includes/driver_route_snapshot.php';

if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}

$failures = 0;

function dq_assert(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        return;
    }
    echo "FAIL  {$label}\n";
    $failures++;
}

$packSrc = (string)file_get_contents($root . '/pack_list.php');
$surveySrc = (string)file_get_contents($root . '/survey.php');
$driverSrc = (string)file_get_contents($root . '/driver.php');

dq_assert(
    bakery_pack_list_default_date(true, '2026-11-26') === '2026-11-26',
    'driver pack list defaults to today'
);
dq_assert(
    bakery_pack_list_default_date(false, '2026-11-26') === '2026-11-27',
    'kitchen and manager pack list still defaults to tomorrow'
);
dq_assert(
    strpos($packSrc, 'bakery_pack_list_default_date($packForDriver)') !== false,
    'pack list page uses the driver today default'
);

$productionActions = ['backfill_production', 'backfill_day', 'match_supposed', 'match_all_supposed', 'set_on_hand', 'seed_driver_loads'];
foreach ($productionActions as $action) {
    dq_assert(
        bakery_pack_list_production_post_blocked(['role_slug' => 'driver'], $action) === true,
        'driver role is refused for pack list ' . $action
    );
    dq_assert(
        bakery_pack_list_production_post_blocked(['role_slug' => 'manager'], $action) === false,
        'manager may still post pack list ' . $action
    );
    dq_assert(
        bakery_pack_list_production_post_blocked(['role_slug' => 'baker'], $action) === false,
        'baker may still post pack list ' . $action
    );
}
dq_assert(
    bakery_pack_list_production_post_blocked(['role_slug' => 'driver'], 'driver_save_load') === false,
    'driver van-load edit is not a pack-list production post'
);
dq_assert(
    strpos($packSrc, 'bakery_pack_list_production_post_blocked') !== false,
    'pack list refuses those posts before they run'
);
dq_assert(strpos($packSrc, 'pack-van-list') !== false, 'driver pack list renders the van checklist');
dq_assert(strpos($packSrc, 'pack-page--driver') !== false, 'driver pack list uses the compact page');
dq_assert(strpos($packSrc, 'language_switch.php') !== false, 'driver pack list keeps the language toggle');
dq_assert(strpos($packSrc, 'substr($dayName, 0, 3)') === false, 'day chips no longer slice weekday names by bytes');
dq_assert(strpos($packSrc, 'min-block-size: 44px') !== false, 'driver pack controls keep a 44px tap target');

if (function_exists('bakery_set_locale')) {
    bakery_set_locale('es', false);
}
$chips = bakery_pack_list_day_chip_labels();
dq_assert(
    $chips === [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'],
    'Spanish day chips include Mié and Sáb'
);
dq_assert(
    bakery_t('daily_run.commit_plan') === 'Confirmar plan de producción',
    'Spanish commit button says Confirmar plan de producción'
);
if (function_exists('bakery_set_locale')) {
    bakery_set_locale('en', false);
}

dq_assert(bakery_survey_hub_open_button_for_role('driver') === false, 'survey has no open button for a driver');
dq_assert(bakery_survey_hub_open_button_for_role('driver_assistant') === false, 'survey has no open button for a driver assistant');
dq_assert(bakery_survey_hub_open_button_for_role('manager') === true, 'managers still get the open-surveys button');
dq_assert(bakery_survey_hub_open_button_for_role('baker') === true, 'kitchen staff still get the open-surveys button');
dq_assert(strpos($surveySrc, 'bakery_survey_hub_open_button_for_role') !== false, 'survey page asks before showing the open button');
dq_assert(strpos($surveySrc, 'survey.hub_opened_by_hq') !== false, 'a driver is told that headquarters opens surveys');
dq_assert(strpos($surveySrc, 'survey-hub-footer') !== false, 'open surveys and survey center are spaced in one footer');
dq_assert(
    bakery_survey_hub_date_for_role('driver', '2026-11-26', [1, 2, 3, 4, 5, 6, 7], []) === '2026-11-26',
    'a driver survey defaults to today when today delivers'
);
dq_assert(
    bakery_survey_hub_date_for_role('manager', '2026-11-26', [1, 2, 3, 4, 5, 6, 7], [], false) === '2026-11-27',
    'a manager outside driver mode still starts on the next sell day'
);
dq_assert(
    bakery_survey_hub_date_for_role('driver', '2026-11-26', [5], []) === '2026-11-27',
    'a driver survey moves to the next delivery day when today does not deliver'
);
dq_assert(
    bakery_survey_hub_date_for_role('driver', '2026-11-26', [5], ['2026-11-26']) === '2026-11-26',
    'dated work on a closed weekday keeps the driver on today'
);
dq_assert(
    bakery_survey_hub_date_for_role('manager', '2026-11-26', [1, 2, 3, 4, 5], [], true) === '2026-11-26',
    'a manager who is driving keeps today\'s sell day'
);

$carryDay = bakery_driver_route_day_state([
    ['customer_name' => 'Casa Guadalupe', 'carry_label' => 'Left from yesterday'],
    ['customer_name' => 'Civic Center', 'carry_label' => 'Left from yesterday'],
]);
$emptyDay = bakery_driver_route_day_state([]);
dq_assert($carryDay['empty'] === false && $carryDay['stop_count'] === 2 && $carryDay['carry_count'] === 2, 'carryover stops count as today\'s work');
dq_assert($emptyDay['empty'] === true && $emptyDay['stop_count'] === 0, 'a day with no assignments is empty');
dq_assert($carryDay['empty'] === $emptyDay['empty'] ? false : $carryDay['empty'] !== $emptyDay['empty'], 'empty and carry days do not share one flag');
$labelAt = strpos($driverSrc, "['carry_label']");
$stateAt = strpos($driverSrc, 'bakery_driver_route_day_state($orderedStops)');
dq_assert($labelAt !== false && $stateAt !== false && $labelAt < $stateAt, 'day state is counted after carry labels are applied');
dq_assert(strpos($driverSrc, "\$driverDayState['stop_count']") !== false, 'phone progress uses the shared stop count');
dq_assert(strpos($driverSrc, "\$driverDayState['empty']") !== false, 'the empty card uses the same day state');
dq_assert(strpos($driverSrc, 'driver.next_work_day') !== false, 'an empty day links to the next day with stops');
dq_assert(strpos($driverSrc, 'bakery_pan_carry_stop_badge_html($heroStop') !== false, 'the next-stop card shows the carryover tag');
dq_assert(substr_count($driverSrc, 'driver.nothing_assigned') === 1, 'both layouts share one empty-day message');

$drivers = $db->query('SELECT id FROM drivers ORDER BY id LIMIT 1')->fetchAll(PDO::FETCH_COLUMN);
$productId = (int)$db->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
$driverId = (int)($drivers[0] ?? 0);
if ($driverId > 0 && $productId > 0 && table_exists($db, 'customers') && table_exists($db, 'daily_orders')) {
    $hasOrigin = function_exists('column_exists') && column_exists($db, 'customers', 'sfb_origin');
    $stamp = 'DQ59 Next ' . bin2hex(random_bytes(4));
    if ($hasOrigin) {
        $db->prepare('INSERT INTO customers (name, zone, is_active, sfb_origin) VALUES (?, ?, 1, ?)')->execute([$stamp, 'Test', 'human']);
    } else {
        $db->prepare('INSERT INTO customers (name, zone, is_active) VALUES (?, ?, 1)')->execute([$stamp, 'Test']);
    }
    $customerId = (int)$db->lastInsertId();
    $quiet = '2099-04-02';
    $later = '2099-04-06';
    $db->prepare(
        'INSERT INTO daily_orders (customer_id, order_date, status, total_amount, credits_taken_back) VALUES (?, ?, ?, 0, 0)'
    )->execute([$customerId, $later, 'pending']);
    $orderId = (int)$db->lastInsertId();
    $db->prepare(
        'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status) VALUES (?, ?, ?, 1, ?)'
    )->execute([$orderId, $driverId, $later, 'pending']);
    dq_assert(
        bakery_driver_next_assigned_date($db, $driverId, $quiet) === $later,
        'empty day finds the next date that has stops'
    );
    dq_assert(
        bakery_driver_next_assigned_date($db, $driverId, $later) === '',
        'no button target when nothing follows inside the window'
    );
    $db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$orderId]);
    $db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$orderId]);
    $db->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);
} else {
    dq_assert(false, 'fixture drivers, products, and orders exist for the next-day lookup');
}

echo "\n{$failures} failed\n";
exit($failures > 0 ? 1 : 0);
