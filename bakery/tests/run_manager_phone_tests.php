<?php
/**
 * Manager phone workspace contracts.
 * Usage: php tests/run_manager_phone_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

$root = dirname(__DIR__);
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once $root . '/includes/config.php';
require_once $root . '/includes/i18n.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/manager_phone.php';
require_once $root . '/includes/exception_desk.php';

$passed = 0;
$failed = 0;

function manager_phone_assert(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  {$message}\n");
    $failed++;
}

$releaseNext = bakery_manager_phone_next_action([
    'date' => '2099-08-07',
    'failed' => 0,
    'unassigned' => 0,
    'plan_committed' => false,
    'plan_drift' => 0,
    'productionSummary' => ['remaining' => 9],
    'packingSummary' => ['required_lines' => 0, 'checked_lines' => 0],
    'pending' => 0,
    'inTransit' => 0,
]);
manager_phone_assert($releaseNext['key'] === 'release', 'unreleased plan is the next action after routes are covered');
manager_phone_assert(strpos($releaseNext['href'], 'production_center.php?date=2099-08-07') !== false, 'release action opens Production Center for that date');
$routesBeforePlan = bakery_manager_phone_next_action([
    'date' => '2099-08-07',
    'failed' => 0,
    'unassigned' => 2,
    'plan_committed' => false,
    'productionSummary' => ['remaining' => 9],
]);
manager_phone_assert($routesBeforePlan['key'] === 'unassigned', 'stops without a driver come before plan release');
$driftNext = bakery_manager_phone_next_action([
    'date' => '2099-08-07',
    'failed' => 0,
    'unassigned' => 0,
    'plan_committed' => true,
    'plan_drift' => 3,
    'productionSummary' => ['remaining' => 0],
    'packingSummary' => ['required_lines' => 0, 'checked_lines' => 0],
    'pending' => 0,
    'inTransit' => 0,
]);
manager_phone_assert($driftNext['key'] === 'drift', 'post-release demand change points back to the plan');
manager_phone_assert(strpos($driftNext['href'], 'production_center.php?date=2099-08-07') !== false, 'drift action keeps the selected date');
manager_phone_assert(bakery_manager_phone_view('routes') === 'routes', 'routes view is accepted');
manager_phone_assert(bakery_manager_phone_view('nope') === 'today', 'unknown view falls back to today');
manager_phone_assert(bakery_manager_phone_sheet('qty') === 'qty', 'qty sheet is accepted');
manager_phone_assert(bakery_manager_phone_sheet('close') === 'close', 'close sheet is accepted');
manager_phone_assert(bakery_manager_phone_sheet('bulk') === '', 'unknown sheet is ignored');
manager_phone_assert(
    strpos(bakery_manager_phone_href('2099-08-20', 'missed'), 'view=missed') !== false,
    'phone href preserves view'
);

$login = (string)file_get_contents($root . '/login.php');
manager_phone_assert(strpos($login, 'bakery_role_home(') !== false, 'login.php resolves the landing page through bakery_role_home');
manager_phone_assert(bakery_role_home('manager') === 'manager.php', 'manager login lands on manager.php');

$header = (string)file_get_contents($root . '/includes/header.php');
manager_phone_assert(strpos($header, 'workspace-manager') !== false, 'header sets workspace-manager');

$managerPage = (string)file_get_contents($root . '/manager.php');
manager_phone_assert(strpos($managerPage, 'bakery_manager_phone_render') !== false, 'manager.php renders the phone workspace');
manager_phone_assert(strpos($managerPage, 'bakery_manager_is_phone_workspace') !== false, 'manager.php branches for the manager role');
manager_phone_assert(strpos($managerPage, 'Manager attention queue') !== false, 'desktop attention queue remains for administrators');
manager_phone_assert(strpos($managerPage, 'phone_move') !== false, 'manager.php accepts phone move mutations');

$skip = (string)file_get_contents($root . '/includes/delivery_skip.php');
manager_phone_assert(strpos($skip, 'function bakery_skip_delivery_stop') !== false, 'skip lives in a shared include');
$complete = (string)file_get_contents($root . '/complete_delivery.php');
manager_phone_assert(strpos($complete, 'delivery_skip.php') !== false, 'delivery API reuses the skip include');

$phone = (string)file_get_contents($root . '/includes/manager_phone.php');
manager_phone_assert(strpos($phone, 'bakery_driver_transfer_assignments') !== false, 'move-one-stop uses Driver Assignment transfer');
manager_phone_assert(strpos($phone, 'bakery_customer_save_daily_line') !== false, 'qty sheet writes dated lines only');
manager_phone_assert(strpos($phone, 'bakery_skip_delivery_stop') !== false, 'skip sheet uses the shared skip helper');
manager_phone_assert(strpos($phone, '<select name="daily_order_id">') === false, 'move and skip sheets use chips, not a native select');
manager_phone_assert(strpos($phone, "pendingOpen > 0 ? ' is-loud'") === false, 'in-progress score stays quiet during a normal run');

manager_phone_assert(strpos($phone, 'function bakery_manager_phone_render_kitchen') !== false, 'kitchen renderer lives in the manager phone include');
manager_phone_assert(strpos($phone, 'manager-phone__chip') !== false, 'kitchen renders a committed-state chip row');
manager_phone_assert(strpos($phone, "function_exists('bakery_production_plan_commits_ready')") !== false, 'committed-state chips are guarded when commit tables are missing');
manager_phone_assert(strpos($phone, 'bakery_production_plan_state') !== false, 'kitchen derives committed state from the plan-state helper');
manager_phone_assert(strpos($phone, 'manager_phone.plan_committed_at') !== false, 'committed kitchen state shows the commit time label');
manager_phone_assert(strpos($phone, 'manager_phone.plan_not_committed') !== false, 'uncommitted kitchen state is loud with its own label');
manager_phone_assert(strpos($phone, 'manager_phone.plan_drift_count') !== false, 'post-commit drift count renders on the kitchen tab');
manager_phone_assert(strpos($phone, 'production_center.php?date=') !== false, 'kitchen sends managers to Production Center first');
manager_phone_assert(strpos($phone, "bakery_te('nav.item.production_center')") !== false, 'kitchen labels the primary action as Production Center');
manager_phone_assert(strpos($phone, "sheet' => 'close'") !== false || strpos($phone, "['sheet' => 'close'") !== false, 'routes tab offers Close route sheet');
manager_phone_assert(strpos($phone, 'manager_phone.close_route') !== false, 'close route copy is wired');
manager_phone_assert(strpos($phone, 'bakery_inventory_closeout_lines') !== false, 'close sheet loads closeout lines');
manager_phone_assert(strpos($phone, 'action="') !== false && strpos($phone, 'route_closeout.php') !== false, 'close sheet posts to route_closeout.php');
manager_phone_assert(strpos($phone, 'close_route') !== false, 'close sheet submits close_route action');
manager_phone_assert(strpos($phone, 'data-van-math=') !== false, 'close sheet asserts van math by name');
manager_phone_assert(strpos($phone, 'bakery_inventory_reconcile_driver_load') === false, 'phone does not duplicate reconcile helper');

$closeoutPage = (string)file_get_contents($root . '/route_closeout.php');
manager_phone_assert(strpos($closeoutPage, 'manager-desktop-only') !== false, 'route_closeout marks dense UI desktop-only');
manager_phone_assert(strpos($closeoutPage, "returnKey === 'manager'") !== false, 'route_closeout redirects phone closes back to Manager Mode');
$routeManagerPage = (string)file_get_contents($root . '/route_manager.php');
manager_phone_assert(strpos($routeManagerPage, 'manager-desktop-only') !== false, 'route_manager marks dense UI desktop-only');
manager_phone_assert(strpos($routeManagerPage, 'manager-desktop-only-hint') !== false, 'route_manager points phones to Manager Mode');

$phoneCss = (string)file_get_contents($root . '/css/manager_phone.css');
manager_phone_assert(strpos($phoneCss, '.manager-phone__chip--loud') !== false, 'loud chip tone exists in manager phone css');
manager_phone_assert(strpos($phoneCss, '.manager-desktop-only-hint') !== false, 'desktop-only hint styles exist');
manager_phone_assert(strpos($phoneCss, 'max-width:720px') !== false, 'desktop-only hide kicks in at 720px');

$productionSheet = (string)file_get_contents($root . '/production.php');
manager_phone_assert(strpos($productionSheet, 'production_sheet.commit_diff_title') !== false, 'bake sheet renders the re-commit diff title');
manager_phone_assert(strpos($productionSheet, 'bp-commit-diff__chip') !== false, 'bake sheet renders re-commit diff chips');
manager_phone_assert(
    bakery_manager_phone_tomorrow_needs_work(['state' => 'ready_unconfirmed']) === true,
    'unconfirmed tomorrow demand needs work'
);
manager_phone_assert(
    bakery_manager_phone_tomorrow_needs_work(['state' => 'confirmed']) === false,
    'confirmed tomorrow is quiet'
);
manager_phone_assert(
    bakery_manager_phone_run_finished(['date' => '2026-08-19', 'today' => '2026-08-20', 'inTransit' => 2]) === true,
    'a past date is leftover after the run'
);
manager_phone_assert(
    bakery_manager_phone_run_finished(['date' => '2026-08-20', 'today' => '2026-08-20', 'inTransit' => 1, 'delivered' => 4, 'failed' => 0]) === false,
    'in-transit today is not leftover missed'
);
manager_phone_assert(
    count(bakery_manager_phone_short_products([
        ['remaining_quantity' => 0, 'product_name' => 'Covered'],
        ['remaining_quantity' => 6, 'product_name' => 'Short'],
    ])) === 1,
    'kitchen lists short products first'
);

$desk = bakery_exception_desk_recovery_card([
    'id' => 3,
    'failure_reason' => 'access_issue',
    'workflow_state' => 'open',
    'customer_name' => 'Cafe Luna',
    'active_driver_name' => 'Ana',
    'manager_note' => '',
    'customer_communication_status' => 'not_needed',
    'billing_handoff' => 'not_needed',
], [
    ['id' => 8, 'name' => 'Ana'],
    ['id' => 9, 'name' => 'Luis'],
], '2099-08-20');
manager_phone_assert(strpos($desk, 'type="radio"') !== false, 'recovery reassign uses driver chips');
manager_phone_assert(strpos($desk, 'name="to_driver_id"') !== false, 'recovery chips post to_driver_id');
manager_phone_assert(strpos($desk, '<select name="to_driver_id">') === false, 'recovery no longer uses a native driver select');

$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
$requiredKeys = [
    'manager_phone.title',
    'manager_phone.next_missed',
    'manager_phone.do_not_interrupt',
    'manager_phone.bucket_still_out',
    'manager_phone.plan_committed_at',
    'manager_phone.plan_not_committed',
    'manager_phone.plan_drift_count',
    'manager_phone.close_route',
    'manager_phone.confirm_close_route',
    'manager_phone.closeout_van_math',
    'manager_phone.desktop_use_manager',
    'manager_phone.mission_timeline',
    'manager_phone.mission_done_so_far',
    'manager_phone.mission_still_to_deliver',
    'manager_phone.mission_current_stop',
    'manager_phone.mission_now',
    'manager_phone.mission_no_time',
    'manager_phone.cod_section',
    'manager_phone.cod_collected_turnin_missing',
    'production_sheet.commit_diff_title',
    'production_sheet.commit_diff_chip',
    'nav.manager_today',
    'nav.manager_routes',
    'nav.manager_kitchen',
    'nav.manager_missed',
    'walkthroughs.item.manager_phone.title',
];
foreach ($requiredKeys as $key) {
    manager_phone_assert(isset($en[$key], $es[$key]), "i18n key {$key} exists in en and es");
}

$mission = bakery_manager_phone_mission_from_stops([
    ['daily_order_id' => 1, 'customer_name' => 'Cafe A', 'delivery_status' => 'delivered', 'actual_delivery_time' => '08:12:00', 'scheduled_delivery_time' => '08:00:00'],
    ['daily_order_id' => 2, 'customer_name' => 'Cafe B', 'delivery_status' => 'in_transit', 'actual_delivery_time' => null, 'scheduled_delivery_time' => '09:00:00'],
    ['daily_order_id' => 3, 'customer_name' => 'Cafe C', 'delivery_status' => 'pending', 'actual_delivery_time' => null, 'scheduled_delivery_time' => '10:00:00'],
]);
manager_phone_assert($mission['phase'] === 'in_progress', 'mission phase is in progress when a stop is open');
manager_phone_assert($mission['done_count'] === 1 && $mission['left_count'] === 2, 'mission splits done and remaining stops');
manager_phone_assert((string)($mission['current']['customer_name'] ?? '') === 'Cafe B', 'current stop prefers in_transit');
manager_phone_assert($mission['last_done_time'] !== '', 'mission formats last delivered time');
$outOfOrder = bakery_manager_phone_mission_from_stops([
    ['daily_order_id' => 10, 'customer_name' => 'Late Cafe', 'delivery_status' => 'delivered', 'actual_delivery_time' => '10:40:00', 'scheduled_delivery_time' => null],
    ['daily_order_id' => 11, 'customer_name' => 'Early Cafe', 'delivery_status' => 'delivered', 'actual_delivery_time' => '08:05:00', 'scheduled_delivery_time' => null],
]);
manager_phone_assert($outOfOrder['last_done_time'] === '10:40 AM', 'last stop time is the latest delivery, not the last row');
$_SESSION['locale'] = 'es';
bakery_reset_locale_cache();
$esMorning = bakery_manager_phone_format_stop_time('10:40:00');
$esAfternoon = bakery_manager_phone_format_stop_time('14:05:00');
manager_phone_assert(
    ($esMorning === '10:40 a. m.' || $esMorning === '10:40')
        && ($esAfternoon === '2:05 p. m.' || $esAfternoon === '14:05'),
    'Spanish mission times use 24-hour or h:mm a. m. style, got ' . $esMorning . ' / ' . $esAfternoon
);
manager_phone_assert(strpos($esMorning, 'AM') === false && strpos($esAfternoon, 'PM') === false, 'Spanish mission times are not English AM/PM');
$_SESSION['locale'] = 'en';
bakery_reset_locale_cache();
foreach (['manager_phone.do_not_interrupt', 'manager_phone.plan_not_committed'] as $dashKey) {
    manager_phone_assert(
        strpos((string)$en[$dashKey], "\u{2014}") === false
            && strpos((string)$en[$dashKey], "\u{2013}") === false
            && strpos((string)$es[$dashKey], "\u{2014}") === false
            && strpos((string)$es[$dashKey], "\u{2013}") === false,
        $dashKey . ' has no em dash or en dash'
    );
}
manager_phone_assert(strpos($phone, 'manager_phone.mission_no_time') !== false, 'missing stop times use a translated label');
manager_phone_assert(strpos($phone, 'manager_phone.cod_collected_turnin_missing') !== false, 'missing COD turn-in uses a translated sentence');
manager_phone_assert(strpos($phone, 'bakery_manager_phone_mission_from_stops') !== false, 'routes render uses mission helper');
manager_phone_assert(strpos($phone, 'manager_phone.mission_still_to_deliver') !== false, 'routes show remaining delivery order');
manager_phone_assert(strpos($phoneCss, '.manager-phone__mission-list') !== false, 'mission timeline styles exist');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
