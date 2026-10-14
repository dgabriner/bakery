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
navigation_test_assert(strpos($managerNav, 'bakery-nav__more') !== false, 'manager navigation keeps critical operations and account controls in More');
navigation_test_assert(strpos($managerNav, 'data-nav-tools-filter') === false, 'manager More does not reopen the all-tools catalog');
navigation_test_assert(strpos($managerNav, 'bakery-nav__more-catalog') === false, 'manager navigation has no nested all-tools catalog');
navigation_test_assert(strpos($managerNav, 'view=routes') !== false, 'manager navigation includes Routes');
navigation_test_assert(strpos($managerNav, 'view=kitchen') !== false, 'manager navigation includes Kitchen');
navigation_test_assert(strpos($managerNav, 'view=missed') !== false, 'manager navigation includes Missed');
navigation_test_assert(strpos($managerNav, 'counter_orders.php?view=pending') !== false, 'manager navigation includes pending counter orders');
navigation_test_assert(preg_match_all('/class="bakery-nav__direct /', $managerNav) === 6, 'manager phone bar is Today, Routes, Kitchen, Missed, Clock, and More');
navigation_test_assert(preg_match('/<a class="bakery-nav__direct [^"]*" href="[^"]*time_clock\.php"/', $managerNav) === 1, 'manager navigation has a direct time clock button');
$managerClockNav = navigation_test_render_nav('manager', 'time_clock');
navigation_test_assert(preg_match('/bakery-nav__direct bakery-nav__direct--active" href="[^"]*time_clock\.php"[^>]*aria-current="page"/', $managerClockNav) === 1, 'manager time clock button is active on the clock page');
foreach ([
    'driver_assignment.php', 'route_manager.php', 'route_summary.php', 'daily_route.php',
    'route_closeout.php', 'drivers.php', 'standing_routes.php', 'route_analysis.php', 'driver.php?change_driver=1',
    'production_manager.php', 'production_center.php', 'product_manager_plan.php', 'production.php',
    'pack_list.php', 'inventory.php', 'driver_load.php', 'ingredient_requirements.php',
    'daily_run.php', 'daily_brief.php', 'index.php', 'daily_orders.php',
] as $criticalManagerTool) {
    navigation_test_assert(strpos($managerNav, $criticalManagerTool) !== false, 'manager More includes critical tool ' . $criticalManagerTool);
}
navigation_test_assert(strpos($managerNav, 'text_comms.php?view=surveys') === false, 'manager navigation omits Survey Center');
navigation_test_assert(strpos($managerNav, 'survey.php') !== false, 'manager More includes the route survey');
$managerSheetPos = strpos($managerNav, 'bakery-nav__more-sheet');
$managerSurveyPos = strpos($managerNav, 'survey.php');
$managerFirstToolPos = strpos($managerNav, 'driver_assignment.php');
navigation_test_assert($managerSurveyPos !== false && $managerSheetPos !== false && $managerSurveyPos > $managerSheetPos, 'manager route survey sits inside More');
navigation_test_assert($managerFirstToolPos !== false && $managerSurveyPos < $managerFirstToolPos, 'manager route survey is the first More shortcut');
$managerSurveyNav = navigation_test_render_nav('manager', 'survey');
navigation_test_assert(strpos($managerSurveyNav, 'bakery-nav__more--active') !== false, 'manager More is active on the route survey');
navigation_test_assert(strpos($managerNav, 'billing_center.php') === false, 'manager navigation omits Billing Center');
navigation_test_assert(strpos($managerNav, 'bakery-nav--ops') === false, 'manager focused nav is not the admin ops shell');

