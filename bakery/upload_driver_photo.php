<?php
/**
 * Driver delivery photo API: upload, list, delete.
 * Auth + CSRF enforced via includes/database.php.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/photo_handler.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/client_request_id.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('POST required');
    }

    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : 'upload';
    if ($action === '' && isset($_FILES['photo'])) {
        $action = 'upload';
    }

    $db = check_mysql_connection();
    $photoHandler = new PhotoHandler();

    switch ($action) {
        case 'list':
            echo json_encode(bakery_list_driver_photos($db, $photoHandler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'delete':
            echo json_encode(bakery_delete_driver_photo($db, $photoHandler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        case 'upload':
            echo json_encode(bakery_upload_driver_photo($db, $photoHandler), JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
}

function bakery_require_photo_ids() {
    $driverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');

    if ($driverId <= 0 || $customerId <= 0) {
        throw new Exception('driver_id and customer_id are required');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    return [$driverId, $customerId, $date];
}

function bakery_list_driver_photos(PDO $db, PhotoHandler $photoHandler) {
    list($driverId, $customerId, $date) = bakery_require_photo_ids();
    bakery_assert_driver_identity($db, $driverId, $date);
    $rows = $photoHandler->getPhotos($db, $driverId, $date, $customerId);
    $photos = $photoHandler->formatPhotosForClient($rows);
    return [
        'success' => true,
        'photos' => $photos,
        'count' => count($photos),
    ];
}

function bakery_delete_driver_photo(PDO $db, PhotoHandler $photoHandler) {
    $driverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
    $photoId = isset($_POST['photo_id']) ? (int)$_POST['photo_id'] : 0;
    if ($driverId <= 0 || $photoId <= 0) {
        throw new Exception('driver_id and photo_id are required');
    }
    $photoDate = $db->prepare('SELECT delivery_date FROM driver_photos WHERE id = ? AND driver_id = ? LIMIT 1');
    $photoDate->execute([$photoId, $driverId]);
    $deliveryDate = (string)$photoDate->fetchColumn();
    if ($deliveryDate === '') {
        throw new Exception('Photo not found');
    }
    bakery_assert_driver_identity($db, $driverId, $deliveryDate);

    $result = $photoHandler->deletePhoto($db, $photoId, $driverId);
    if (empty($result['success'])) {
        throw new Exception($result['error'] ?? 'Delete failed');
    }

    return [
        'success' => true,
        'message' => 'Photo removed',
        'photo_id' => $photoId,
    ];
}

function bakery_upload_driver_photo(PDO $db, PhotoHandler $photoHandler) {
    $driverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $dailyOrderId = isset($_POST['daily_order_id']) ? (int)$_POST['daily_order_id'] : 0;
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');
    $photoType = isset($_POST['photo_type']) ? trim((string)$_POST['photo_type']) : 'After';
    $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
    $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $clientRequestId = bakery_normalize_client_request_id($_POST['client_request_id'] ?? '');

    if ($driverId <= 0 || $customerId <= 0 || $dailyOrderId <= 0) {
        throw new Exception('driver_id, customer_id, and daily_order_id are required');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }
    bakery_assert_driver_identity($db, $driverId, $date);

    $allowedTypes = ['Before', 'After', 'Receipt'];
    if (!in_array($photoType, $allowedTypes, true)) {
        $photoType = 'After';
    }

    $assignment = $photoHandler->verifyDeliveryAssignment($db, $driverId, $customerId, $dailyOrderId, $date);
    if (!$assignment) {
        throw new Exception('No delivery assignment found for this stop on the selected date');
    }

    if ($clientRequestId !== '' && bakery_driver_photos_client_request_id_ready($db)) {
        $dup = $db->prepare(
            'SELECT id, file_path, notes, created_at, photo_type
             FROM driver_photos WHERE client_request_id = ? LIMIT 1'
        );
        $dup->execute([$clientRequestId]);
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $photoId = (int)$existing['id'];
            $urls = $photoHandler->getPhotoUrlWithFallback((string)$existing['file_path']);
            $response = $photoHandler->buildUploadSuccessResponse($assignment, [
                'filename' => basename((string)$existing['file_path']),
                'file_path' => (string)$existing['file_path'],
                'photo_type' => (string)$existing['photo_type'],
                'daily_order_id' => $dailyOrderId,
                'delivery_date' => $date,
            ], $photoId);
            $response['photo']['id'] = $photoId;
            $response['photo']['url'] = $urls['primary'];
            $response['photo']['fallback_url'] = $urls['fallback'];
            $response['photo']['notes'] = (string)($existing['notes'] ?? '');
            $response['photo']['created_at'] = (string)($existing['created_at'] ?? '');
            $response['duplicate'] = true;
            $response['client_request_id'] = $clientRequestId;
            return $response;
        }
    }

    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        throw new Exception('Photo file is required');
    }

    $uploadResult = $photoHandler->processUpload(
        $_FILES['photo'],
        $driverId,
        $customerId,
        $photoType,
        $notes,
        $latitude,
        $longitude
    );

    if (empty($uploadResult['success'])) {
        throw new Exception($uploadResult['error'] ?? 'Photo upload failed');
    }

    $photoData = $uploadResult['data'];
    $photoData['delivery_date'] = $date;
    $photoData['daily_order_id'] = $dailyOrderId;
    if ($clientRequestId !== '') {
        $photoData['client_request_id'] = $clientRequestId;
    }

    try {
        $saveResult = $photoHandler->saveToDatabase($db, $driverId, $customerId, $photoData);
    } catch (Throwable $e) {
        $saveResult = ['success' => false, 'error' => $e->getMessage()];
    }
    if (empty($saveResult['success'])) {
        if ($clientRequestId !== '' && bakery_driver_photos_client_request_id_ready($db)) {
            $dup = $db->prepare('SELECT id, file_path, notes, created_at, photo_type FROM driver_photos WHERE client_request_id = ? LIMIT 1');
            $dup->execute([$clientRequestId]);
            $existing = $dup->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $photoId = (int)$existing['id'];
                $urls = $photoHandler->getPhotoUrlWithFallback((string)$existing['file_path']);
                $response = $photoHandler->buildUploadSuccessResponse($assignment, [
                    'filename' => basename((string)$existing['file_path']),
                    'file_path' => (string)$existing['file_path'],
                    'photo_type' => (string)$existing['photo_type'],
                    'daily_order_id' => $dailyOrderId,
                    'delivery_date' => $date,
                ], $photoId);
                $response['photo']['id'] = $photoId;
                $response['photo']['url'] = $urls['primary'];
                $response['photo']['fallback_url'] = $urls['fallback'];
                $response['photo']['notes'] = (string)($existing['notes'] ?? '');
                $response['photo']['created_at'] = (string)($existing['created_at'] ?? '');
                $response['duplicate'] = true;
                $response['client_request_id'] = $clientRequestId;
                return $response;
            }
        }
        throw new Exception($saveResult['error'] ?? 'Failed to save photo metadata');
    }

    $response = $photoHandler->buildUploadSuccessResponse($assignment, $photoData, $saveResult['photo_id']);
    $urls = $photoHandler->getPhotoUrlWithFallback($photoData['file_path']);
    $response['photo']['id'] = (int)$saveResult['photo_id'];
    $response['photo']['url'] = $urls['primary'];
    $response['photo']['fallback_url'] = $urls['fallback'];
    $response['photo']['notes'] = $notes;
    $response['photo']['created_at'] = date('Y-m-d H:i:s');
    if ($clientRequestId !== '') {
        $response['client_request_id'] = $clientRequestId;
    }
    return $response;
}
