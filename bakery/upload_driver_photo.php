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
require_once __DIR__ . '/includes/staff_driver_profile.php';
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
} catch (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
}

function bakery_store_photo_driver_exists(PDO $db, int $driverId): bool {
    if ($driverId <= 0) {
        return false;
    }
    $stmt = $db->prepare('SELECT id FROM drivers WHERE id = ? LIMIT 1');
    $stmt->execute([$driverId]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_resolve_store_photo_driver_id(PDO $db, int $requestedDriverId, int $customerId, int $dailyOrderId, string $date): int {
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $role = (string)($user['role_slug'] ?? '');
    $isStaffSupervisor = in_array($role, ['administrator', 'manager', 'sales'], true);

    if ($requestedDriverId > 0) {
        if ($isStaffSupervisor) {
            if (bakery_store_photo_driver_exists($db, $requestedDriverId)) {
                return $requestedDriverId;
            }
        } else {
            bakery_assert_driver_identity($db, $requestedDriverId, $date);
            return $requestedDriverId;
        }
    }

    if (function_exists('bakery_staff_own_driver_id')) {
        $ownDriverId = bakery_staff_own_driver_id($db, $user);
        if ($ownDriverId > 0 && bakery_store_photo_driver_exists($db, $ownDriverId)) {
            return $ownDriverId;
        }
    }

    if (function_exists('bakery_is_driver_route_role') && bakery_is_driver_route_role($role)) {
        $workerDriverId = bakery_route_worker_driver_id($db, $user, $date);
        if ($workerDriverId > 0) {
            bakery_assert_driver_identity($db, $workerDriverId, $date);
            return $workerDriverId;
        }
    }

    if (!$isStaffSupervisor) {
        throw new Exception('driver_id is required');
    }

    if (function_exists('bakery_get_selected_driver_id')) {
        $selectedDriverId = (int)bakery_get_selected_driver_id();
        if ($selectedDriverId > 0 && bakery_store_photo_driver_exists($db, $selectedDriverId)) {
            return $selectedDriverId;
        }
    }

    if ($dailyOrderId > 0) {
        $stmt = $db->prepare('SELECT driver_id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$dailyOrderId, $date]);
        $assignedId = (int)$stmt->fetchColumn();
        if ($assignedId > 0 && bakery_store_photo_driver_exists($db, $assignedId)) {
            return $assignedId;
        }
    }

    if ($customerId > 0) {
        $stmt = $db->prepare(
            'SELECT doa.driver_id
             FROM daily_order_assignments doa
             INNER JOIN daily_orders do ON do.id = doa.daily_order_id
             WHERE do.customer_id = ? AND do.order_date = ? AND doa.delivery_date = ?
             ORDER BY doa.id DESC LIMIT 1'
        );
        $stmt->execute([$customerId, $date, $date]);
        $assignedId = (int)$stmt->fetchColumn();
        if ($assignedId > 0 && bakery_store_photo_driver_exists($db, $assignedId)) {
            return $assignedId;
        }

        if (function_exists('table_exists') && table_exists($db, 'standing_routes')) {
            $srStmt = $db->prepare('SELECT driver_id FROM standing_routes WHERE customer_id = ? AND driver_id IS NOT NULL ORDER BY id DESC LIMIT 1');
            $srStmt->execute([$customerId]);
            $srDriverId = (int)$srStmt->fetchColumn();
            if ($srDriverId > 0 && bakery_store_photo_driver_exists($db, $srDriverId)) {
                return $srDriverId;
            }
        }
    }

    $hasArchived = function_exists('column_exists') && column_exists($db, 'drivers', 'archived');
    $fallbackSql = $hasArchived
        ? 'SELECT id FROM drivers WHERE COALESCE(archived, 0) = 0 ORDER BY id ASC LIMIT 1'
        : 'SELECT id FROM drivers ORDER BY id ASC LIMIT 1';
    $fallbackId = (int)$db->query($fallbackSql)->fetchColumn();
    if ($fallbackId <= 0) {
        $fallbackId = (int)$db->query('SELECT id FROM drivers ORDER BY id ASC LIMIT 1')->fetchColumn();
    }
    if ($fallbackId <= 0) {
        throw new Exception('No driver profile available to record store photo');
    }
    return $fallbackId;
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
    $driverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');
    $allDrivers = !empty($_POST['all_drivers']);
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $role = (string)($user['role_slug'] ?? '');
    $canViewAllStorePhotos = in_array($role, ['administrator', 'manager', 'sales'], true);

    if ($customerId <= 0) {
        throw new Exception('customer_id is required');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    if (($allDrivers || $driverId <= 0) && $canViewAllStorePhotos) {
        $stmt = $db->prepare(
            'SELECT p.*, c.name AS customer_name, c.address AS customer_address
             FROM driver_photos p
             INNER JOIN customers c ON p.customer_id = c.id
             WHERE p.customer_id = ? AND p.delivery_date = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$customerId, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $photos = $photoHandler->formatPhotosForClient($rows);
        return [
            'success' => true,
            'photos' => $photos,
            'count' => count($photos),
        ];
    }

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
    if ($photoId <= 0) {
        throw new Exception('photo_id is required');
    }
    $isSupervisor = function_exists('bakery_user_has_role') && bakery_user_has_role(['administrator', 'manager']);
    if ($driverId <= 0 && $isSupervisor) {
        $ownerStmt = $db->prepare('SELECT driver_id, delivery_date FROM driver_photos WHERE id = ? LIMIT 1');
        $ownerStmt->execute([$photoId]);
        $ownerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ownerRow) {
            throw new Exception('Photo not found');
        }
        $driverId = (int)$ownerRow['driver_id'];
        $deliveryDate = (string)$ownerRow['delivery_date'];
    } else {
        if ($driverId <= 0) {
            throw new Exception('driver_id and photo_id are required');
        }
        $photoDate = $db->prepare('SELECT delivery_date FROM driver_photos WHERE id = ? AND driver_id = ? LIMIT 1');
        $photoDate->execute([$photoId, $driverId]);
        $deliveryDate = (string)$photoDate->fetchColumn();
        if ($deliveryDate === '') {
            if ($isSupervisor) {
                $anyStmt = $db->prepare('SELECT driver_id, delivery_date FROM driver_photos WHERE id = ? LIMIT 1');
                $anyStmt->execute([$photoId]);
                $anyRow = $anyStmt->fetch(PDO::FETCH_ASSOC);
                if ($anyRow) {
                    $driverId = (int)$anyRow['driver_id'];
                    $deliveryDate = (string)$anyRow['delivery_date'];
                }
            }
            if ($deliveryDate === '') {
                throw new Exception('Photo not found');
            }
        }
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
    $requestedDriverId = isset($_POST['driver_id']) ? (int)$_POST['driver_id'] : 0;
    $customerId = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0;
    $dailyOrderId = isset($_POST['daily_order_id']) ? (int)$_POST['daily_order_id'] : 0;
    $storeVisit = !empty($_POST['store_visit']) && (string)$_POST['store_visit'] !== '0';
    $date = isset($_POST['date']) ? trim((string)$_POST['date']) : date('Y-m-d');
    $photoType = isset($_POST['photo_type']) ? trim((string)$_POST['photo_type']) : 'After';
    $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : '';
    $latitude = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $clientRequestId = bakery_normalize_client_request_id($_POST['client_request_id'] ?? '');

    if ($customerId <= 0) {
        throw new Exception('customer_id is required');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception('Invalid date format');
    }

    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $role = (string)($user['role_slug'] ?? '');
    $isStaffSupervisor = in_array($role, ['administrator', 'manager', 'sales'], true);

    if (!$storeVisit && !$isStaffSupervisor && ($requestedDriverId <= 0 || $dailyOrderId <= 0)) {
        throw new Exception('driver_id, customer_id, and daily_order_id are required');
    }

    $driverId = bakery_resolve_store_photo_driver_id($db, $requestedDriverId, $customerId, $dailyOrderId, $date);
    if (!$isStaffSupervisor) {
        bakery_assert_driver_identity($db, $driverId, $date);
    }

    $allowedTypes = ['Before', 'After', 'Receipt'];
    if (!in_array($photoType, $allowedTypes, true)) {
        $photoType = 'After';
    }

    $assignment = null;
    if ($dailyOrderId > 0) {
        $assignment = $photoHandler->verifyDeliveryAssignment($db, $driverId, $customerId, $dailyOrderId, $date);
    }

    if (!$assignment) {
        if (!$storeVisit && !$isStaffSupervisor) {
            throw new Exception('No delivery assignment found for this stop on the selected date');
        }
        $custStmt = $db->prepare('SELECT id, name FROM customers WHERE id = ? LIMIT 1');
        $custStmt->execute([$customerId]);
        $customerRow = $custStmt->fetch(PDO::FETCH_ASSOC);
        if (!$customerRow) {
            throw new Exception('Store not found');
        }

        $stopStmt = $db->prepare(
            'SELECT do.id AS daily_order_id, doa.id AS assignment_id, doa.route_order, doa.delivery_status
             FROM daily_orders do
             LEFT JOIN daily_order_assignments doa
               ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
             WHERE do.customer_id = ? AND do.order_date = ?
             ORDER BY doa.id DESC, do.id DESC
             LIMIT 1'
        );
        $stopStmt->execute([$customerId, $date]);
        $stopRow = $stopStmt->fetch(PDO::FETCH_ASSOC);
        if ($stopRow && $dailyOrderId <= 0) {
            $dailyOrderId = (int)($stopRow['daily_order_id'] ?? 0);
        }
        $assignment = [
            'assignment_id' => $stopRow ? (int)($stopRow['assignment_id'] ?? 0) : 0,
            'customer_name' => (string)$customerRow['name'],
            'route_order' => $stopRow['route_order'] ?? null,
            'delivery_status' => (string)($stopRow['delivery_status'] ?? 'store_visit'),
        ];
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