$adminNav = navigation_test_render_nav('administrator', 'users');
navigation_test_assert(strpos($adminNav, 'bakery-nav--ops') !== false, 'administrator navigation uses the operations mobile shell');
navigation_test_assert(strpos($adminNav, 'bakery-nav__billing-shortcut') !== false, 'administrator navigation includes a Billing Center shortcut');
navigation_test_assert(strpos($adminNav, 'billing_center.php') !== false, 'administrator navigation links to Billing Center');
navigation_test_assert(strpos($adminNav, 'User Management') !== false, 'administrator navigation still includes User Management');
navigation_test_assert(strpos($adminNav, 'Shop Photos') !== false, 'administrator navigation labels Shop Photos');
navigation_test_assert(strpos($adminNav, 'nav.item.cashier_shop_photos') === false, 'administrator navigation does not show the Shop Photos translation key');
navigation_test_assert(strpos($adminNav, 'call_headquarters.php') !== false, 'administrator navigation includes Call Headquarters');
navigation_test_assert(strpos($adminNav, 'sfb_admin_learn.php') !== false, 'administrator navigation includes Learning Center');
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
navigation_test_assert(preg_match('/\.bakery-nav--manager \.bakery-nav__more-sheet \{\s*position: absolute;/', $navCss) === 1, 'manager More opens as an overlay instead of stretching the bar');
navigation_test_assert(preg_match('/\.bakery-nav--manager \.bakery-nav__groups \{\s*grid-template-columns: repeat\(6, minmax\(0, 1fr\)\);/', $navCss) === 1, 'manager phone bar keeps Clock on one row with the other tabs');
navigation_test_assert(strpos($navCss, '.bakery-nav:not(.bakery-nav--focused) .bakery-nav__billing-shortcut') !== false, 'ops phone bar can hide shortcuts that crowd out Menu');
navigation_test_assert(strpos($navCss, '.bakery-nav__alerts-toggle[hidden]') !== false, 'alert bell stays hidden until it has something to show');
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
navigation_test_assert(bakery_role_home('cashier') === 'time_clock.php', 'cashier home is the time clock');
navigation_test_assert(bakery_role_uses_dedicated_home('cashier') === true, 'cashier login must not keep a default next of index.php');
navigation_test_assert(bakery_role_uses_dedicated_home('administrator') === false, 'administrators keep the requested next URL');
navigation_test_assert(bakery_ops_index_bypass_home('cashier') === 'time_clock.php', 'cashiers hitting the ops dashboard are sent to the time clock');
navigation_test_assert(bakery_ops_index_bypass_home('administrator') === null, 'administrators may open the ops dashboard');
navigation_test_assert(isset(bakery_assignable_role_labels()['cashier']), 'User Management can assign the cashier type');
$loginSrc = (string)file_get_contents(dirname(__DIR__) . '/login.php');
$usersSrc = (string)file_get_contents(dirname(__DIR__) . '/users.php');
navigation_test_assert(strpos($loginSrc, 'bakery_role_home') !== false, 'login sends staff to bakery_role_home');
navigation_test_assert(strpos($usersSrc, 'bakery_assignable_role_labels') !== false, 'users.php validates types via bakery_assignable_role_labels');

$cashierNav = navigation_test_render_nav('cashier', 'product_photos');
navigation_test_assert(strpos($cashierNav, 'bakery-nav bakery-nav--focused bakery-nav--cashier') !== false, 'cashier navigation uses the focused cashier shell');
navigation_test_assert(strpos($cashierNav, 'bakery-nav--ops') === false, 'cashier navigation is not the admin ops shell');
navigation_test_assert(strpos($cashierNav, 'time_clock.php') !== false, 'cashier navigation links Time Clock');
navigation_test_assert(strpos($cashierNav, 'product_photos.php') !== false, 'cashier navigation links Product Photos');
navigation_test_assert(strpos($cashierNav, 'cashier_shop_photos.php') !== false, 'cashier navigation links Shop Photos');
navigation_test_assert(strpos($cashierNav, 'cashier_add_product.php') !== false, 'cashier navigation links Add Product');
navigation_test_assert(strpos($cashierNav, 'counter_orders.php') !== false, 'cashier navigation links counter orders');
navigation_test_assert(strpos($cashierNav, 'href="' . BASE_URL . 'index.php"') === false, 'cashier navigation does not link the ops dashboard');
navigation_test_assert(strpos($cashierNav, 'manager.php') === false, 'cashier navigation does not link Manager Mode');
navigation_test_assert(strpos($cashierNav, 'bakery-nav__logout') !== false, 'cashier navigation includes logout in the focused bar');

$headerSrc = (string)file_get_contents(dirname(__DIR__) . '/includes/header.php');
navigation_test_assert(strpos($headerSrc, 'workspace-cashier') !== false, 'header treats cashier as a focused workspace');
$navCss = (string)file_get_contents(dirname(__DIR__) . '/css/nav.css');
navigation_test_assert(strpos($navCss, '.bakery-nav--cashier .bakery-nav__groups') !== false, 'nav.css lays out the cashier focused bar');

