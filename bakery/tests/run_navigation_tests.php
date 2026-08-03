<?php
/**
 * Non-destructive role-navigation contract checks.
 * Usage: php tests/run_navigation_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

require_once dirname(__DIR__) . '/includes/navigation_catalog.php';
require_once dirname(__DIR__) . '/includes/auth.php';

$passed = 0;
$failed = 0;

function navigation_test_assert($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function navigation_test_group_keys($role) {
    return array_column(bakery_navigation_groups_for_role($role), 'key');
}

$adminGroups = navigation_test_group_keys('administrator');
$managerGroups = navigation_test_group_keys('manager');

navigation_test_assert(in_array('administration', $adminGroups, true), 'administrators receive Administration');
navigation_test_assert(!in_array('administration', $managerGroups, true), 'managers do not receive Administration');
navigation_test_assert(count($managerGroups) === 6, 'managers receive all six operational areas');

$adminItems = [];
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    $adminItems = array_merge($adminItems, array_column($group['items'], 'href'));
}
navigation_test_assert(in_array('users.php', $adminItems, true), 'administrators receive User Management');
navigation_test_assert(in_array('historical_navigation.php', $adminItems, true), 'administrators receive Historical Navigation');

navigation_test_assert(in_array('production.php', bakery_baker_scripts(), true), 'bakers can access Daily Production');
navigation_test_assert(in_array('pack_list.php', bakery_baker_scripts(), true), 'bakers can access Pack List');
navigation_test_assert(!in_array('index.php', bakery_baker_scripts(), true), 'bakers cannot access the operations dashboard');
navigation_test_assert(!in_array('production_center.php', bakery_baker_scripts(), true), 'bakers cannot access Production Center');
navigation_test_assert(in_array('driver.php', bakery_driver_scripts(), true), 'drivers can access My Route');
navigation_test_assert(in_array('call_headquarters.php', bakery_driver_scripts(), true), 'drivers can access Call HQ');

exit($failed === 0 ? 0 : 1);
