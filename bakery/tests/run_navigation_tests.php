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

require_once dirname(__DIR__) . '/includes/i18n.php';
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

if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
function navigation_test_render_nav($role, $page = 'index') {
    $_SESSION = [
        'user_id' => 999,
        'user_role_slug' => $role,
        'user_display_name' => 'Navigation Test',
    ];
    $_SERVER['PHP_SELF'] = '/' . $page . '.php';
    ob_start();
    include dirname(__DIR__) . '/includes/nav.php';
    return ob_get_clean();
}

$adminGroups = navigation_test_group_keys('administrator');
$managerGroups = navigation_test_group_keys('manager');
$adminSections = bakery_navigation_sections_for_role('administrator');
$managerSections = bakery_navigation_sections_for_role('manager');

navigation_test_assert(in_array('administration', $adminGroups, true), 'administrators receive Administration');
navigation_test_assert(!in_array('administration', $managerGroups, true), 'managers do not receive Administration');
navigation_test_assert(count($managerGroups) === 6, 'managers receive all six operational areas');
navigation_test_assert(array_column($adminSections, 'key') === ['primary', 'extras'], 'administrators receive Primary work and Extras sections');
navigation_test_assert(array_column($managerSections, 'key') === ['primary', 'extras'], 'managers receive Primary work and Extras sections');

$adminItems = [];
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    $adminItems = array_merge($adminItems, array_column($group['items'], 'href'));
}
navigation_test_assert(in_array('agent_homebase.php', $adminItems, true), 'administrators receive Agent Homebase');
navigation_test_assert(in_array('users.php', $adminItems, true), 'administrators receive User Management');
navigation_test_assert(in_array('historical_navigation.php', $adminItems, true), 'administrators receive Historical Navigation');
navigation_test_assert(in_array('manager.php', $adminItems, true), 'administrators receive Manager Mode');
navigation_test_assert(in_array('route_summary.php', $adminItems, true), 'administrators receive Route Summary');
navigation_test_assert(in_array('walkthroughs.php', $adminItems, true), 'administrators receive Walkthroughs');
navigation_test_assert(in_array('driver.php?change_driver=1', $adminItems, true), 'administrators receive My Route');
navigation_test_assert(in_array('text_comms.php?view=surveys', $adminItems, true), 'administrators receive Survey Center');

$surveyCenterLabels = [];
foreach (bakery_navigation_groups_for_role('manager') as $group) {
    foreach ($group['items'] as $item) {
        if (($item['href'] ?? '') === 'text_comms.php?view=surveys') {
            $surveyCenterLabels[] = (string)($item['label'] ?? '');
        }
    }
}
navigation_test_assert($surveyCenterLabels !== [] && !in_array('nav.item.survey_center', $surveyCenterLabels, true), 'Survey Center translates via nav_key');
navigation_test_assert(in_array('Survey Center', $surveyCenterLabels, true), 'Survey Center label resolves in English');

navigation_test_assert(in_array('production.php', bakery_baker_scripts(), true), 'bakers can access Daily Production');
navigation_test_assert(in_array('baker_mix.php', bakery_baker_scripts(), true), 'bakers can access Mix Today');
navigation_test_assert(in_array('pack_list.php', bakery_baker_scripts(), true), 'bakers can access Pack List');
navigation_test_assert(!in_array('index.php', bakery_baker_scripts(), true), 'bakers cannot access the operations dashboard');
navigation_test_assert(!in_array('production_center.php', bakery_baker_scripts(), true), 'bakers cannot access Production Center');
navigation_test_assert(!in_array('production_manager.php', bakery_baker_scripts(), true), 'bakers cannot access Production Manager Dashboard');
navigation_test_assert(in_array('driver.php', bakery_driver_scripts(), true), 'drivers can access My Route');
navigation_test_assert(in_array('qr_login.php', bakery_driver_scripts(), true), 'drivers can access Customer QR Login');
navigation_test_assert(in_array('call_headquarters.php', bakery_driver_scripts(), true), 'drivers can access Call HQ');