$bakerNav = navigation_test_render_nav('baker', 'production');
navigation_test_assert(strpos($bakerNav, bakery_t('nav.baker_today')) !== false, 'baker navigation includes Today');
navigation_test_assert(strpos($bakerNav, 'production.php') !== false, 'baker Today links to Daily Production home');
navigation_test_assert(preg_match_all('/class="bakery-nav__direct(?:\s|")/', $bakerNav) === 2, 'baker navigation has Today and Orders');
navigation_test_assert(strpos($bakerNav, 'counter_orders.php?view=pending') !== false, 'baker navigation links counter orders');
navigation_test_assert(strpos($bakerNav, 'Production Center') === false, 'baker navigation omits Production Center');
navigation_test_assert(strpos($bakerNav, 'bakery-nav--baker') !== false, 'baker navigation uses the compact mobile bar');
navigation_test_assert(strpos($bakerNav, 'bakery-nav__logout') !== false, 'baker navigation includes logout in the focused bar');
navigation_test_assert(strpos($bakerNav, 'bakery-nav__label-short') !== false, 'baker navigation includes compact mobile labels');
require_once dirname(__DIR__) . '/includes/kitchen_segments.php';
navigation_test_assert(strpos(bakery_role_home('baker'), bakery_baker_home_delivery_date()) !== false, 'baker login opens tomorrow\'s delivery');
$navDateBefore = $_GET['date'] ?? null;
$_GET['date'] = '2099-08-07';
$_GET['line'] = '4';
$bakerKept = navigation_test_render_nav('baker', 'baker_mix');
navigation_test_assert(strpos($bakerKept, 'production.php?date=2099-08-07') !== false, 'baker workspace keeps the selected delivery date');
navigation_test_assert(strpos($bakerKept, 'production.php?date=' . bakery_baker_home_delivery_date()) !== false, 'baker Today brand resets to tomorrow\'s delivery');
navigation_test_assert(strpos($bakerKept, 'counter_orders.php?view=pending&amp;date=2099-08-07') !== false || strpos($bakerKept, 'counter_orders.php?view=pending&date=2099-08-07') !== false, 'baker orders link keeps the delivery date');
$bakerSegments = bakery_kitchen_segments_html('mix', '2099-08-07');
navigation_test_assert(strpos($bakerSegments, 'baker_mix.php?') !== false && strpos($bakerSegments, 'date=2099-08-07') !== false, 'mix segment keeps the selected date');
navigation_test_assert(strpos($bakerSegments, 'production.php?') !== false && substr_count($bakerSegments, 'date=2099-08-07') >= 3, 'bake and pack segments keep the selected date');
navigation_test_assert(strpos($bakerSegments, 'line=4') !== false, 'segment hop keeps the line filter');
$saturdayAria = bakery_baker_delivery_aria('2026-10-17');
navigation_test_assert(strpos($saturdayAria, 'Oct 17') !== false && strpos($saturdayAria, 'Oct 16') !== false, 'Saturday delivery names the Friday pan dulce bake');
$_GET['date'] = '2099-08-08';
$driverKept = navigation_test_render_nav('driver', 'driver');
navigation_test_assert(strpos($driverKept, 'driver.php?date=2099-08-08') !== false, 'driver My Route keeps the selected date');
navigation_test_assert(strpos($driverKept, 'pack_list.php?date=2099-08-08') !== false, 'driver pack shortcut uses the selected route date');
navigation_test_assert(strpos($driverKept, 'pack_list.php?date=' . date('Y-m-d')) === false, 'driver pack shortcut does not open calendar today');
$_GET['date'] = 'not-a-date';
$driverBad = navigation_test_render_nav('driver', 'driver');
navigation_test_assert(strpos($driverBad, 'pack_list.php?date=' . rawurlencode(date('Y-m-d'))) !== false, 'invalid driver date falls back to today');
if ($navDateBefore === null) {
    unset($_GET['date']);
} else {
    $_GET['date'] = $navDateBefore;
}
unset($_GET['line']);

$driverNav = navigation_test_render_nav('driver', 'driver');
navigation_test_assert(strpos($driverNav, 'bakery-nav--driver') !== false, 'driver navigation uses the compact mobile bar');
navigation_test_assert(strpos($driverNav, 'bakery-nav__logout') !== false, 'driver navigation includes logout in the focused bar');
navigation_test_assert(strpos($navCss, 'position: fixed') !== false, 'focused navigation docks to the bottom on small screens');
navigation_test_assert(strpos($driverNav, 'bakery-nav__tomorrow') !== false, 'driver navigation includes a Tomorrow shortcut');
navigation_test_assert(strpos($driverNav, 'bakery-nav__live-dot') !== false, 'driver navigation includes the live status dot');
navigation_test_assert(strpos($driverNav, 'routeDateNavToggle') !== false, 'driver My Route navigation includes a date toggle');
navigation_test_assert(strpos($driverNav, 'bakery-nav--with-date') !== false, 'driver My Route navigation marks the date-capable bar');
navigation_test_assert(strpos($driverNav, 'bakery-nav__more') !== false, 'driver navigation parks Pack, Stops, and QR behind More');
navigation_test_assert(strpos($driverNav, 'time_clock.php') !== false, 'driver More includes the time clock');
navigation_test_assert(strpos($adminNav, 'time_clock.php') !== false, 'administrator navigation includes Time Clock');
navigation_test_assert(strpos($driverNav, 'survey.php') !== false, 'driver primary nav includes Survey');
$driverSurveyPos = strpos($driverNav, 'survey.php');
$driverMorePos = strpos($driverNav, 'bakery-nav__more');
navigation_test_assert($driverSurveyPos !== false && $driverMorePos !== false && $driverSurveyPos < $driverMorePos, 'driver Survey is a primary direct link before More');
navigation_test_assert(strpos($driverNav, 'driver_stops.php') !== false, 'Stops remains reachable from More');
navigation_test_assert(strpos($navCss, '.bakery-nav--driver.bakery-nav--with-date .bakery-nav__groups') !== false && strpos($navCss, 'repeat(5, minmax(0, 1fr))') !== false, 'driver date bar keeps My Route, Date, Survey, Call HQ, and More');

