<?php
/**
 * Demo recorder contracts (no browser).
 * Usage: php tests/run_demo_recorder_tests.php
 */
define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/demo_recorder.php';
require_once $root . '/includes/walkthroughs.php';

$failures = 0;

function demo_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL  $label\n";
        $failures++;
        return;
    }
    echo "PASS  $label\n";
}

$scenarios = bakery_demo_recorder_list_scenarios();
$ids = array_column($scenarios, 'id');
demo_assert('lists login', in_array('login', $ids, true));
demo_assert('lists daily-run', in_array('daily-run', $ids, true));
demo_assert('lists admin-route-build', in_array('admin-route-build', $ids, true));
demo_assert('lists admin-route-reorder', in_array('admin-route-reorder', $ids, true));
demo_assert('lists admin-route-verify', in_array('admin-route-verify', $ids, true));
demo_assert('lists driver-assignment', in_array('driver-assignment', $ids, true));
demo_assert('lists adjust-route', in_array('adjust-route', $ids, true));
demo_assert('lists manager-phone', in_array('manager-phone', $ids, true));
demo_assert('lists driver-login', in_array('driver-login', $ids, true));
demo_assert('lists driver-tomorrow', in_array('driver-tomorrow', $ids, true));
demo_assert('lists driver-complete-stop', in_array('driver-complete-stop', $ids, true));
demo_assert('lists driver-skip-stop', in_array('driver-skip-stop', $ids, true));
demo_assert('lists driver-adjust-route', in_array('driver-adjust-route', $ids, true));
demo_assert('lists driver-call-hq', in_array('driver-call-hq', $ids, true));

$login = bakery_demo_recorder_load_scenario('login');
$assign = bakery_demo_recorder_load_scenario('driver-assignment');
$adminBuild = bakery_demo_recorder_load_scenario('admin-route-build');
$adminReorder = bakery_demo_recorder_load_scenario('admin-route-reorder');
$adminVerify = bakery_demo_recorder_load_scenario('admin-route-verify');
demo_assert('login has steps', count($login['steps']) >= 3);
demo_assert('driver-assignment uses driver id', strpos(json_encode($assign['steps']), '{{DRIVER_ID}}') !== false);
demo_assert('admin build uses isolated date preparation', ($adminBuild['prepare'] ?? '') === 'admin-route-build');
demo_assert('admin build clicks Build Route Plan', strpos(json_encode($adminBuild['steps']), 'autoAssignFromStandingRoutes') !== false);
demo_assert('admin reorder snapshots a real route', ($adminReorder['prepare'] ?? '') === 'admin-route-reorder');
demo_assert('admin reorder drags a stop', strpos(json_encode($adminReorder['steps']), 'dragTo') !== false);
demo_assert('admin verify opens My Route', strpos(json_encode($adminVerify['steps']), 'driver.php?driver_id={{DRIVER_ID}}') !== false);
$adjust = bakery_demo_recorder_load_scenario('adjust-route');
demo_assert('adjust-route prepares a route', ($adjust['prepare'] ?? '') === 'adjust-route');
demo_assert('login captions are bilingual', isset($login['steps'][0]['caption']['en'], $login['steps'][0]['caption']['es']));
demo_assert('adjust captions are bilingual', isset($adjust['steps'][0]['caption']['en'], $adjust['steps'][0]['caption']['es']));

$loginJson = json_encode($login['steps']);
demo_assert('login uses admin placeholder', strpos($loginJson, '{{ADMIN_CODE}}') !== false);
$managerPhone = bakery_demo_recorder_load_scenario('manager-phone');
demo_assert('manager-phone uses manager placeholder', strpos(json_encode($managerPhone['steps']), '{{MANAGER_CODE}}') !== false);
demo_assert('manager-phone waits for manager.php', strpos(json_encode($managerPhone['steps']), 'manager.php') !== false);
demo_assert('adjust-route uses driver id placeholder', strpos(json_encode($adjust['steps']), '{{DRIVER_ID}}') !== false);
demo_assert('adjust-route uses date placeholder', strpos(json_encode($adjust['steps']), '{{DATE}}') !== false);

demo_assert(
    'localized helper prefers locale',
    bakery_demo_recorder_localized(['en' => 'Hello', 'es' => 'Hola'], 'es') === 'Hola'
);

$threw = false;
try {
    bakery_demo_recorder_validate_scenario([
        'id' => 'bad',
        'steps' => [['action' => 'explode', 'selector' => '#x']],
    ]);
} catch (InvalidArgumentException $e) {
    $threw = strpos($e->getMessage(), 'unknown action') !== false;
}
demo_assert('unknown action is rejected', $threw);