$managerNav = navigation_test_render_nav('manager', 'production');
navigation_test_assert(strpos($managerNav, 'bakery-nav--manager') !== false, 'manager navigation uses the focused manager shell');
navigation_test_assert(strpos($managerNav, 'bakery-nav--focused') !== false, 'manager navigation is a focused workspace');
navigation_test_assert(strpos($managerNav, 'bakery-nav__menu-toggle') === false, 'manager phone navigation has no hamburger');
navigation_test_assert(strpos($managerNav, 'bakery-nav__more') !== false, 'manager navigation keeps extras in More');
navigation_test_assert(strpos($managerNav, 'data-nav-tools-filter') !== false, 'manager More has a searchable All tools filter');
navigation_test_assert(strpos($managerNav, 'bakery-nav__more-catalog') === false, 'manager All tools is a flat searchable sheet, not nested details');
$moreBeforeTools = strpos($managerNav, 'data-nav-tools-sheet');
navigation_test_assert($moreBeforeTools !== false, 'manager More includes the All tools sheet');
$primaryLinkCount = $moreBeforeTools === false ? 0 : substr_count(substr($managerNav, 0, $moreBeforeTools), 'bakery-nav__more-link');
navigation_test_assert($primaryLinkCount === 8, 'manager More primary shortcuts stay at eight');
navigation_test_assert(strpos($managerNav, 'view=routes') !== false, 'manager navigation includes Routes');
navigation_test_assert(strpos($managerNav, 'view=kitchen') !== false, 'manager navigation includes Kitchen');
navigation_test_assert(strpos($managerNav, 'view=missed') !== false, 'manager navigation includes Missed');
navigation_test_assert(strpos($managerNav, 'daily_orders.php') !== false, 'manager More includes Daily Orders');
navigation_test_assert(strpos($managerNav, 'text_comms.php?view=surveys') !== false, 'manager More includes Survey Center');
navigation_test_assert(strpos($managerNav, 'billing_center.php') !== false, 'manager More includes Billing Center');
navigation_test_assert(strpos($managerNav, 'bakery-nav--ops') === false, 'manager focused nav is not the admin ops shell');

$adminNav = navigation_test_render_nav('administrator', 'users');
navigation_test_assert(strpos($adminNav, 'bakery-nav--ops') !== false, 'administrator navigation uses the operations mobile shell');
navigation_test_assert(strpos($adminNav, 'bakery-nav__billing-shortcut') !== false, 'administrator navigation includes a Billing Center shortcut');
navigation_test_assert(strpos($adminNav, 'billing_center.php') !== false, 'administrator navigation links to Billing Center');
navigation_test_assert(strpos($adminNav, 'User Management') !== false, 'administrator navigation still includes User Management');
navigation_test_assert(strpos($adminNav, 'bakery-nav__section--primary') !== false, 'administrator navigation marks Primary work');
navigation_test_assert(strpos($adminNav, 'bakery-nav__section--extras') !== false, 'administrator navigation marks Extras & setup');
navigation_test_assert(strpos($adminNav, 'bakery-nav__usage-legend') !== false, 'administrator navigation includes usage legend');
navigation_test_assert(strpos($adminNav, 'bakery-nav__usage-mark') !== false, 'administrator navigation includes usage color marks');
navigation_test_assert(strpos($adminNav, 'bakery-nav__usage-legend-slot--drawer') !== false, 'administrator navigation keeps a mobile drawer legend slot');
navigation_test_assert(strpos($adminNav, 'bakery-nav__usage-legend-slot--panel') !== false, 'administrator navigation keeps a desktop panel legend slot');
navigation_test_assert(strpos($adminNav, 'bakery-nav__item--usage-everyday') !== false, 'administrator navigation marks everyday items');
navigation_test_assert(strpos($adminNav, 'bakery-nav__item--usage-moderate') !== false, 'administrator navigation marks moderate items');
navigation_test_assert(strpos($adminNav, 'bakery-nav__item--usage-occasional') !== false, 'administrator navigation marks occasional items');
navigation_test_assert(strpos($adminNav, 'Everyday') === false && strpos($adminNav, 'Moderate') === false && strpos($adminNav, 'Occasional') === false, 'administrator navigation omits usage words');
$navCss = file_get_contents(dirname(__DIR__) . '/css/nav.css');
navigation_test_assert(strpos($navCss, '.bakery-nav--ops .bakery-nav__usage-mark') !== false, 'nav.css styles the usage mark for ops nav');
navigation_test_assert(strpos($navCss, '.bakery-nav--ops .bakery-nav__item--usage-everyday') !== false, 'nav.css tints everyday ops items');
navigation_test_assert(strpos($navCss, '.bakery-nav__group[open] > .bakery-nav__panel') !== false, 'nav.css only shows panels when a group is open');
navigation_test_assert(strpos($navCss, '@media (max-width: 1180px)') !== false, 'ops navigation uses a tablet-width drawer instead of overlapping the top bar');
navigation_test_assert(strpos($adminNav, 'data-drawer-breakpoint="1180"') !== false, 'ops navigation exposes the drawer breakpoint to script');

