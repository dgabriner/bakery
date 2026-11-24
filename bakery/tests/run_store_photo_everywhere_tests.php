<?php
/**
 * Store photos from every screen — DB + UI contract suite.
 *
 * Verifies that Laura (manager who also drives) and other staff can take and
 * save store photos from any screen in Sour Flour OS — even at a store with no
 * order today or assigned to another driver — while preserving route security.
 *
 * Usage: php tests/run_store_photo_everywhere_tests.php
 * Database: bakerysf_test only.
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
require_once $root . '/includes/photo_handler.php';
require_once $root . '/includes/driver_assignments.php';
require_once $root . '/includes/client_request_id.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

// Load upload_driver_photo.php helper functions without running its top-level POST switch.
$uploadSrc = (string)file_get_contents($root . '/upload_driver_photo.php');
$fnPos = strpos($uploadSrc, 'function bakery_store_photo_driver_exists');
assert_true($fnPos !== false, 'upload_driver_photo.php defines bakery_store_photo_driver_exists');
if ($fnPos !== false) {
    eval(substr($uploadSrc, $fnPos));
}

function store_photo_render_nav(PDO $db, string $role, string $displayName, string $page, int $selectedDriverId = 0): string
{
    $_SESSION = [
        'user_id' => 991,
        'user_role_slug' => $role,
        'user_display_name' => $displayName,
        'admin_workspace_mode' => 'full',
    ];
    if ($selectedDriverId > 0) {
        $_SESSION['selected_driver_id'] = $selectedDriverId;
    }
    $_GET = [];
    $_SERVER['PHP_SELF'] = '/' . $page . '.php';
    return (static function () use ($db) {
        ob_start();
        include dirname(__DIR__) . '/includes/nav.php';
        return (string)ob_get_clean();
    })();
}

$tag = 'Sp' . substr(md5((string)microtime(true)), 0, 6);
$lauraName = $tag . ' Laura';
$marcosName = $tag . ' Marcos';
$storeUnscheduled = $tag . ' Unscheduled Market';
$storeMarcos = $tag . ' Marcos Cafe';
$testDate = '2099-11-24';

$createdDrivers = [];
$createdCustomers = [];
$createdOrders = [];
$createdAssignments = [];
$createdPhotos = [];
$tempFiles = [];

$insDriver = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
foreach ([$lauraName, $marcosName] as $dName) {
    $insDriver->execute([$dName]);
    $createdDrivers[$dName] = (int)$db->lastInsertId();
}

$insCustomer = $db->prepare('INSERT INTO customers (name, address, is_active) VALUES (?, ?, 1)');
$insCustomer->execute([$storeUnscheduled, '100 Valencia St']);
$createdCustomers['unscheduled'] = (int)$db->lastInsertId();
$insCustomer->execute([$storeMarcos, '200 24th St']);
$createdCustomers['marcos'] = (int)$db->lastInsertId();

$insOrder = $db->prepare('INSERT INTO daily_orders (customer_id, order_date) VALUES (?, ?)');
$insOrder->execute([$createdCustomers['marcos'], $testDate]);
$marcosOrderId = (int)$db->lastInsertId();
$createdOrders[] = $marcosOrderId;

$insAssign = $db->prepare('INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, delivery_status) VALUES (?, ?, ?, 1, ?)');
$insAssign->execute([$marcosOrderId, $createdDrivers[$marcosName], $testDate, 'pending']);
$createdAssignments[] = (int)$db->lastInsertId();

try {
    $lauraDriverId = $createdDrivers[$lauraName];
    $marcosDriverId = $createdDrivers[$marcosName];

    // 1. Universal Photo button renders across every store-facing screen & role.
    foreach ([
        ['manager', $lauraName, 'manager', 0],
        ['manager', $lauraName, 'driver', $lauraDriverId],
        ['manager', $lauraName, 'customer_record', 0],
        ['driver', $marcosName, 'driver', $marcosDriverId],
        ['administrator', 'Danny', 'index', 0],
        ['sales', 'Sales Rep', 'sales_home', 0],
        ['cashier', 'Cashier', 'counter_orders', 0],
    ] as [$role, $name, $page, $drvId]) {
        $html = store_photo_render_nav($db, $role, $name, $page, $drvId);
        assert_true(strpos($html, 'id="sfQuickPhotoFab"') !== false, "$role on $page renders #sfQuickPhotoFab");
        assert_true(strpos($html, 'id="sfQuickPhotoModal"') !== false, "$role on $page renders #sfQuickPhotoModal");
    }

    // Baker screen stays uncluttered without wholesale store camera FAB.
    $bakerHtml = store_photo_render_nav($db, 'baker', 'Juan Carlos', 'baker_day', 0);
    assert_true(strpos($bakerHtml, 'id="sfQuickPhotoFab"') === false, 'baker nav stays focused without store photo FAB');

    // 2. Laura (manager with matching driver profile) resolves her own driver_id even with driver_id=0 and no order today.
    $_SESSION = [
        'user_id' => 991,
        'user_role_slug' => 'manager',
        'user_display_name' => $lauraName,
    ];
    $resolvedForUnscheduled = bakery_resolve_store_photo_driver_id($db, 0, $createdCustomers['unscheduled'], 0, $testDate);
    assert_eq($lauraDriverId, $resolvedForUnscheduled, 'Laura on manager screen resolves to her own driver profile');

    // Create a tiny real JPEG via GD to exercise PhotoHandler + saveToDatabase end-to-end.
    $tmpJpg = tempnam(sys_get_temp_dir(), 'sfp_') . '.jpg';
    $img = imagecreatetruecolor(64, 48);
    $green = imagecolorallocate($img, 21, 108, 67);
    imagefill($img, 0, 0, $green);
    imagejpeg($img, $tmpJpg, 85);
    imagedestroy($img);
    $tempFiles[] = $tmpJpg;

    $photoHandler = new class extends PhotoHandler {
        public function processUpload($file, $driverId, $customerId, $photoType = 'Before', $notes = '', $latitude = null, $longitude = null)
        {
            $filename = sprintf('driver%d_customer%d_%s_%d_%s.jpg', (int)$driverId, (int)$customerId, $photoType, time(), substr(uniqid(), -6));
            $subDir = date('Y/m') . '/';
            $targetDir = dirname(__DIR__) . '/uploads/driver_photos/' . $subDir;
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $dest = $targetDir . $filename;
            copy($file['tmp_name'], $dest);
            return [
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'original_filename' => $file['name'] ?? 'store.jpg',
                    'file_path' => 'uploads/driver_photos/' . $subDir . $filename,
                    'thumbnail_path' => null,
                    'file_size' => (int)filesize($dest),
                    'mime_type' => 'image/jpeg',
                    'photo_type' => $photoType,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'notes' => $notes,
                ],
            ];
        }
    };

    // 3. Upload at a store with NO order/assignment today (store_visit=1, driver_id=0, daily_order_id=0).
    $_POST = [
        'action' => 'upload',
        'store_visit' => '1',
        'driver_id' => '0',
        'customer_id' => (string)$createdCustomers['unscheduled'],
        'daily_order_id' => '0',
        'date' => $testDate,
        'photo_type' => 'Before',
        'notes' => 'Shelf check at unscheduled store',
        'client_request_id' => 'test-sqp-' . uniqid(),
    ];
    $_FILES = [
        'photo' => [
            'name' => 'store-visit.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $tmpJpg,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpJpg),
        ],
    ];
    // PhotoHandler::processUpload uses move_uploaded_file for HTTP SAPI; copy via PhotoHandler test-safe upload check or verify saveToDatabase + filename credit:
    $uploadAttempt = bakery_upload_driver_photo($db, $photoHandler);
    assert_true(!empty($uploadAttempt['success']), 'store visit upload succeeds with no daily_order_assignments row');
    $savedPhotoId1 = (int)($uploadAttempt['photo_id'] ?? 0);
    assert_true($savedPhotoId1 > 0, 'store visit upload inserts a driver_photos row');
    if ($savedPhotoId1 > 0) {
        $createdPhotos[] = $savedPhotoId1;
        $row1 = $db->query('SELECT * FROM driver_photos WHERE id = ' . $savedPhotoId1)->fetch(PDO::FETCH_ASSOC);
        assert_eq($lauraDriverId, (int)$row1['driver_id'], 'unscheduled store photo records Laura driver_id');
        assert_eq($lauraDriverId, bakery_driver_photo_credited_driver_id($row1), 'filename credits Laura as the photographer');
        if (!empty($row1['file_path'])) {
            $rel = bakery_driver_photo_relative_path((string)$row1['file_path']);
            if ($rel !== '') {
                $tempFiles[] = $root . '/uploads/driver_photos/' . $rel;
            }
        }
        if (!empty($row1['thumbnail_path'])) {
            $relThumb = bakery_driver_photo_relative_path((string)$row1['thumbnail_path']);
            if ($relThumb !== '') {
                $tempFiles[] = $root . '/uploads/driver_photos/' . $relThumb;
            }
        }
    }

    // 4. Upload by Laura at Marcos's assigned stop — succeeds, credits Laura, AND Marcos's stop sees the photo!
    $tmpJpg2 = tempnam(sys_get_temp_dir(), 'sfp2_') . '.jpg';
    copy($tmpJpg, $tmpJpg2);
    $tempFiles[] = $tmpJpg2;

    $_POST = [
        'action' => 'upload',
        'store_visit' => '1',
        'driver_id' => '0',
        'customer_id' => (string)$createdCustomers['marcos'],
        'daily_order_id' => '0',
        'date' => $testDate,
        'photo_type' => 'Before',
        'notes' => 'Laura checked Marcos cafe display',
        'client_request_id' => 'test-sqp2-' . uniqid(),
    ];
    $_FILES['photo']['tmp_name'] = $tmpJpg2;
    $uploadMarcosStop = bakery_upload_driver_photo($db, $photoHandler);
    assert_true(!empty($uploadMarcosStop['success']), 'Laura can save a photo at another driver\'s stop');
    $savedPhotoId2 = (int)($uploadMarcosStop['photo_id'] ?? 0);
    if ($savedPhotoId2 > 0) {
        $createdPhotos[] = $savedPhotoId2;
        $row2 = $db->query('SELECT * FROM driver_photos WHERE id = ' . $savedPhotoId2)->fetch(PDO::FETCH_ASSOC);
        assert_eq($lauraDriverId, bakery_driver_photo_credited_driver_id($row2), 'photo at Marcos stop still credits Laura');
        $marcosViewPhotos = $photoHandler->getPhotos($db, $marcosDriverId, $testDate, $createdCustomers['marcos']);
        assert_true(count($marcosViewPhotos) >= 1, 'Marcos stop gallery sees the photo Laura took for that store and date');
        if (!empty($row2['file_path'])) {
            $rel2 = bakery_driver_photo_relative_path((string)$row2['file_path']);
            if ($rel2 !== '') {
                $tempFiles[] = $root . '/uploads/driver_photos/' . $rel2;
            }
        }
        if (!empty($row2['thumbnail_path'])) {
            $relT2 = bakery_driver_photo_relative_path((string)$row2['thumbnail_path']);
            if ($relT2 !== '') {
                $tempFiles[] = $root . '/uploads/driver_photos/' . $relT2;
            }
        }
    }

    // 5. Regular driver cannot spoof another driver_id.
    $_SESSION = [
        'user_id' => 992,
        'user_role_slug' => 'driver',
        'user_display_name' => $marcosName,
        'selected_driver_id' => $marcosDriverId,
    ];
    $spoofBlocked = false;
    try {
        bakery_resolve_store_photo_driver_id($db, $lauraDriverId, $createdCustomers['marcos'], $marcosOrderId, $testDate);
    } catch (Throwable $spoofErr) {
        $spoofBlocked = true;
    }
    assert_true($spoofBlocked, 'a driver role cannot upload under another driver_id');
} finally {
    if ($createdPhotos !== []) {
        $ph = implode(',', array_map('intval', $createdPhotos));
        $db->exec("DELETE FROM driver_photos WHERE id IN ($ph)");
    }
    foreach ($tempFiles as $f) {
        if (is_string($f) && $f !== '' && is_file($f)) {
            @unlink($f);
        }
    }
    if ($createdAssignments !== []) {
        $ph = implode(',', array_map('intval', $createdAssignments));
        $db->exec("DELETE FROM daily_order_assignments WHERE id IN ($ph)");
    }
    if ($createdOrders !== []) {
        $ph = implode(',', array_map('intval', $createdOrders));
        $db->exec("DELETE FROM daily_orders WHERE id IN ($ph)");
    }
    if ($createdCustomers !== []) {
        $ph = implode(',', array_map('intval', array_values($createdCustomers)));
        $db->exec("DELETE FROM customers WHERE id IN ($ph)");
    }
    if ($createdDrivers !== []) {
        $ph = implode(',', array_map('intval', array_values($createdDrivers)));
        $db->exec("DELETE FROM drivers WHERE id IN ($ph)");
    }
}

echo "\n", $GLOBALS['TEST_PASS'], " passed, ", $GLOBALS['TEST_FAIL'], " failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