$threwCode = false;
try {
    bakery_demo_recorder_validate_scenario([
        'id' => 'bad-code',
        'steps' => [['action' => 'fill', 'selector' => '#code', 'value' => '1234']],
    ]);
} catch (InvalidArgumentException $e) {
    $threwCode = strpos($e->getMessage(), 'login code') !== false;
}
demo_assert('hard-coded login codes are rejected', $threwCode);

$cli = file_get_contents($root . '/scripts/demo_record.php');
demo_assert('CLI is bakerysf_local', strpos($cli, 'bakerysf_local') !== false);
demo_assert('CLI does not isolate test DB', strpos($cli, 'isolate_test_db.php') === false);
demo_assert('CLI does not force bakerysf_test', strpos($cli, 'bakerysf_test') === false);
demo_assert('CLI writes mp4', strpos($cli, '.mp4') !== false);
demo_assert('CLI supports locale', strpos($cli, '--locale') !== false);
demo_assert('CLI supports publish', strpos($cli, '--publish') !== false);
demo_assert('CLI supports all', strpos($cli, 'all') !== false);

$python = file_get_contents($root . '/tools/demo-recorder/record.py');
demo_assert('Python converts to mp4', strpos($python, 'libx264') !== false);
demo_assert('Python records Playwright video', strpos($python, 'record_video_dir') !== false);
demo_assert('Python pick_port does not reuse a busy Windows port', strpos($python, 'SO_REUSEADDR') === false);
demo_assert('Python bootstrap installs ffmpeg', strpos(file_get_contents($root . '/includes/demo_recorder.php'), "install', 'ffmpeg'") !== false);
demo_assert('recorder drops sandbox Playwright cache', strpos(file_get_contents($root . '/includes/demo_recorder.php'), 'cursor-sandbox-cache') !== false);
demo_assert('Python refuses live production DB name', strpos($python, 'bakerysf') !== false);
demo_assert('Python does not force bakerysf_test', strpos($python, 'bakerysf_test') === false);

$runner = file_get_contents($root . '/tools/demo-recorder/runner.py');
demo_assert('runner switches locale', strpos($runner, 'hreflang') !== false);
demo_assert('runner reads DEMO_LOCALE', strpos($runner, 'DEMO_LOCALE') !== false);

$help = [];
exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/demo_record.php') . ' help', $help, $helpCode);
$helpText = implode("\n", $help);
demo_assert('help exits 0', $helpCode === 0);
demo_assert('help mentions login', strpos($helpText, 'login') !== false);
demo_assert('help mentions daily-run', strpos($helpText, 'daily-run') !== false);
demo_assert('help mentions admin route build', strpos($helpText, 'admin-route-build') !== false);
demo_assert('help mentions adjust-route', strpos($helpText, 'adjust-route') !== false);
demo_assert('help mentions manager-phone', strpos($helpText, 'manager-phone') !== false);
demo_assert('help mentions publish', strpos($helpText, '--publish') !== false);
demo_assert('help mentions drivers', strpos($helpText, 'drivers') !== false);

$page = file_get_contents($root . '/walkthroughs.php');
demo_assert('gallery is role gated', strpos($page, "bakery_require_role(['administrator', 'manager'])") !== false);
demo_assert('gallery uses catalog', strpos($page, 'bakery_walkthrough_items') !== false);

$driverPage = file_get_contents($root . '/guias.php');
demo_assert('driver guides page is public file', strpos($driverPage, 'bakery_require_role') === false);
demo_assert('driver guides use driver catalog', strpos($driverPage, 'bakery_driver_walkthrough_items') !== false);
demo_assert('driver guides force Spanish', strpos($driverPage, "bakery_set_locale('es'") !== false);

