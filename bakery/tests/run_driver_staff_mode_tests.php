<?php
/**
 * Driver stop flow (Photo 2, camera choice) and managers who also drive.
 *
 * Covers the second-photo contract in the stop flow, and the Driving mode a
 * manager or administrator gets when their name matches one driver profile.
 *
 * Usage: php tests/run_driver_staff_mode_tests.php
 * Database: bakerysf_test only. Temporary drivers are removed at the end.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
/** @var PDO $db */
$db = require __DIR__ . '/harness.php';

require_once $root . '/includes/i18n.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/navigation_catalog.php';
require_once $root . '/includes/staff_driver_profile.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

/** Render includes/nav.php for a signed-in role with the given selected driver. */
function staff_mode_render_nav(PDO $db, string $role, string $displayName, string $page, int $selectedDriverId, array $get = [], array $sessionExtra = []): string
{
    $_SESSION = [
        'user_id' => 998,
        'user_role_slug' => $role,
        'user_display_name' => $displayName,
        'admin_workspace_mode' => 'full',
    ];
    foreach ($sessionExtra as $sessionKey => $sessionValue) {
        $_SESSION[$sessionKey] = $sessionValue;
    }
    if ($selectedDriverId > 0) {
        $_SESSION['selected_driver_id'] = $selectedDriverId;
    }
    $_GET = $get;
    $_SERVER['PHP_SELF'] = '/' . $page . '.php';
    return (static function () use ($db) {
        ob_start();
        include dirname(__DIR__) . '/includes/nav.php';
        return (string)ob_get_clean();
    })();
}

$tag = 'Zq' . substr(md5((string)microtime(true)), 0, 6);
$ownName = $tag . ' Laura';
$otherName = $tag . ' Marcos';
$archivedName = $tag . ' Archie';

$insertDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$createdIds = [];
foreach ([$ownName, $otherName, $archivedName] as $name) {
    $insertDriver->execute([$name]);
    $createdIds[$name] = (int)$db->lastInsertId();
}
$db->prepare('UPDATE drivers SET archived = 1 WHERE id = ?')->execute([$createdIds[$archivedName]]);