$driverHqNav = navigation_test_render_nav('driver', 'call_headquarters');
navigation_test_assert(strpos($driverHqNav, 'routeDateNavToggle') === false, 'driver Call HQ navigation omits the route date toggle');
navigation_test_assert(strpos($driverHqNav, 'survey.php') !== false, 'driver Call HQ bar still includes Survey');
$driverHqGroups = '';
if (preg_match('/bakery-nav__groups">(.*?)<\/div>\s*<\/div>\s*<\/nav>/s', $driverHqNav, $driverHqMatch)) {
    $driverHqGroups = $driverHqMatch[1];
}
navigation_test_assert(substr_count($driverHqGroups, 'bakery-nav__direct') >= 4, 'driver non-date bar has Survey among primary directs');

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
    if (in_array($script, bakery_navigation_disabled_scripts(), true)) {
        continue;
    }
    if (bakery_navigation_roles_for_script($script) === []) {
        $unresolved[] = $script;
    }
}
navigation_test_assert($unresolved === [], 'every non-public/portal root page resolves to at least one catalog or registry role' . ($unresolved ? (': ' . implode(', ', $unresolved)) : ''));
navigation_test_assert(bakery_navigation_roles_for_script('users.php') === ['administrator'], 'User Management stays administrator-only from the catalog');
navigation_test_assert(bakery_navigation_roles_for_script('generate_invoice_simple.php') === ['administrator', 'manager'], 'simple invoice bookmark redirects for billing roles');
navigation_test_assert(bakery_navigation_roles_for_script('simple_invoice.php') === ['administrator', 'manager'], 'printable invoice bookmark redirects for billing roles');
navigation_test_assert(bakery_navigation_roles_for_script('products_new.php') === ['administrator'], 'old product editor stays administrator-only');
navigation_test_assert(bakery_navigation_roles_for_script('get_customers_no_address.php') === [], 'disabled address utility is not granted a role');
navigation_test_assert(in_array('get_customers_no_address.php', bakery_navigation_disabled_scripts(), true), 'disabled address utility is an explicit coverage exception');
$catalogHrefs = [];
foreach (bakery_navigation_catalog() as $section) {
    foreach ($section['items'] ?? [] as $item) {
        $catalogHrefs[] = (string)($item['href'] ?? '');
    }
}
navigation_test_assert(!in_array('products_new.php', $catalogHrefs, true), 'old product editor stays off the menu');
navigation_test_assert(!in_array('get_customers_no_address.php', $catalogHrefs, true), 'disabled address utility stays off the menu');
navigation_test_assert(!in_array('generate_invoice_simple.php', $catalogHrefs, true), 'quarantined invoice generator stays off the menu');
navigation_test_assert(!in_array('simple_invoice.php', $catalogHrefs, true), 'quarantined printable invoice stays off the menu');
navigation_test_assert(in_array('baker_mix.php', bakery_navigation_scripts_for_role('baker'), true), 'catalog grants bakers Mix Today');
navigation_test_assert(!in_array('daily_orders.php', bakery_navigation_scripts_for_role('driver'), true), 'catalog does not grant drivers Daily Orders');

$moduleGuideSrc = file_get_contents($rootDir . '/module_guide.php');
navigation_test_assert(strpos($moduleGuideSrc, 'Mix Today') !== false, 'module guide baker blurb includes Mix Today');
$accessGuideSrc = file_get_contents($rootDir . '/docs/MODULE_ACCESS_GUIDE.md');
navigation_test_assert(strpos($accessGuideSrc, 'Mix Today') !== false, 'MODULE_ACCESS_GUIDE documents Mix Today for bakers');

exit($failed === 0 ? 0 : 1);