$catalogIds = array_column(bakery_walkthrough_items(), 'id');
demo_assert('gallery catalog includes login', in_array('login', $catalogIds, true));
demo_assert('gallery catalog includes daily-run', in_array('daily-run', $catalogIds, true));
demo_assert('gallery catalog includes admin route build', in_array('admin-route-build', $catalogIds, true));
demo_assert('gallery catalog includes admin route reorder', in_array('admin-route-reorder', $catalogIds, true));
demo_assert('gallery catalog includes admin route verify', in_array('admin-route-verify', $catalogIds, true));
demo_assert('gallery catalog includes driver-assignment', in_array('driver-assignment', $catalogIds, true));
demo_assert('gallery catalog includes adjust-route', in_array('adjust-route', $catalogIds, true));
demo_assert('gallery catalog includes manager-phone', in_array('manager-phone', $catalogIds, true));
$driverIds = array_column(bakery_driver_walkthrough_items(), 'id');
demo_assert('driver catalog includes tomorrow', in_array('driver-tomorrow', $driverIds, true));
demo_assert('driver catalog includes complete', in_array('driver-complete-stop', $driverIds, true));
demo_assert('driver catalog includes skip', in_array('driver-skip-stop', $driverIds, true));
demo_assert('driver catalog includes adjust', in_array('driver-adjust-route', $driverIds, true));
demo_assert('driver catalog excludes staff assignment', !in_array('driver-assignment', $driverIds, true));
$cli = file_get_contents($root . '/scripts/demo_record.php');
demo_assert('drivers CLI uses driver catalog', strpos($cli, 'bakery_demo_recorder_driver_scenario_ids') !== false);
demo_assert('runner does not Escape after camera picker', strpos($runner, 'Do not press Escape') !== false);
demo_assert('runner records narrator cues', strpos($runner, 'note_caption_cue') !== false);
demo_assert('runner supports route drag', strpos($runner, 'drag_to') !== false);
demo_assert('runner hides local env banner in recordings', strpos($runner, '.local-env-banner') !== false);
demo_assert(
    'restore writes only allowed assignment statuses',
    strpos(file_get_contents($root . '/includes/demo_recorder.php'), 'bakery_demo_recorder_legal_assignment_status') !== false
);
$demoPhp = file_get_contents($root . '/includes/demo_recorder.php');
demo_assert('admin build cleanup removes generated dated rows', strpos($demoPhp, 'bakery_demo_recorder_cleanup_generated_date') !== false);
demo_assert('discover uses pending and in_transit', strpos($demoPhp, "IN ('pending', 'in_transit')") !== false);
demo_assert('restore parks route_order before writeback', strpos($demoPhp, '10000') !== false);
$loginScenario = file_get_contents($root . '/tools/demo-recorder/scenarios/driver-login.json');
demo_assert('driver-login waits for day nav or empty state', strpos($loginScenario, '.route-day-nav, .empty-state') !== false);
demo_assert('driver-login opens a dated route after sign-in', strpos($loginScenario, 'driver.php?date={{DATE}}') !== false);
$skipScenario = file_get_contents($root . '/tools/demo-recorder/scenarios/driver-skip-stop.json');
demo_assert('skip-stop snapshots via skip-stop prepare', strpos($skipScenario, '"prepare": "skip-stop"') !== false);
demo_assert('skip-stop shows the confirm button', strpos($skipScenario, '#skipStopConfirmBtn') !== false);
demo_assert('skip-stop clicks confirm', (bool)preg_match('/"action":\s*"click"[^}]*#skipStopConfirmBtn/', $skipScenario));
demo_assert('skip-stop waits for skip toast', strpos($skipScenario, 'routeSuccessToast') !== false);
demo_assert('skip-stop uses Dejar copy', strpos($skipScenario, 'Dejar esta parada') !== false);
demo_assert('restore maps cancelled when ENUM cannot store it', strpos($demoPhp, "status === 'cancelled'") !== false);
demo_assert('tomorrow prefers consecutive dated days', strpos($demoPhp, 'bakery_demo_recorder_discover_consecutive_dates') !== false);
$adjustDriver = file_get_contents($root . '/tools/demo-recorder/scenarios/driver-adjust-route.json');
demo_assert('driver-adjust waits for visible toast class', strpos($adjustDriver, '#routeSuccessToast.is-visible') !== false);
$complete = file_get_contents($root . '/tools/demo-recorder/scenarios/driver-complete-stop.json');
demo_assert('complete-stop waits for invoice confirm only', strpos($complete, '#deliveryInvoiceConfirmBtn') !== false);
demo_assert('complete-stop does not click invoice confirm', !preg_match('/"action":\s*"click"[^}]*#deliveryInvoiceConfirmBtn/', $complete));
$reqs = file_get_contents($root . '/tools/demo-recorder/requirements.txt');
demo_assert('requirements pin edge-tts', strpos($reqs, 'edge-tts') !== false);
demo_assert('narrate helper exists', is_file($root . '/tools/demo-recorder/narrate.py'));
demo_assert('record mixes narration', strpos($python, 'mix_narration') !== false);
demo_assert(
    'published filename is id-locale',
    bakery_walkthrough_filename('login', 'es') === 'login-es.mp4'
);

$nav = file_get_contents($root . '/includes/navigation_catalog.php');
demo_assert('nav catalogs walkthroughs', strpos($nav, 'walkthroughs.php') !== false);

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}
echo "\nAll demo recorder contract tests passed\n";
exit(0);
