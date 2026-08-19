<?php
/**
 * SF Baker batch photo uploads (portal customers, per-phase).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class SfbPhotoHandler {
    private $uploadDir;
    private $uploadUrl;
    private $maxSize;
    private $allowedTypes;
    private $allowedPhases = ['starter', 'mix', 'development', 'shape', 'bake', 'final'];

    public function __construct() {
        $this->uploadDir = dirname(__FILE__) . '/../uploads/sfb_photos/';
        $this->uploadUrl = BASE_URL . 'uploads/sfb_photos/';
        $this->maxSize = 10 * 1024 * 1024;
        $this->allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
        $this->ensureDirectoriesExist();
    }

    public function processUpload(PDO $db, $file, $batchId, $customerId, $phase = 'final', $caption = null) {
        $batchId = (int)$batchId;
        $customerId = (int)$customerId;
        if ($batchId <= 0 || $customerId <= 0) {
            return ['success' => false, 'error' => 'Invalid batch'];
        }
        if (!in_array($phase, $this->allowedPhases, true)) {
            $phase = 'final';
        }

        $owns = $db->prepare('SELECT 1 FROM sfb_batches WHERE id = ? AND customer_id = ? LIMIT 1');
        $owns->execute([$batchId, $customerId]);
        if (!$owns->fetchColumn()) {
            return ['success' => false, 'error' => 'Batch not found'];
        }

        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'error' => $validation['error']];
        }

        $filename = $this->generateFilename($file['name'], $batchId);
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
            $ins = $db->prepare(
                'INSERT INTO sfb_batch_photos (batch_id, phase, filename, file_path, caption, file_size, mime_type)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $batchId,
                $phase,
                $filename,
                $relativePath,
                ($caption !== null && trim((string)$caption) !== '') ? trim((string)$caption) : null,
                $finalSize,
                $file['type'],
            ]);

            return [
                'success' => true,
                'data' => [
                    'id' => (int)$db->lastInsertId(),
                    'filename' => $filename,
                    'file_path' => $relativePath,
                    'url' => $this->uploadUrl . $relativePath,
                    'phase' => $phase,
                ],
            ];
        } catch (Throwable $e) {
            @unlink($targetPath);
            error_log('SFB photo DB error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save photo record'];
        }
    }

    public function deletePhoto(PDO $db, $batchId, $customerId, $photoId) {
        $stmt = $db->prepare(
            'SELECT p.id, p.file_path
             FROM sfb_batch_photos p
             JOIN sfb_batches b ON b.id = p.batch_id
             WHERE p.id = ? AND p.batch_id = ? AND b.customer_id = ? LIMIT 1'
        );
        $stmt->execute([(int)$photoId, (int)$batchId, (int)$customerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['success' => false, 'error' => 'Photo not found'];
        }

        $fullPath = $this->uploadDir . ltrim($row['file_path'], '/');
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        $db->prepare('DELETE FROM sfb_batch_photos WHERE id = ?')->execute([(int)$photoId]);

        return ['success' => true];
    }

    private function ensureDirectoriesExist() {
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }
    }

    private function validateFile($file) {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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

    private function generateFilename($originalName, $batchId) {
        $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !preg_match('/^[a-z0-9]+$/', $extension)) {
            $extension = 'jpg';
        }
        $timestamp = date('Ymd_His');
        $unique = substr(md5(uniqid((string)rand(), true)), 0, 8);
        return "batch{$batchId}_{$timestamp}_{$unique}.{$extension}";
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
