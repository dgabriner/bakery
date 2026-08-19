<?php
/**
 * Non-destructive Manager Mode contracts.
 * Usage: php tests/run_manager_mode_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
define('BASE_URL', '/');

require_once dirname(__DIR__) . '/includes/operational_exceptions.php';
require_once dirname(__DIR__) . '/includes/navigation_catalog.php';

$failed = 0;
function manager_mode_assert(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo "PASS  {$message}\n";
        return;
    }
    fwrite(STDERR, "FAIL  {$message}\n");
    $failed++;
}

$managerItems = [];
foreach (bakery_navigation_groups_for_role('manager') as $group) {
    $managerItems = array_merge($managerItems, array_column($group['items'], 'href'));
}
$adminItems = [];
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    $adminItems = array_merge($adminItems, array_column($group['items'], 'href'));
}

manager_mode_assert(in_array('manager.php', $managerItems, true), 'Manager Mode is available to managers');
manager_mode_assert(in_array('manager.php', $adminItems, true), 'Manager Mode is available to administrators');
manager_mode_assert(
    bakery_ops_return_resolve('manager', '2099-08-17')['href'] === '/manager.php?date=2099-08-17',
    'Manager Mode is a safe dated return target'
);

$page = file_get_contents(dirname(__DIR__) . '/manager.php');
$css = file_get_contents(dirname(__DIR__) . '/css/manager.css');
manager_mode_assert($page !== false && strpos($page, 'Manager attention queue') !== false, 'Manager page exposes an exception queue');
manager_mode_assert($page !== false && strpos($page, 'Driver board') !== false, 'Manager page exposes driver oversight');
manager_mode_assert($page !== false && strpos($page, 'Route closeout') !== false, 'Manager page links delivery reconciliation');
manager_mode_assert($page !== false && strpos($page, 'What is going to production') !== false, 'Manager page exposes production handoff visibility');
manager_mode_assert($page !== false && strpos($page, 'Packing readiness') !== false, 'Manager page exposes packing progress visibility');
manager_mode_assert($page !== false && strpos($page, 'Baker app activity') !== false, 'Manager page exposes baker workflow activity');
manager_mode_assert($page !== false && strpos($page, 'Manager tool suite') !== false, 'Manager page links the workflow adjustment tools');
manager_mode_assert($page !== false && strpos($page, 'Recent workflow audit') !== false, 'Manager page exposes workflow audit records');
manager_mode_assert($page !== false && strpos($page, 'Route planning') !== false, 'Manager page exposes route planning');
manager_mode_assert($page !== false && strpos($page, 'Failed-stop recovery') !== false, 'Manager page exposes failed-stop recovery');
manager_mode_assert($page !== false && strpos($page, 'bakery_manager_exception_save') !== false, 'Manager page uses shared exception work service');
manager_mode_assert($css !== false && strpos($css, '.manager-scorecard') !== false, 'Manager page has responsive workspace styling');
manager_mode_assert($css !== false && strpos($css, '.manager-handoff-board') !== false, 'Manager page styles the bake-to-dispatch handoff board');

exit($failed === 0 ? 0 : 1);