$usageCounts = ['everyday' => 0, 'moderate' => 0, 'occasional' => 0];
$adminItemCount = 0;
foreach (bakery_navigation_groups_for_role('administrator') as $group) {
    foreach ($group['items'] as $item) {
        $adminItemCount++;
        $usage = bakery_navigation_normalize_usage($item['usage'] ?? '');
        $usageCounts[$usage]++;
    }
}
navigation_test_assert($adminItemCount > 0 && array_sum($usageCounts) === $adminItemCount, 'all administrator items carry a usage level');
navigation_test_assert($usageCounts['everyday'] >= 10, 'everyday bucket includes the core operating tabs');
navigation_test_assert($usageCounts['occasional'] >= 10, 'occasional bucket includes setup and admin tabs');

navigation_test_assert(function_exists('bakery_role_home'), 'role home helper exists');
navigation_test_assert(bakery_role_home('cashier') === 'product_photos.php', 'cashier home is product photos catalog');
navigation_test_assert(bakery_role_uses_dedicated_home('cashier') === true, 'cashier login must not keep a default next of index.php');
navigation_test_assert(bakery_role_uses_dedicated_home('administrator') === false, 'administrators keep the requested next URL');
navigation_test_assert(bakery_ops_index_bypass_home('cashier') === 'product_photos.php', 'cashiers hitting the ops dashboard are sent to product photos');
navigation_test_assert(bakery_ops_index_bypass_home('administrator') === null, 'administrators may open the ops dashboard');
navigation_test_assert(isset(bakery_assignable_role_labels()['cashier']), 'User Management can assign the cashier type');
$loginSrc = (string)file_get_contents(dirname(__DIR__) . '/login.php');
$usersSrc = (string)file_get_contents(dirname(__DIR__) . '/users.php');
navigation_test_assert(strpos($loginSrc, 'bakery_role_home') !== false, 'login sends staff to bakery_role_home');
navigation_test_assert(strpos($usersSrc, 'bakery_assignable_role_labels') !== false, 'users.php validates types via bakery_assignable_role_labels');

$cashierNav = navigation_test_render_nav('cashier', 'product_photos');
navigation_test_assert(strpos($cashierNav, 'bakery-nav bakery-nav--focused bakery-nav--cashier') !== false, 'cashier navigation uses the focused cashier shell');
navigation_test_assert(strpos($cashierNav, 'bakery-nav--ops') === false, 'cashier navigation is not the admin ops shell');
navigation_test_assert(strpos($cashierNav, 'product_photos.php') !== false, 'cashier navigation links Product Photos');
navigation_test_assert(strpos($cashierNav, 'cashier_shop_photos.php') !== false, 'cashier navigation links Shop Photos');
navigation_test_assert(strpos($cashierNav, 'cashier_add_product.php') !== false, 'cashier navigation links Add Product');
navigation_test_assert(strpos($cashierNav, 'href="' . BASE_URL . 'index.php"') === false, 'cashier navigation does not link the ops dashboard');
navigation_test_assert(strpos($cashierNav, 'manager.php') === false, 'cashier navigation does not link Manager Mode');
navigation_test_assert(strpos($cashierNav, 'bakery-nav__logout') !== false, 'cashier navigation includes logout in the focused bar');

$headerSrc = (string)file_get_contents(dirname(__DIR__) . '/includes/header.php');
navigation_test_assert(strpos($headerSrc, 'workspace-cashier') !== false, 'header treats cashier as a focused workspace');
$navCss = (string)file_get_contents(dirname(__DIR__) . '/css/nav.css');
navigation_test_assert(strpos($navCss, '.bakery-nav--cashier .bakery-nav__groups') !== false, 'nav.css lays out the cashier focused bar');

