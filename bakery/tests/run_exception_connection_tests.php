<?php
/**
 * Exception connection contract: canonical deep links, return= round-trip,
 * failed-delivery recovery, generate preserves dated edits, chip helper safety.
 * CLI / local test DB only.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/dashboard_command_center.php';
require_once $root . '/includes/operational_exceptions.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: exception connection tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

$date = '2099-08-17';
$forbidden = ['driver_list.php', 'generate_invoice.php', 'generate_invoice_simple.php'];
$canonical = bakery_ops_canonical_pages();

foreach (bakery_ops_known_exception_types() as $type) {
    $enriched = bakery_ops_enrich_exceptions([
        ['type' => $type, 'category' => 'general', 'title' => $type, 'severity' => 'warning'],
    ], $date, 'manager');
    $assert($enriched !== [], $type . ' enriches to one exception');
    $href = (string)($enriched[0]['href'] ?? '');
    $page = bakery_ops_href_page($href);
    $assert($href !== '', $type . ' has a deep link');
    $assert(in_array($page, $canonical, true), $type . ' lands on canonical page (' . $page . ')');
    foreach ($forbidden as $bad) {
        $assert(strpos($href, $bad) === false, $type . ' does not use ' . $bad);
    }
}

$managerReturn = bakery_ops_return_resolve('manager', $date);
$assert(is_array($managerReturn), 'return=manager resolves');
$assert(
    isset($managerReturn['href']) && strpos($managerReturn['href'], 'manager.php?date=' . $date) !== false,
    'return=manager round-trips to manager.php?date='
);

$failed = bakery_ops_enrich_exceptions([
    ['type' => 'delivery_failed', 'category' => 'delivery', 'title' => 'Failed', 'severity' => 'critical'],
], $date, 'daily_run');
$failedHref = (string)($failed[0]['href'] ?? '');
$failedPage = bakery_ops_href_page($failedHref);
$assert(
    in_array($failedPage, ['manager.php', 'driver_assignment.php'], true),
    'failed-delivery points at recovery or assignment'
);
$assert(
    strpos($failedHref, 'attention=failed') !== false || strpos($failedHref, 'filter=failed') !== false,
    'failed-delivery includes a failed/attention filter'
);
$assert(strpos($failedHref, 'driver_list.php') === false, 'failed-delivery does not use driver_list.php');

$apiSrc = file_get_contents($root . '/daily_run_api.php');
$assert($apiSrc !== false, 'daily_run_api.php is readable');
$assert(
    preg_match("/case\\s+'generate_daily_orders':[\\s\\S]*?'overwrite_changed'\\s*=>\\s*false/", $apiSrc) === 1,
    'inline generate_daily_orders still uses overwrite_changed=false'
);

$missing = bakery_ops_enrich_exceptions([
    ['type' => 'demand_missing_daily', 'category' => 'demand', 'title' => 'Missing'],
], $date, 'dashboard');
$assert(
    ($missing[0]['inline_action']['action'] ?? '') === 'generate_daily_orders',
    'missing-daily inline action is generate_daily_orders'
);

$threw = false;
$emptyChips = [];
try {
    $emptyChips = bakery_ops_chips_for_row([
        bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
    ], []);
} catch (Throwable $e) {
    $threw = true;
}
$assert(!$threw && $emptyChips === [], 'chip helper is a no-op when context ids are missing');

$namedOnly = bakery_ops_chips_for_row([
    bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
], ['customer_name' => 'Cafe']);
$assert($namedOnly === [], 'chip helper is a no-op when only a name is present');

$matched = bakery_ops_chips_for_row([
    bakery_ops_exception(['type' => 'demand_missing_daily', 'severity' => 'critical', 'title' => 'Missing']),
], ['customer_id' => 12, 'flags' => ['missing_daily' => true]]);
$assert(count($matched) === 1, 'chip helper matches a row that caused the exception');

$dailyRunSrc = (string)file_get_contents($root . '/includes/daily_run.php');
$assert(
    strpos($dailyRunSrc, "\$dispatchStage['action_label'] = 'Open Driver Pickup Loads'") !== false
        && strpos($dailyRunSrc, "'attention' => 'incomplete'") !== false,
    'Assign/Load/Dispatch with only incomplete loads opens Driver Pickup Loads'
);
$ccSrc = (string)file_get_contents($root . '/includes/dashboard_command_center.php');
$assert(
    strpos($ccSrc, 'bakery_inventory_load_progress') !== false
        && strpos($ccSrc, "\$loadParams['driver_id'] = \$focusDriverId") !== false,
    'a single incomplete load deep-links to that driver'
);
$loadSrc = (string)file_get_contents($root . '/driver_load.php');
$assert(
    strpos($loadSrc, 'data-load-finish-hint') !== false
        && strpos($loadSrc, 'driver_load.today_still_open') !== false,
    'Driver Pickup Loads names the stuck load and warns when Daily Run is a different day'
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
