<?php
/**
 * Photo Handler for Driver Delivery Photos
 * 
 * Handles photo uploads, thumbnail generation, and file management
 * for driver delivery documentation photos.
 * 
 * @package BakeryManagement
 * @version 1.0
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

class PhotoHandler {
    
    private $uploadDir;
    private $uploadUrl;
    private $maxSize;
    private $allowedTypes;
    private $thumbSize;
    
    public function __construct() {
        $this->uploadDir = dirname(__FILE__) . '/../uploads/driver_photos/';
        $this->uploadUrl = BASE_URL . 'uploads/driver_photos/';
        $this->maxSize = 10 * 1024 * 1024; // 10MB
        $this->allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
        $this->thumbSize = 300; // pixels
        
        // Create directories if they don't exist
        $this->ensureDirectoriesExist();
    }
    
    /**
     * Process uploaded photo file
     * 
     * @param array $file $_FILES array element
     * @param int $driverId Driver ID
     * @param int $customerId Customer ID  
     * @param string $photoType Photo category (Before, After, Receipt)
     * @param string $notes Optional notes
     * @param float $latitude Optional GPS latitude
     * @param float $longitude Optional GPS longitude
     * @return array Result with success status and data
     */
    public function processUpload($file, $driverId, $customerId, $photoType = 'Before', $notes = '', $latitude = null, $longitude = null) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                return ['success' => false, 'error' => $validation['error']];
            }
            
            // Generate unique filename
            $filename = $this->generateFilename($file['name'], $driverId, $customerId, $photoType);
            
            // Create year/month directory structure
            $yearMonth = date('Y/m');
            $targetDir = $this->uploadDir . $yearMonth . '/';
            
            if (!is_dir($targetDir)) {
                if (!@mkdir($targetDir, 0755, true)) {
                    return ['success' => false, 'error' => 'Failed to create upload directory'];
                }
            }
            
            $targetPath = $targetDir . $filename;
            
            // Move uploaded file
            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'error' => 'Failed to save uploaded file'];
            }
            
            // Optimize main image
            $this->optimizeImage($targetPath, $file['type']);
            
            // Get final file size
            $finalSize = @filesize($targetPath);
            if ($finalSize === false) {
                $finalSize = $file['size']; // Fallback to original size
            }
            
            return [
                'success' => true,
                'data' => [
                    'filename' => $filename,
                    'original_filename' => $file['name'],
                    'file_path' => $yearMonth . '/' . $filename,
                    'thumbnail_path' => null, // No longer generating thumbnails
                    'file_size' => $finalSize,
                    'mime_type' => $file['type'],
                    'photo_type' => $photoType,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'notes' => $notes
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Photo upload error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Photo upload failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Validate uploaded file
     * 
     * @param array $file $_FILES array element
     * @return array Validation result
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File too large (exceeds server limit)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)',
                UPLOAD_ERR_PARTIAL => 'File upload incomplete',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error (no temp directory)',
                UPLOAD_ERR_CANT_WRITE => 'Server configuration error (cannot write file)',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension'
            ];
            
            $errorMsg = $errors[$file['error']] ?? 'Unknown upload error';
            error_log("Photo upload error: " . $errorMsg . " (Error code: " . $file['error'] . ")");
            
            return [
                'valid' => false, 
                'error' => $errorMsg
            ];
        }
        
        // Check file size
        if ($file['size'] > $this->maxSize) {
            error_log("Photo upload error: File too large. Size: " . $file['size'] . ", Max: " . $this->maxSize);
            return [
                'valid' => false,
                'error' => 'File too large. Maximum size: ' . $this->formatFileSize($this->maxSize)
            ];
        }
        
        // Enhanced MIME type detection for mobile uploads
        $detectedMimeType = null;
        
        // Try multiple methods to detect MIME type
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            error_log("Photo upload debug - Detected MIME type (finfo): " . $detectedMimeType);
        }
        
        // Fallback to browser-provided MIME type if finfo fails
        if (!$detectedMimeType || $detectedMimeType === 'application/octet-stream') {
            $detectedMimeType = $file['type'];
            error_log("Photo upload debug - Using browser MIME type: " . $detectedMimeType);
        }
        
        // Extended allowed types for mobile compatibility
        $allowedTypes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif',
            'image/heic', 'image/heif'  // iOS formats
        ];
        
        if (!in_array($detectedMimeType, $allowedTypes)) {
            error_log("Photo upload error: Invalid MIME type: " . $detectedMimeType);
            return [
                'valid' => false,
                'error' => 'Invalid file type. Detected: ' . $detectedMimeType . '. Allowed: JPEG, PNG, WebP, GIF, HEIC'
            ];
        }
        
        // Additional security check - verify it's actually an image
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            error_log("Photo upload error: File is not a valid image. getimagesize failed.");
            
            // For HEIC/HEIF files, getimagesize might fail but file could still be valid
            if (in_array($detectedMimeType, ['image/heic', 'image/heif'])) {
                error_log("Photo upload debug: HEIC/HEIF file detected, allowing despite getimagesize failure");
                return ['valid' => true];
            }
            
            return [
                'valid' => false,
                'error' => 'File is not a valid image or is corrupted'
            ];
        }
        
        error_log("Photo upload debug: Validation passed. Image info: " . print_r($imageInfo, true));
        return ['valid' => true];
    }
    
    /**
     * Generate unique filename
     * 
     * @param string $originalName Original filename
     * @param int $driverId Driver ID
     * @param int $customerId Customer ID
     * @param string $photoType Photo type
     * @return string Generated filename
     */
    private function generateFilename($originalName, $driverId, $customerId, $photoType) {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $timestamp = date('Ymd_His');
        $unique = substr(md5(uniqid(rand(), true)), 0, 8);
        
        return "driver{$driverId}_customer{$customerId}_{$photoType}_{$timestamp}_{$unique}.{$extension}";
    }
    
    /**
     * Optimize main image for storage
     * 
     * @param string $imagePath Image file path
     * @param string $mimeType MIME type
     */
    private function optimizeImage($imagePath, $mimeType) {
        try {
            $imageInfo = getimagesize($imagePath);
            if (!$imageInfo) return;
            
            list($width, $height, $type) = $imageInfo;
            
            // Skip optimization if image is already small enough
            if ($width <= 1920 && $height <= 1920 && filesize($imagePath) <= 2 * 1024 * 1024) {
                return;
            }
            
            // Calculate new dimensions (max 1920px on longest side)
            $maxDimension = 1920;
            if ($width > $height) {
                $newWidth = min($width, $maxDimension);
                $newHeight = intval($height * $newWidth / $width);
            } else {
                $newHeight = min($height, $maxDimension);
                $newWidth = intval($width * $newHeight / $height);
            }
            
            // Only resize if necessary
            if ($newWidth < $width || $newHeight < $height) {
                $this->resizeImage($imagePath, $newWidth, $newHeight, $type);
            }
            
        } catch (Exception $e) {
            error_log("Image optimization error: " . $e->getMessage());
        }
    }
    
    /**
     * Resize image in place
     * 
     * @param string $imagePath Image file path
     * @param int $newWidth New width
     * @param int $newHeight New height
     * @param int $imageType Image type constant
     */
    private function resizeImage($imagePath, $newWidth, $newHeight, $imageType) {
        // Create source image
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($imagePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($imagePath);
                break;
            case IMAGETYPE_WEBP:
                $source = imagecreatefromwebp($imagePath);
                break;
            default:
                return;
        }
        
        if (!$source) return;
        
        list($width, $height) = getimagesize($imagePath);
        
        // Create new image
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        // Resize
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        
        // Save over original
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                imagejpeg($resized, $imagePath, 85);
                break;
            case IMAGETYPE_PNG:
                imagepng($resized, $imagePath, 6);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($resized, $imagePath, 85);
                break;
        }
        
        // Clean up
        imagedestroy($source);
        imagedestroy($resized);
    }
    
    /**
     * Get photo URL for display
     * 
     * @param string $filePath File path from database
     * @param bool $thumbnail Whether to get thumbnail version (ignored - always returns main image)
     * @return string Full URL to photo
     */
    public function getPhotoUrl($filePath, $thumbnail = false) {
        // Always return main image - CSS will handle sizing
        if (isDevelopment()) {
            return $this->uploadUrl . $filePath;
        }
        
        // In production, use production URLs
        $productionBaseUrl = 'https://bakery.sourflour.org/uploads/driver_photos/';
        return $productionBaseUrl . $filePath;
    }
    
    /**
     * Get photo URL with fallback handling for dual environments
     * 
     * @param string $filePath File path from database
     * @param bool $thumbnail Whether to get thumbnail version (ignored - always returns main image)
     * @return array Array with primary URL and fallback URL
     */
    public function getPhotoUrlWithFallback($filePath, $thumbnail = false) {
        // Always use main image - ignore thumbnail parameter
        $localUrl = BASE_URL . 'uploads/driver_photos/' . $filePath;
        $productionUrl = 'https://bakery.sourflour.org/uploads/driver_photos/' . $filePath;
        
        if (isDevelopment()) {
            return [
                'primary' => $localUrl,
                'fallback' => $productionUrl
            ];
        } else {
            return [
                'primary' => $productionUrl,
                'fallback' => $localUrl
            ];
        }
    }
    
    /**
     * Save photo metadata to database
     * 
     * @param PDO $db Database connection
     * @param int $driverId Driver ID
     * @param int $customerId Customer ID
     * @param array $photoData Photo data from processUpload
     * @return array Result with success status and photo ID
     */
    public function saveToDatabase($db, $driverId, $customerId, $photoData) {
        try {
            $stmt = $db->prepare("
                INSERT INTO driver_photos 
                (driver_id, customer_id, delivery_date, filename, original_filename, file_path, thumbnail_path, 
                 file_size, mime_type, photo_type, latitude, longitude, notes) 
                VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                $driverId,
                $customerId,
                $photoData['filename'],
                $photoData['original_filename'],
                $photoData['file_path'],
                $photoData['thumbnail_path'],
                $photoData['file_size'],
                $photoData['mime_type'],
                $photoData['photo_type'],
                $photoData['latitude'],
                $photoData['longitude'],
                $photoData['notes']
            ]);
            
            if ($result) {
                return ['success' => true, 'photo_id' => $db->lastInsertId()];
            } else {
                return ['success' => false, 'error' => 'Failed to save photo to database'];
            }
            
        } catch (Exception $e) {
            error_log("Database save error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Confirm driver/customer/daily_order assignment for a delivery date.
     *
     * @return array|null Assignment row with customer_name and route_order, or null
     */
    public function verifyDeliveryAssignment(PDO $db, $driverId, $customerId, $dailyOrderId, $date) {
        try {
            $stmt = $db->prepare("
                SELECT doa.id AS assignment_id,
                       c.name AS customer_name,
                       doa.route_order,
                       doa.delivery_status
                FROM daily_order_assignments doa
                INNER JOIN daily_orders do ON do.id = doa.daily_order_id
                INNER JOIN customers c ON do.customer_id = c.id
                WHERE doa.driver_id = ?
                  AND do.customer_id = ?
                  AND do.id = ?
                  AND do.order_date = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$driverId, (int)$customerId, (int)$dailyOrderId, $date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Exception $e) {
            error_log('Assignment verification error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Standard JSON payload after a successful upload (UX confirmation).
     */
    public function buildUploadSuccessResponse(array $assignment, array $photoData, $photoId) {
        return [
            'success' => true,
            'photo_id' => (int)$photoId,
            'message' => sprintf(
                'Photo saved for %s (stop #%s)',
                $assignment['customer_name'],
                $assignment['route_order']
            ),
            'assignment' => [
                'customer_name' => $assignment['customer_name'],
                'route_order' => (int)$assignment['route_order'],
                'daily_order_id' => (int)($photoData['daily_order_id'] ?? 0),
            ],
            'photo' => [
                'photo_type' => $photoData['photo_type'],
                'file_path' => $photoData['file_path'],
            ],
        ];
    }

    /**
     * Get photos for a specific driver and date
     * 
     * @param PDO $db Database connection
     * @param int $driverId Driver ID
     * @param string $date Date (Y-m-d format)
     * @param int $customerId Optional customer filter
     * @return array Photos data
     */
    public function getPhotos($db, $driverId, $date, $customerId = null) {
        try {
            $query = "
                SELECT p.*, c.name as customer_name, c.address as customer_address
                FROM driver_photos p
                JOIN customers c ON p.customer_id = c.id
                WHERE p.driver_id = ? AND p.delivery_date = ?
            ";
            
            $params = [$driverId, $date];
            
            if ($customerId) {
                $query .= " AND p.customer_id = ?";
                $params[] = $customerId;
            }
            
            $query .= " ORDER BY p.created_at DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get photos error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format file size for human reading
     * 
     * @param int $bytes File size in bytes
     * @return string Formatted size
     */
    private function formatFileSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen($bytes) - 1) / 3);
        return sprintf("%.1f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
    }
    
    /**
     * Ensure required directories exist
     */
    private function ensureDirectoriesExist() {
        $dirs = [
            $this->uploadDir,
            $this->uploadDir . date('Y/'),
            $this->uploadDir . date('Y/m/')
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
} 