try {
    // --- Name matching (pure) -------------------------------------------------
    $pool = [
        ['id' => 1, 'name' => 'Laura'],
        ['id' => 2, 'name' => 'Marcos'],
        ['id' => 3, 'name' => 'Sergio Ramírez'],
    ];
    assert_eq(1, bakery_staff_pick_own_driver($pool, 'Laura'), 'exact one-word name picks that driver');
    assert_eq(1, bakery_staff_pick_own_driver($pool, '  laura '), 'match ignores case and stray spaces');
    assert_eq(3, bakery_staff_pick_own_driver($pool, 'sergio ramirez'), 'match ignores accents');
    assert_eq(3, bakery_staff_pick_own_driver($pool, 'Sergio'), 'a one-word staff name picks the one driver who starts with it');
    assert_eq(0, bakery_staff_pick_own_driver($pool, 'Laura Chen'), 'a two-word staff name never matches a different person by first name');
    assert_eq(0, bakery_staff_pick_own_driver($pool, 'Nobody'), 'no match returns 0 so the normal picker is used');
    assert_eq(0, bakery_staff_pick_own_driver($pool, ''), 'blank name returns 0');
    $twins = [['id' => 5, 'name' => 'Ana'], ['id' => 6, 'name' => 'Ana']];
    assert_eq(0, bakery_staff_pick_own_driver($twins, 'Ana'), 'two drivers with the same name is ambiguous and returns 0');
    $firstOnly = [['id' => 7, 'name' => 'Pat Lee'], ['id' => 8, 'name' => 'Pat Wu']];
    assert_eq(0, bakery_staff_pick_own_driver($firstOnly, 'Pat'), 'a first name shared by two drivers is ambiguous and returns 0');

    // --- Against the drivers table -------------------------------------------
    $manager = ['id' => 998, 'role_slug' => 'manager', 'display_name' => $ownName, 'driver_id' => null];
    $admin = ['id' => 997, 'role_slug' => 'administrator', 'display_name' => $ownName, 'driver_id' => null];
    assert_eq($createdIds[$ownName], bakery_staff_own_driver_id($db, $manager), 'a manager finds the driver profile with their name');
    assert_eq($createdIds[$ownName], bakery_staff_own_driver_id($db, $admin), 'an administrator finds the driver profile with their name');
    foreach (['baker', 'cashier', 'driver', 'driver_assistant', 'sales'] as $role) {
        $other = ['id' => 996, 'role_slug' => $role, 'display_name' => $ownName, 'driver_id' => null];
        assert_eq(0, bakery_staff_own_driver_id($db, $other), "role $role never gets a staff-owned driver profile");
    }
    $archivedStaff = ['id' => 995, 'role_slug' => 'manager', 'display_name' => $archivedName, 'driver_id' => null];
    assert_eq(0, bakery_staff_own_driver_id($db, $archivedStaff), 'an archived driver profile is not matched');
    assert_eq(0, bakery_staff_own_driver_id($db, null), 'signed-out returns 0');
    assert_true(bakery_staff_driving_as_self($db, $manager, $createdIds[$ownName]), 'driving as self is true on the own profile');
    assert_true(!bakery_staff_driving_as_self($db, $manager, $createdIds[$otherName]), 'viewing another driver is not driving as self');
    assert_true(!bakery_staff_driving_as_self($db, $manager, 0), 'no selected driver is not driving as self');
    assert_eq('/driver.php?me=1', bakery_staff_my_route_href('/'), 'My Route link skips the picker');
    assert_eq('/driver.php?me=1&date=2026-10-14', bakery_staff_my_route_href('/', '2026-10-14'), 'My Route link keeps a valid date');
    assert_eq('/driver.php?me=1', bakery_staff_my_route_href('/', 'tomorrow; drop table'), 'My Route link drops an invalid date');
    assert_eq('driver.php?me=1', bakery_staff_login_home($db, $manager), 'a manager who drives lands on her own route');
    assert_eq('manager.php', bakery_staff_login_home($db, ['id' => 994, 'role_slug' => 'manager', 'display_name' => $tag . ' Nobody']), 'a manager with no driver profile still lands on manager tools');
    assert_eq('index.php', bakery_staff_login_home($db, $admin), 'an administrator login stays on the ops home');
    assert_eq('driver.php', bakery_staff_login_home($db, ['id' => 993, 'role_slug' => 'driver', 'display_name' => $ownName]), 'a driver login stays on the route');

    // --- Nav: focused Driving mode on the manager's own route -----------------
    $ownDriver = $createdIds[$ownName];
    $otherDriver = $createdIds[$otherName];

    $driving = staff_mode_render_nav($db, 'manager', $ownName, 'driver', $ownDriver);
    assert_true(strpos($driving, 'bakery-nav--driver') !== false, 'manager on their own route sees the focused Driving nav');
    assert_true(strpos($driving, 'bakery-nav--manager') === false, 'manager on their own route does not see the Manager nav');
    assert_true(strpos($driving, 'manager.php') !== false && strpos($driving, 'bakery-nav__more-link--staff') !== false, 'Driving nav keeps one clear door back to Manager tools');
    assert_true(strpos($driving, bakery_t('nav.driving_manager_home')) !== false, 'the door is labeled Manager tools');
    assert_true(strpos($driving, 'change_driver=1') !== false, 'More still offers Change driver for supervising another route');

    $adminDriving = staff_mode_render_nav($db, 'administrator', $ownName, 'driver', $ownDriver);
    assert_true(strpos($adminDriving, 'bakery-nav--driver') !== false, 'administrator on their own route sees the focused Driving nav');
    assert_true(strpos($adminDriving, bakery_t('nav.driving_admin_home')) !== false, 'administrator door is labeled Admin tools');

    $stopsPage = staff_mode_render_nav($db, 'manager', $ownName, 'driver_stops', $ownDriver);
    assert_true(strpos($stopsPage, 'bakery-nav--driver') !== false, 'Stops page on the own route keeps the Driving nav');

    $supervising = staff_mode_render_nav($db, 'manager', $ownName, 'driver', $otherDriver);
    assert_true(strpos($supervising, 'bakery-nav--manager') !== false, 'manager viewing another driver keeps the Manager nav');
    assert_true(strpos($supervising, 'bakery-nav--driver') === false, 'manager viewing another driver does not get the Driving nav');

    $noMatch = staff_mode_render_nav($db, 'manager', $tag . ' Nobody', 'driver', $ownDriver);
    assert_true(strpos($noMatch, 'bakery-nav--manager') !== false, 'a manager with no matching driver profile keeps the Manager nav');
    assert_true(strpos($noMatch, 'driver.php?me=1') === false, 'a manager with no matching driver profile gets no My Route shortcut');

    $managerHome = staff_mode_render_nav($db, 'manager', $ownName, 'manager', 0);
    assert_true(strpos($managerHome, 'bakery-nav--manager') !== false, 'manager home keeps the Manager nav');
    assert_true(strpos($managerHome, 'driver.php?me=1') !== false, 'manager More has a direct My Route link to their own profile');
    assert_true(strpos($managerHome, 'bakery-nav__more-link--staff') !== false, 'My Route is the highlighted first item in manager More');

    $opsHome = staff_mode_render_nav($db, 'administrator', $ownName, 'index', 0);
    assert_true(strpos($opsHome, 'driver.php?me=1') !== false, 'administrator My Route shortcut opens their own profile');

    $driverRole = staff_mode_render_nav($db, 'driver', $ownName, 'driver', $ownDriver);
    assert_true(strpos($driverRole, 'bakery-nav--driver') !== false, 'a real driver still gets the Driving nav');
    assert_true(strpos($driverRole, bakery_t('nav.driving_manager_home')) === false, 'a real driver is never shown a Manager tools door');
    assert_true(strpos($driverRole, 'admin_craft_preview') === false, 'a real driver does not get Danny’s preview switch');

    if (function_exists('bakery_baker_classic_nav_file') && is_file(bakery_baker_classic_nav_file())) {
        unlink(bakery_baker_classic_nav_file());
    }
    $juan = staff_mode_render_nav($db, 'baker', 'Juan Carlos', 'baker_day', 0);
    assert_true(strpos($juan, 'bakery-nav--craft-baker') !== false, 'Juan gets the new baker bar');
    assert_true(strpos($juan, 'baker_day.php') !== false, 'Juan’s nav opens the new baker day');
    assert_true(strpos($juan, 'admin_craft_preview') === false, 'Juan does not get an admin return switch');

    $dannyHome = staff_mode_render_nav($db, 'administrator', 'Danny', 'index', 0);
    assert_true(strpos($dannyHome, 'baker_day.php') !== false, 'Danny’s admin nav offers Baker day');
    assert_true(strpos($dannyHome, 'name="admin_craft_preview" value="driver"') !== false, 'Danny’s admin nav can open a driver phone');

    $otherAdmin = staff_mode_render_nav($db, 'administrator', 'Pat Admin', 'index', 0, [], ['admin_craft_preview' => 'driver']);
    assert_true(strpos($otherAdmin, 'bakery-nav--ops') !== false, 'another administrator keeps the admin nav');
    assert_true(strpos($otherAdmin, 'value="exit"') === false, 'another administrator cannot stay in the driver phone preview');

    $dannyDrive = staff_mode_render_nav($db, 'administrator', 'Danny', 'driver', $otherDriver, [], ['admin_craft_preview' => 'driver']);
    assert_true(strpos($dannyDrive, 'bakery-nav--driver') !== false, 'Danny previewing a driver gets the focused Driving nav');
    assert_true(strpos($dannyDrive, 'bakery-nav--ops') === false, 'Danny’s driver preview hides the admin catalog');
    $exitAt = strpos($dannyDrive, 'name="admin_craft_preview" value="exit"');
    $moreAt = strpos($dannyDrive, 'bakery-nav__more');
    assert_true($exitAt !== false && ($moreAt === false || $exitAt < $moreAt), 'Admin sits on the driver bar, not inside More');

    $dannyBaker = staff_mode_render_nav($db, 'administrator', 'Danny Gabriner', 'baker_day', 0, ['stage' => 'shape'], ['admin_craft_preview' => 'baker']);
    assert_true(strpos($dannyBaker, 'bakery-nav--craft-baker') !== false, 'Danny’s baker preview uses the baker day bar');
    assert_true(strpos($dannyBaker, 'baker_day.php') !== false, 'the baker day bar links back to the baker day');
    assert_true(strpos($dannyBaker, 'bakery-nav--ops') === false, 'the baker day bar hides the admin catalog');
    assert_true(strpos($dannyBaker, 'value="exit"') !== false, 'the baker day bar can return to Admin');
} finally {
    $in = implode(',', array_map('intval', $createdIds));
    $db->exec("DELETE FROM drivers WHERE id IN ($in)");
}

