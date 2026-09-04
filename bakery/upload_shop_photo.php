<?php
/**
 * Shop photo API: upload, list, delete.
 * Accessible to cashier, manager, and administrator roles.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/shop_photo_handler.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    bakery_require_role(['cashier', 'manager', 'administrator']);

    $db      = check_mysql_connection();
    $handler = new ShopPhotoHandler();
    $action  = isset($_POST['action']) ? trim((string)$_POST['action']) : 'upload';
    if ($action === '' && isset($_FILES['photo'])) {
        $action = 'upload';
    }

    switch ($action) {
        case 'upload':
            echo json_encode(bakery_shop_photo_upload($db, $handler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'list':
            echo json_encode(bakery_shop_photo_list($db, $handler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'delete':
            echo json_encode(bakery_shop_photo_delete($db, $handler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
}

// ---------------------------------------------------------------------------

function bakery_shop_photo_upload(PDO $db, ShopPhotoHandler $handler): array {
    $user     = bakery_current_user();
    $userId   = (int)($user['id'] ?? 0);
    $date     = isset($_POST['photo_date']) ? trim((string)$_POST['photo_date']) : date('Y-m-d');
    $category = isset($_POST['photo_category']) ? trim((string)$_POST['photo_category']) : 'general';
    $caption  = isset($_POST['caption']) ? trim((string)$_POST['caption']) : '';

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        throw new Exception('Photo file is required');
    }

    $uploadResult = $handler->processUpload($_FILES['photo'], $userId, $date, $category, $caption);
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error'] ?? 'Upload failed');
    }

    $saveResult = $handler->saveToDatabase($db, $userId, $date, $uploadResult['data']);
    if (!$saveResult['success']) {
        throw new Exception($saveResult['error'] ?? 'Failed to save photo record');
    }

    return [
        'success'  => true,
        'photo_id' => $saveResult['photo_id'],
        'message'  => 'Photo saved',
        'photo'    => [
            'photo_category' => $uploadResult['data']['photo_category'],
            'file_path'      => $uploadResult['data']['file_path'],
            'url'            => $handler->getPhotoUrl($uploadResult['data']['file_path']),
        ],
    ];
}

function bakery_shop_photo_list(PDO $db, ShopPhotoHandler $handler): array {
    $date     = isset($_POST['photo_date']) ? trim((string)$_POST['photo_date']) : date('Y-m-d');
    $category = (isset($_POST['photo_category']) && $_POST['photo_category'] !== '')
        ? trim((string)$_POST['photo_category'])
        : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    $user   = bakery_current_user();
    $role   = $user['role_slug'] ?? '';
    $userId = (int)($user['id'] ?? 0);

    // Cashiers see their own photos; managers and admins see all
    $filterUserId = in_array($role, ['manager', 'administrator'], true) ? null : $userId;

    $rows   = $handler->getPhotos($db, $date, $filterUserId, $category);
    $photos = $handler->formatForClient($rows);
    return [
        'success' => true,
        'photos'  => $photos,
        'count'   => count($photos),
        'date'    => $date,
    ];
}

function bakery_shop_photo_delete(PDO $db, ShopPhotoHandler $handler): array {
    $photoId = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
    if ($photoId <= 0) {
        throw new Exception('photo_id is required');
    }
    $user    = bakery_current_user();
    $userId  = (int)($user['id'] ?? 0);
    $isAdmin = in_array($user['role_slug'] ?? '', ['administrator', 'manager'], true);

    $result = $handler->deletePhoto($db, $photoId, $userId, $isAdmin);
    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Delete failed');
    }
    return ['success' => true, 'message' => 'Photo removed', 'photo_id' => $photoId];
}
