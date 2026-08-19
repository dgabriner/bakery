<?php
/**
 * Manager API for product catalog photos.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/product_photo_handler.php';

header('Content-Type: application/json');

if (!table_exists($db, 'product_images')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Product images not migrated yet. Run scripts/run_migrations.php']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $handler = new ProductPhotoHandler();

    switch ($action) {
        case 'upload':
            $productId = (int)($_POST['product_id'] ?? 0);
            $setPrimary = !isset($_POST['set_primary']) || $_POST['set_primary'] !== '0';
            if (empty($_FILES['photo'])) {
                throw new InvalidArgumentException('No photo uploaded');
            }
            $result = $handler->processUpload($db, $_FILES['photo'], $productId, $setPrimary);
            echo json_encode($result);
            break;

        case 'list':
            $productId = (int)($_GET['product_id'] ?? $_POST['product_id'] ?? 0);
            echo json_encode(['success' => true, 'images' => $handler->listImages($db, $productId)]);
            break;

        case 'set_primary':
            $productId = (int)($_POST['product_id'] ?? 0);
            $imageId = (int)($_POST['image_id'] ?? 0);
            echo json_encode($handler->setPrimary($db, $productId, $imageId));
            break;

        case 'delete':
            $productId = (int)($_POST['product_id'] ?? 0);
            $imageId = (int)($_POST['image_id'] ?? 0);
            echo json_encode($handler->deleteImage($db, $productId, $imageId));
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
