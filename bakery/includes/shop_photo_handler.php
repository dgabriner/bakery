<?php
/**
 * Shop Photo Handler
 *
 * Handles upload, storage, and retrieval of cashier shop photos
 * (window display, trays, general shop shots), organized by date and cashier.
 *
 * @package BakeryManagement
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class ShopPhotoHandler {

    private $uploadDir;
    private $uploadUrl;
    private $maxSize;
    private $thumbSize;

    public static function categories(): array {
        return [
            'window_display' => 'Window Display',
            'trays'          => 'Trays',
            'general'        => 'General',
            'other'          => 'Other',
        ];
    }

    public function __construct() {
        $this->uploadDir = dirname(__FILE__) . '/../uploads/shop_photos/';
        $this->uploadUrl = BASE_URL . 'uploads/shop_photos/';
        $this->maxSize   = 10 * 1024 * 1024; // 10 MB
        $this->thumbSize = 300;
        $this->ensureDirectoriesExist();
    }

    /**
     * Validate + move an uploaded file; return structured data or error.
     */
    public function processUpload(array $file, int $cashierUserId, string $photoDate, string $category = 'general', string $caption = ''): array {
        try {
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }

            $category = in_array($category, array_keys(self::categories()), true) ? $category : 'general';

            $filename  = $this->generateFilename($file['name'], $cashierUserId, $category);
            $yearMonth = date('Y/m', strtotime($photoDate) ?: time());
            $targetDir = $this->uploadDir . $yearMonth . '/';

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create upload directory'];
            }

            $targetPath = $targetDir . $filename;

            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'error' => 'Failed to save uploaded file'];
            }

            $this->optimizeImage($targetPath, $file['type']);

            $finalSize = @filesize($targetPath) ?: $file['size'];

            return [
                'success' => true,
                'data' => [
                    'filename'          => $filename,
                    'original_filename' => $file['name'],
                    'file_path'         => $yearMonth . '/' . $filename,
                    'file_size'         => $finalSize,
                    'mime_type'         => $file['type'],
                    'photo_category'    => $category,
                    'caption'           => $caption,
                ],
            ];
        } catch (Exception $e) {
            error_log('ShopPhotoHandler upload error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Upload failed: ' . $e->getMessage()];
        }
    }

    /**
     * Persist photo metadata to the shop_photos table.
     */
    public function saveToDatabase(PDO $db, int $cashierUserId, string $photoDate, array $photoData): array {
        try {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $photoDate)) {
                $photoDate = date('Y-m-d');
            }
            $stmt = $db->prepare("
                INSERT INTO shop_photos
                  (cashier_user_id, photo_date, photo_category, caption,
                   filename, original_filename, file_path, file_size, mime_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cashierUserId,
                $photoDate,
                $photoData['photo_category'],
                $photoData['caption'],
                $photoData['filename'],
                $photoData['original_filename'],
                $photoData['file_path'],
                $photoData['file_size'],
                $photoData['mime_type'],
            ]);
            return ['success' => true, 'photo_id' => (int)$db->lastInsertId()];
        } catch (Exception $e) {
            error_log('ShopPhotoHandler DB save error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch photos for a given date, optionally filtered by cashier or category.
     */
    public function getPhotos(PDO $db, string $date, ?int $cashierUserId = null, ?string $category = null): array {
        try {
            $where  = ['sp.photo_date = ?'];
            $params = [$date];

            if ($cashierUserId !== null) {
                $where[]  = 'sp.cashier_user_id = ?';
                $params[] = $cashierUserId;
            }
            if ($category !== null && in_array($category, array_keys(self::categories()), true)) {
                $where[]  = 'sp.photo_category = ?';
                $params[] = $category;
            }

            $sql = "
                SELECT sp.*, u.name AS cashier_name
                FROM shop_photos sp
                JOIN users u ON sp.cashier_user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY sp.photo_category, sp.created_at ASC
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('ShopPhotoHandler getPhotos error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Return a list of distinct dates that have shop photos, newest first.
     */
    public function getPhotoDates(PDO $db, int $limit = 30): array {
        try {
            $stmt = $db->prepare("
                SELECT DISTINCT photo_date
                FROM shop_photos
                ORDER BY photo_date DESC
                LIMIT ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'photo_date');
        } catch (Exception $e) {
            error_log('ShopPhotoHandler getPhotoDates error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete a single photo (ownership check: must belong to cashierUserId unless admin).
     */
    public function deletePhoto(PDO $db, int $photoId, int $cashierUserId, bool $isAdmin = false): array {
        try {
            if ($photoId <= 0) {
                return ['success' => false, 'error' => 'Invalid photo ID'];
            }
            $stmt = $db->prepare('SELECT id, file_path, cashier_user_id FROM shop_photos WHERE id = ? LIMIT 1');
            $stmt->execute([$photoId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['success' => false, 'error' => 'Photo not found'];
            }
            if (!$isAdmin && (int)$row['cashier_user_id'] !== $cashierUserId) {
                return ['success' => false, 'error' => 'Permission denied'];
            }

            $db->prepare('DELETE FROM shop_photos WHERE id = ?')->execute([$photoId]);

            $diskPath = $this->uploadDir . ($row['file_path'] ?? '');
            if (is_string($diskPath) && $diskPath !== $this->uploadDir && is_file($diskPath)) {
                @unlink($diskPath);
            }
            return ['success' => true];
        } catch (Exception $e) {
            error_log('ShopPhotoHandler deletePhoto error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to delete photo'];
        }
    }

    /**
     * Build display-ready URL for a stored file path.
     */
    public function getPhotoUrl(string $filePath): string {
        if (function_exists('isDevelopment') && isDevelopment()) {
            return $this->uploadUrl . $filePath;
        }
        return 'https://bakery.sourflour.org/uploads/shop_photos/' . $filePath;
    }

    /**
     * Format a rows array for JSON clients.
     */
    public function formatForClient(array $rows): array {
        $categories = self::categories();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'             => (int)$row['id'],
                'photo_date'     => $row['photo_date'],
                'photo_category' => $row['photo_category'],
                'category_label' => $categories[$row['photo_category']] ?? $row['photo_category'],
                'caption'        => $row['caption'] ?? '',
                'cashier_name'   => $row['cashier_name'] ?? '',
                'created_at'     => $row['created_at'] ?? '',
                'url'            => $this->getPhotoUrl($row['file_path']),
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateFile(array $file): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msgs = [
                UPLOAD_ERR_INI_SIZE   => 'File too large (server limit)',
                UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                UPLOAD_ERR_PARTIAL    => 'Upload incomplete',
                UPLOAD_ERR_NO_FILE    => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error (no temp dir)',
                UPLOAD_ERR_CANT_WRITE => 'Server error (cannot write)',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by server',
            ];
            return ['valid' => false, 'error' => $msgs[$file['error']] ?? 'Upload error'];
        }

        if ($file['size'] > $this->maxSize) {
            return ['valid' => false, 'error' => 'File exceeds 10 MB limit'];
        }

        $mimeType = $file['type'];
        if (function_exists('finfo_open')) {
            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            if ($detected && $detected !== 'application/octet-stream') {
                $mimeType = $detected;
            }
        }

        $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];
        if (!in_array($mimeType, $allowed, true)) {
            return ['valid' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, WebP, GIF, HEIC'];
        }

        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false && !in_array($mimeType, ['image/heic', 'image/heif'], true)) {
            return ['valid' => false, 'error' => 'File is not a valid image'];
        }

        return ['valid' => true];
    }

    private function generateFilename(string $originalName, int $cashierUserId, string $category): string {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !preg_match('/^[a-z0-9]+$/', $ext)) {
            $ext = 'jpg';
        }
        $safeCategory = preg_replace('/[^A-Za-z0-9_-]/', '', $category) ?: 'photo';
        $timestamp    = date('Ymd_His');
        $unique       = substr(md5(uniqid(rand(), true)), 0, 8);
        return "cashier{$cashierUserId}_{$safeCategory}_{$timestamp}_{$unique}.{$ext}";
    }

    private function optimizeImage(string $imagePath, string $mimeType): void {
        try {
            $imageInfo = @getimagesize($imagePath);
            if (!$imageInfo) {
                return;
            }
            [$width, $height, $type] = $imageInfo;
            $maxDim = 1920;
            if ($width <= $maxDim && $height <= $maxDim && filesize($imagePath) <= 2 * 1024 * 1024) {
                return;
            }
            if ($width > $height) {
                $newW = min($width, $maxDim);
                $newH = (int)round($height * $newW / $width);
            } else {
                $newH = min($height, $maxDim);
                $newW = (int)round($width * $newH / $height);
            }
            if ($newW >= $width && $newH >= $height) {
                return;
            }
            $src = null;
            switch ($type) {
                case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($imagePath); break;
                case IMAGETYPE_PNG:  $src = @imagecreatefrompng($imagePath);  break;
                case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($imagePath); break;
                default: return;
            }
            if (!$src) {
                return;
            }
            $dst = imagecreatetruecolor($newW, $newH);
            if ($type === IMAGETYPE_PNG) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
                imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
            switch ($type) {
                case IMAGETYPE_JPEG: imagejpeg($dst, $imagePath, 85); break;
                case IMAGETYPE_PNG:  imagepng($dst, $imagePath, 6);   break;
                case IMAGETYPE_WEBP: imagewebp($dst, $imagePath, 85); break;
            }
            imagedestroy($src);
            imagedestroy($dst);
        } catch (Exception $e) {
            error_log('ShopPhotoHandler optimize error: ' . $e->getMessage());
        }
    }

    private function ensureDirectoriesExist(): void {
        foreach ([$this->uploadDir, $this->uploadDir . date('Y/'), $this->uploadDir . date('Y/m/')] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }
}
