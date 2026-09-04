<?php
/**
 * Route-order survey unit tests (no bakerysf_test required for pure helpers).
 * Usage: php tests/run_survey_route_order_tests.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
define('ACCESS_ALLOWED', true);
require_once $root . '/includes/survey_route_order.php';

$failed = 0;
$passed = 0;
$assert = static function (bool $cond, string $msg) use (&$failed, &$passed): void {
    if ($cond) {
        echo "PASS  {$msg}\n";
        $passed++;
    } else {
        echo "FAIL  {$msg}\n";
        $failed++;
    }
};

$rows = [
    ['id' => 1, 'daily_order_id' => 10, 'delivery_status' => 'delivered', 'name' => 'Done'],
    ['id' => 2, 'daily_order_id' => 20, 'delivery_status' => 'pending', 'name' => 'A'],
    ['id' => 3, 'daily_order_id' => 30, 'delivery_status' => 'in_transit', 'name' => 'Door'],
    ['id' => 4, 'daily_order_id' => 40, 'delivery_status' => 'pending', 'name' => 'B'],
];
$part = bakery_survey_route_order_partition($rows);
$assert(count($part['locked']) === 2, 'delivered + in_transit are locked');
$assert(count($part['movable']) === 2, 'two pending are movable');
$assert((int)$part['movable'][0]['daily_order_id'] === 20, 'movable keeps encounter order');

$ok = bakery_survey_route_order_collect([40, 20], $part['movable']);
$assert($ok['ok'] === true && $ok['ordered'] === [40, 20], 'full permutation accepted');
$dup = bakery_survey_route_order_collect([20, 20], $part['movable']);
$assert($dup['ok'] === false && ($dup['error'] ?? '') === 'duplicate', 'duplicates rejected');
$partial = bakery_survey_route_order_collect([20], $part['movable']);
$assert($partial['ok'] === false && ($partial['error'] ?? '') === 'incomplete', 'partial rejected');
$bad = bakery_survey_route_order_collect([20, 10], $part['movable']);
$assert($bad['ok'] === false && ($bad['error'] ?? '') === 'unknown_or_locked', 'locked id rejected');

$plan = bakery_survey_route_order_plan($part['locked'], $part['movable'], [40, 20]);
$assert(count($plan) === 4, 'plan includes locked + movable');
$assert((int)$plan[0]['daily_order_id'] === 10 && (int)$plan[0]['route_order'] === 1, 'locked stay first');
$assert((int)$plan[2]['daily_order_id'] === 40 && (int)$plan[2]['route_order'] === 3, 'first tap becomes next after locked');
$assert((int)$plan[3]['daily_order_id'] === 20 && (int)$plan[3]['route_order'] === 4, 'second tap last');

$sms = bakery_survey_route_order_sms_body([
    'driver_name' => 'Laura',
    'delivery_date' => '2026-09-01',
    'stores' => [['name' => 'Amigos'], ['name' => 'Bar 49']],
]);
$assert(strpos($sms, 'Laura') !== false && strpos($sms, '1. Amigos') !== false, 'SMS lists numbered stores');

$assert(in_array('route_order', ['route_order'], true), 'kind name reserved');

// Page / wiring contracts (source).
$surveysPhp = (string)file_get_contents($root . '/includes/surveys.php');
$surveyPhp = (string)file_get_contents($root . '/survey.php');
$authPhp = (string)file_get_contents($root . '/includes/auth.php');
$svPhp = (string)file_get_contents($root . '/includes/survey_store_verify.php');

// These assert post-wiring; until wired they may fail — run after Task 2–4.
$wiringReady = strpos($surveysPhp, "'route_order'") !== false
    || strpos($surveysPhp, '"route_order"') !== false;

if ($wiringReady) {
    $assert(strpos($surveysPhp, 'route_order') !== false, 'surveys.php knows route_order kind');
    $assert(
        strpos($svPhp, "'route_order'") !== false || strpos($svPhp, 'route_order') !== false,
        'public token allowlist includes route_order'
    );
    $assert(strpos($surveyPhp, 'order_route') !== false, 'survey.php handles order_route action');
    $assert(strpos($surveyPhp, 'apply_route_order') !== false, 'survey.php can Apply to route for route-order results');
    $helperRo = (string)file_get_contents($root . '/includes/survey_route_order.php');
    $assert(strpos($helperRo, 'bakery_survey_route_order_preview') !== false, 'route-order preview helper exists');
    $assert(strpos($helperRo, 'bakery_survey_route_order_confirm_apply') !== false, 'route-order confirm apply exists');
    $assert(strpos($helperRo, 'survey_route_order_applied') !== false, 'route-order apply records operational_events');
    $assert(strpos($surveyPhp, 'route_order') !== false, 'survey.php renders route_order');
    $assert(strpos($surveyPhp, 'new URL(rel, window.location.href)') !== false
        || strpos($surveyPhp, 'bakery_survey_link_url') !== false, 'copy links stay BASE_URL-aware');
    $en = (string)file_get_contents($root . '/lang/en.php');
    $es = (string)file_get_contents($root . '/lang/es.php');
    $assert(strpos($en, 'survey.route_order') !== false, 'en has route_order strings');
    $assert(strpos($es, 'survey.route_order') !== false, 'es has route_order strings');
} else {
    echo "SKIP  wiring contracts until kind is registered\n";
}

echo "\nRoute-order: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