// --- driver.php wiring (source contract) ----------------------------------
$driverPhp = (string)file_get_contents($root . '/driver.php');
assert_true(strpos($driverPhp, "require_once 'includes/staff_driver_profile.php';") !== false, 'driver.php loads the staff driver profile helper');
assert_true(strpos($driverPhp, "isset(\$_GET['me'])") !== false && strpos($driverPhp, '$meRequested && $staffOwnDriverId > 0') !== false, '?me=1 selects the own profile only when one matches');
assert_true(strpos($driverPhp, '$staffOwnDriverId > 0') !== false && strpos($driverPhp, '$selectedDriverId = $staffOwnDriverId;') !== false, 'with nothing remembered, staff default to their own profile');
assert_true(strpos($driverPhp, '!$isAuthenticatedDriver && empty($staffDrivingAsSelf)') !== false, 'the top Change driver link is hidden while driving as self');
assert_true(strpos($driverPhp, "\$selectedDriverId > 0 && !empty(\$driver) && empty(\$showSelector) && empty(\$routePrepMode)) ? ' data-route-view=\"now\"'") !== false, 'the server renders the Now view so first paint has no layout flash');
$helperSrc = (string)file_get_contents($root . '/includes/staff_driver_profile.php');
assert_true(strpos($helperSrc, 'users.driver_id') !== false && strpos($helperSrc, "\$user['driver_id']") === false, 'the helper never reads or writes users.driver_id');
assert_true(strpos($helperSrc, 'UPDATE') === false && strpos($helperSrc, 'INSERT') === false, 'the helper only reads');
assert_true(strpos($helperSrc, 'function bakery_staff_login_home') !== false && strpos($helperSrc, 'driver.php?me=1') !== false, 'login home sends a driving manager to her route');
assert_true(strpos($driverPhp, "\$routePrepMode = \$routeView === 'prep' && \$routeDateKind !== 'past'") !== false, 'tomorrow opens the drive route unless prep is chosen');
assert_true(strpos($driverPhp, 'data-latest') !== false && strpos($driverPhp, '$routeForwardDate') !== false, 'the day switch stops at tomorrow and still reaches any past day');
assert_true(strpos($driverPhp, 'stop-open-delivery') !== false && strpos($driverPhp, 'data-start-step="delivery"') !== false, 'each coming store can open amounts and credits');

