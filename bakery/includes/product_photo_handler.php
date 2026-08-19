<?php
/**
 * Product catalog photo uploads for managers.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class ProductPhotoHandler {
    private $uploadDir;
    private $uploadUrl;
    private $maxSize;
    private $allowedTypes;

    public function __construct() {
        $this->uploadDir = dirname(__FILE__) . '/../uploads/product_photos/';
        $this->uploadUrl = BASE_URL . 'uploads/product_photos/';
        $this->maxSize = 10 * 1024 * 1024;
        $this->allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
        $this->ensureDirectoriesExist();
    }

    public function processUpload(PDO $db, $file, $productId, $setPrimary = true) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            return ['success' => false, 'error' => 'Invalid product'];
        }

        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $filename = $this->generateFilename($file['name'], $productId);
        $yearMonth = date('Y/m');
        $targetDir = $this->uploadDir . $yearMonth . '/';

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }

        $targetPath = $targetDir . $filename;
        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file'];
        }

        $this->optimizeImage($targetPath, $file['type']);
        $finalSize = @filesize($targetPath);
        if ($finalSize === false) {
            $finalSize = $file['size'];
        }

        $relativePath = $yearMonth . '/' . $filename;

        try {
            $db->beginTransaction();

            if ($setPrimary) {
                $clear = $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?');
                $clear->execute([$productId]);
            }

            $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM product_images WHERE product_id = ?');
            $sortStmt->execute([$productId]);
            $sortOrder = (int)$sortStmt->fetchColumn();

            $isPrimary = $setPrimary ? 1 : 0;
            if (!$setPrimary) {
                $hasPrimary = $db->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1');
                $hasPrimary->execute([$productId]);
                if (!$hasPrimary->fetchColumn()) {
                    $isPrimary = 1;
                }
            }

            $ins = $db->prepare(
                'INSERT INTO product_images (product_id, filename, file_path, is_primary, sort_order, file_size, mime_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $productId,
                $filename,
                $relativePath,
                $isPrimary,
                $sortOrder,
                $finalSize,
                $file['type'],
            ]);

            $imageId = (int)$db->lastInsertId();
            $db->commit();

            return [
                'success' => true,
                'data' => [
                    'id' => $imageId,
                    'filename' => $filename,
                    'file_path' => $relativePath,
                    'url' => $this->uploadUrl . $relativePath,
                    'is_primary' => (bool)$isPrimary,
                ],
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            @unlink($targetPath);
            error_log('Product photo DB error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save photo record'];
        }
    }

    public function setPrimary(PDO $db, $productId, $imageId) {
        $productId = (int)$productId;
        $imageId = (int)$imageId;
        $check = $db->prepare('SELECT id FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
        $check->execute([$imageId, $productId]);
        if (!$check->fetchColumn()) {
            return ['success' => false, 'error' => 'Image not found'];
        }

        $db->beginTransaction();
        $db->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        $db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$imageId]);
        $db->commit();

        return ['success' => true];
    }

    public function deleteImage(PDO $db, $productId, $imageId) {
        $productId = (int)$productId;
        $imageId = (int)$imageId;
        $stmt = $db->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ? LIMIT 1');
        $stmt->execute([$imageId, $productId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'error' => 'Image not found'];
        }

        $fullPath = $this->uploadDir . ltrim($row['file_path'], '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $wasPrimary = (int)$row['is_primary'] === 1;
        $db->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);

        if ($wasPrimary) {
            $next = $db->prepare(
                'SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
            );
            $next->execute([$productId]);
            $nextId = $next->fetchColumn();
            if ($nextId) {
                $db->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([(int)$nextId]);
            }
        }

        return ['success' => true];
    }

    public function listImages(PDO $db, $productId) {
        $stmt = $db->prepare(
            'SELECT id, filename, file_path, is_primary, sort_order, created_at
             FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order, id'
        );
        $stmt->execute([(int)$productId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['url'] = $this->uploadUrl . ltrim($row['file_path'], '/');
            $row['is_primary'] = (int)$row['is_primary'] === 1;
        }
        return $rows;
    }

    private function ensureDirectoriesExist() {
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    private function validateFile($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => 'Upload failed'];
        }
        if ($file['size'] > $this->maxSize) {
            return ['valid' => false, 'error' => 'File too large (max 10 MB)'];
        }

        $detectedMimeType = $file['type'];
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($detected && $detected !== 'application/octet-stream') {
                $detectedMimeType = $detected;
            }
        }

        if (!in_array($detectedMimeType, $this->allowedTypes, true)) {
            return ['valid' => false, 'error' => 'Invalid file type'];
        }

        return ['valid' => true];
    }

    private function generateFilename($originalName, $productId) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !preg_match('/^[a-z0-9]+$/', $extension)) {
            $extension = 'jpg';
        }
        $timestamp = date('Ymd_His');
        $unique = substr(md5(uniqid((string)rand(), true)), 0, 8);
        return "product{$productId}_{$timestamp}_{$unique}.{$extension}";
    }

    private function optimizeImage($imagePath, $mimeType) {
        $imageInfo = @getimagesize($imagePath);
        if (!$imageInfo) {
            return;
        }

        list($width, $height, $type) = $imageInfo;
        $maxDimension = 1920;
        if ($width <= $maxDimension && $height <= $maxDimension) {
            return;
        }

        if ($width > $height) {
            $newWidth = min($width, $maxDimension);
            $newHeight = (int)($height * $newWidth / $width);
        } else {
            $newHeight = min($height, $maxDimension);
            $newWidth = (int)($width * $newHeight / $height);
        }

        $this->resizeImage($imagePath, $newWidth, $newHeight, $type);
    }

    private function resizeImage($imagePath, $newWidth, $newHeight, $imageType) {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_WEBP:
                $source = @imagecreatefromwebp($imagePath);
                break;
            default:
                return;
        }
        if (!$source) {
            return;
        }

        list($width, $height) = getimagesize($imagePath);
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($imageType === IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        switch ($imageType) {
            case IMAGETYPE_JPEG:
                imagejpeg($resized, $imagePath, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($resized, $imagePath, 8);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resized, $imagePath, 85);
                break;
        }

        imagedestroy($source);
        imagedestroy($resized);
    }
}