$bakerNav = navigation_test_render_nav('baker', 'production');
navigation_test_assert(strpos($bakerNav, 'Daily production') !== false, 'baker navigation includes Daily Production');
navigation_test_assert(strpos($bakerNav, 'Mix today') !== false, 'baker navigation includes Mix Today');
navigation_test_assert(strpos($bakerNav, 'baker_mix.php') !== false, 'baker navigation links Mix Today');
navigation_test_assert(strpos($bakerNav, 'Production Center') === false, 'baker navigation omits Production Center');
navigation_test_assert(strpos($bakerNav, 'bakery-nav--baker') !== false, 'baker navigation uses the compact mobile bar');
navigation_test_assert(strpos($bakerNav, 'bakery-nav__logout') !== false, 'baker navigation includes logout in the focused bar');
navigation_test_assert(strpos($bakerNav, 'bakery-nav__label-short') !== false, 'baker navigation includes compact mobile labels');

$driverNav = navigation_test_render_nav('driver', 'driver');
navigation_test_assert(strpos($driverNav, 'bakery-nav--driver') !== false, 'driver navigation uses the compact mobile bar');
navigation_test_assert(strpos($driverNav, 'bakery-nav__logout') !== false, 'driver navigation includes logout in the focused bar');
navigation_test_assert(strpos($navCss, 'position: fixed') !== false, 'focused navigation docks to the bottom on small screens');
navigation_test_assert(strpos($driverNav, 'bakery-nav__tomorrow') !== false, 'driver navigation includes a Tomorrow shortcut');
navigation_test_assert(strpos($driverNav, 'bakery-nav__live-dot') !== false, 'driver navigation includes the live status dot');
navigation_test_assert(strpos($driverNav, 'routeDateNavToggle') !== false, 'driver My Route navigation includes a date toggle');
navigation_test_assert(strpos($driverNav, 'bakery-nav--with-date') !== false, 'driver My Route navigation marks the date-capable bar');
navigation_test_assert(strpos($driverNav, 'bakery-nav__more') !== false, 'driver navigation parks Pack, Stops, and QR behind More');
navigation_test_assert(strpos($driverNav, 'survey.php') !== false, 'driver More includes Tomorrow\'s stores survey');
navigation_test_assert(strpos($driverNav, 'driver_stops.php') !== false, 'Stops remains reachable from More');
navigation_test_assert(strpos($navCss, 'repeat(4, minmax(0, 1fr))') !== false, 'driver date bar keeps My Route, Date, Call HQ, and More');

$driverHqNav = navigation_test_render_nav('driver', 'call_headquarters');
navigation_test_assert(strpos($driverHqNav, 'routeDateNavToggle') === false, 'driver Call HQ navigation omits the route date toggle');

echo "=== Catalog is the role allowlist ===\n";
require_once dirname(__DIR__) . '/includes/customer_portal.php';
require_once dirname(__DIR__) . '/includes/sf_baker.php';
$rootDir = dirname(__DIR__);
$exemptScripts = array_values(array_unique(array_merge(
    bakery_public_scripts(),
    bakery_diagnostic_scripts(),
    bakery_customer_portal_scripts(),
    bakery_sfb_portal_scripts(),
    bakery_sfb_community_scripts()
)));
$unresolved = [];
foreach (glob($rootDir . '/*.php') as $path) {
    $script = basename($path);
    if (in_array($script, $exemptScripts, true) || strpos($script, 'customer_portal_') === 0) {
        continue;
    }
    if (bakery_navigation_roles_for_script($script) === []) {
        $unresolved[] = $script;
    }
}
navigation_test_assert($unresolved === [], 'every non-public/portal root page resolves to at least one catalog or registry role' . ($unresolved ? (': ' . implode(', ', $unresolved)) : ''));
navigation_test_assert(bakery_navigation_roles_for_script('users.php') === ['administrator'], 'User Management stays administrator-only from the catalog');
navigation_test_assert(in_array('baker_mix.php', bakery_navigation_scripts_for_role('baker'), true), 'catalog grants bakers Mix Today');
navigation_test_assert(!in_array('daily_orders.php', bakery_navigation_scripts_for_role('driver'), true), 'catalog does not grant drivers Daily Orders');

$moduleGuideSrc = file_get_contents($rootDir . '/module_guide.php');
navigation_test_assert(strpos($moduleGuideSrc, 'Mix Today') !== false, 'module guide baker blurb includes Mix Today');
$accessGuideSrc = file_get_contents($rootDir . '/docs/MODULE_ACCESS_GUIDE.md');
navigation_test_assert(strpos($accessGuideSrc, 'Mix Today') !== false, 'MODULE_ACCESS_GUIDE documents Mix Today for bakers');

exit($failed === 0 ? 0 : 1);