// --- Photo 2 and camera choice (source contract) ---------------------------
$script = (string)file_get_contents($root . '/includes/driver_delivery.js');
$route = (string)file_get_contents($root . '/includes/driver_route.js');
assert_true(strpos($driverPhp, 'id="deliverySecondPhotoBtn"') !== false, 'the charge screen has a Photo 2 button');
assert_true(strpos($driverPhp, 'id="deliveryInAppCameraBtn"') !== false, 'the capture screen can switch back to the in-app camera');
assert_true(strpos($script, 'function startSecondPhoto(') !== false, 'Photo 2 can start before Delivered');
assert_true(strpos($script, "(state.photoReturnStep === 'complete' || state.secondPhoto) ? 'After' : 'Before'") !== false, 'Photo 2 is saved as an After photo');
assert_true(strpos($script, "var CAMERA_PREF_KEY = 'bakeryDriverCameraPref';") !== false, 'the camera choice is remembered per phone');
assert_true(strpos($script, "state.currentStep === 'photo' || state.photoMode === 'review'") !== false, 'the in-app camera still starts in review mode (Add a photo after Delivered)');
assert_true(strpos($script, 'openReviewAddPhoto: openReviewAddPhoto') !== false, 'the Add photo toast can reopen the camera after Delivered');
assert_true(strpos($route, "window.DriverDelivery.openReviewAddPhoto(reviewBtn)") !== false, 'the post-Delivered toast action calls the review camera');
assert_true(strpos($script, 'uploadPromise') !== false, 'photos upload in the background and Delivered waits on the upload');

// --- i18n ----------------------------------------------------------------
$en = require $root . '/lang/en.php';
$es = require $root . '/lang/es.php';
foreach ([
    'nav.driving_manager_home', 'nav.driving_admin_home',
    'driver.second_photo', 'driver.second_photo_title', 'driver.second_photo_hint',
    'driver.second_photo_saving', 'driver.second_photo_saved', 'driver.second_photo_added',
    'driver.second_photo_toast_action', 'driver.take_second_photo', 'driver.use_in_app_camera',
] as $key) {
    assert_true(isset($en[$key]) && $en[$key] !== '', "EN has $key");
    assert_true(isset($es[$key]) && $es[$key] !== '', "ES has $key");
}

echo "\n", $GLOBALS['TEST_PASS'], " passed, ", $GLOBALS['TEST_FAIL'], " failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
