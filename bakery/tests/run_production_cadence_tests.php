<?php
/**
 * Bake-cover calendar for Production Center (no database).
 *
 * Usage: php tests/run_production_cadence_tests.php
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/production_cadence.php';

$failures = 0;
$assert = static function (bool $ok, string $msg) use (&$failures): void {
    if ($ok) {
        echo "PASS  $msg\n";
        return;
    }
    echo "FAIL  $msg\n";
    $failures++;
};

$assert(bakery_production_cadence_family('Pan Dulce') === BAKERY_PRODUCTION_CADENCE_DAILY, 'Pan Dulce is daily family');
$assert(bakery_production_cadence_family('Traditional') === BAKERY_PRODUCTION_CADENCE_DAILY, 'Traditional is daily family');
$assert(bakery_production_cadence_family('Sour Flour') === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 'Sour Flour family');
$assert(bakery_production_cadence_family('Sour Flour Bread') === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 'Sour Flour prefix family');

$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_DAILY, 5) === [6, 7, 1], 'Friday pan dulce covers Sat, Sun, Mon');
$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_DAILY, 1) === [2], 'Monday pan dulce covers Tuesday');
$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_DAILY, 7) === [], 'Sunday is not a typical pan dulce bake');
$assert(bakery_production_cadence_typical_bake_weekdays(BAKERY_PRODUCTION_CADENCE_DAILY) === [1, 2, 3, 4, 5], 'Pan dulce produce Mon-Fri');

$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 2) === [3, 4, 5], 'SF Tuesday covers Wed-Fri');
$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 5) === [6, 7], 'SF Friday covers Sat-Sun');
$assert(bakery_production_cadence_cover_weekdays(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 7) === [1], 'SF Sunday covers Monday');
$assert(bakery_production_cadence_bake_weekday_for_delivery(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, 2) === null, 'SF Tuesday delivery has no dedicated run');

// 2026-08-21 is Friday; 2026-08-17 is Monday.
$assert(
    bakery_production_cadence_cover_dates(BAKERY_PRODUCTION_CADENCE_DAILY, '2026-08-21') === ['2026-08-22', '2026-08-23', '2026-08-24'],
    'Friday 8/21 covers Sat-Sun and next Monday'
);
$assert(
    bakery_production_cadence_bake_date_for_delivery(BAKERY_PRODUCTION_CADENCE_DAILY, '2026-08-24') === '2026-08-21',
    'Monday pan dulce was baked previous Friday'
);
$assert(
    bakery_production_cadence_bake_date_for_delivery(BAKERY_PRODUCTION_CADENCE_DAILY, '2026-08-18') === '2026-08-17',
    'Tuesday pan dulce was baked Monday'
);
$assert(
    bakery_production_cadence_bake_date_for_delivery(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, '2026-08-24') === '2026-08-23',
    'Monday Sour Flour was baked Sunday'
);
$assert(
    bakery_production_cadence_bake_date_for_delivery(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, '2026-08-22') === '2026-08-21',
    'Saturday Sour Flour was baked Friday'
);
$assert(
    bakery_production_cadence_cover_dates(BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR, '2026-08-18') === ['2026-08-19', '2026-08-20', '2026-08-21'],
    'Tuesday SF bake covers Wed-Fri'
);

$weekRuns = bakery_production_cadence_runs_for_week('2026-08-17', '2026-08-23');
$dailyFriPrev = null;
$dailyFriThis = null;
$sfSun = null;
foreach ($weekRuns as $run) {
    if ($run['family'] === BAKERY_PRODUCTION_CADENCE_DAILY && $run['bake_date'] === '2026-08-14') {
        $dailyFriPrev = $run;
    }
    if ($run['family'] === BAKERY_PRODUCTION_CADENCE_DAILY && $run['bake_date'] === '2026-08-21') {
        $dailyFriThis = $run;
    }
    if ($run['family'] === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR && $run['bake_date'] === '2026-08-16') {
        $sfSun = $run;
    }
}
$assert(is_array($dailyFriPrev), 'Week includes previous Friday pan dulce bake that covers this Monday');
$assert(($dailyFriPrev['cover_dates'] ?? []) === ['2026-08-15', '2026-08-16', '2026-08-17'], 'Previous Friday covers last weekend plus this Monday');
$assert(is_array($dailyFriThis), 'Week includes this Friday pan dulce bake');
$assert(($dailyFriThis['cover_dates'] ?? []) === ['2026-08-22', '2026-08-23', '2026-08-24'], 'This Friday covers weekend plus next Monday');
$assert(is_array($sfSun), 'Week includes Sunday Sour Flour bake for this Monday');

$monLegs = bakery_production_cadence_delivery_legs('2026-08-24');
$assert(count($monLegs) === 2, 'Monday delivery shows both families');
$assert($monLegs[0]['family'] === BAKERY_PRODUCTION_CADENCE_DAILY && $monLegs[0]['bake_date'] === '2026-08-21', 'Monday daily leg is Friday');
$assert($monLegs[1]['family'] === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR && $monLegs[1]['bake_date'] === '2026-08-23', 'Monday SF leg is Sunday');

if ($failures > 0) {
    fwrite(STDERR, "$failures production cadence assertion(s) failed\n");
    exit(1);
}
echo "All production cadence tests passed\n";
exit(0);
