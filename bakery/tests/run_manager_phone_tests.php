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

manager_phone_assert(bakery_manager_phone_view('routes') === 'routes', 'routes view is accepted');
manager_phone_assert(bakery_manager_phone_view('nope') === 'today', 'unknown view falls back to today');
manager_phone_assert(bakery_manager_phone_sheet('qty') === 'qty', 'qty sheet is accepted');
manager_phone_assert(bakery_manager_phone_sheet('bulk') === '', 'unknown sheet is ignored');
manager_phone_assert(
    strpos(bakery_manager_phone_href('2099-08-20', 'missed'), 'view=missed') !== false,
    'phone href preserves view'
);

$login = (string)file_get_contents($root . '/login.php');
manager_phone_assert(strpos($login, "'manager.php'") !== false, 'manager login lands on manager.php');

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

$phoneCss = (string)file_get_contents($root . '/css/manager_phone.css');
manager_phone_assert(strpos($phoneCss, '.manager-phone__chip--loud') !== false, 'loud chip tone exists in manager phone css');

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

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
