<?php
/**
 * Login-gated delivery photo. Files stay in uploads/driver_photos.
 * Direct web access to that folder is denied. This script is the reader.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/photo_handler.php';

$user = function_exists('bakery_current_user') ? bakery_current_user() : null;
$id = (int)($_GET['id'] ?? 0);
$file = (string)($_GET['file'] ?? '');
$result = bakery_driver_photo_authorize($db, $user, $id, $file);
$status = (int)($result['status'] ?? 403);

if ($status !== 200 || !is_file((string)($result['path'] ?? ''))) {
    http_response_code($status === 200 ? 404 : $status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $status === 403 ? "Forbidden\n" : "Not found\n";
    exit;
}

$path = (string)$result['path'];
$mime = (string)($result['mime'] ?? 'image/jpeg');

/**
 * 300 px JPEG beside the original, made once on first request. The gallery
 * grid loads this; the full file is only fetched by the viewer.
 */
function bakery_driver_photo_thumb_path(string $path): string
{
    $thumbDir = dirname($path) . DIRECTORY_SEPARATOR . 'thumbs';
    $thumbPath = $thumbDir . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME) . '.jpg';
    if (is_file($thumbPath) && filemtime($thumbPath) >= filemtime($path)) {
        return $thumbPath;
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return '';
    }
    $info = @getimagesize($path);
    $width = is_array($info) ? (int)$info[0] : 0;
    $height = is_array($info) ? (int)$info[1] : 0;
    if ($width < 1 || $height < 1 || ($width * $height) > 6000000) {
        return '';
    }
    $source = @imagecreatefromstring((string)file_get_contents($path));
    if (!$source) {
        return '';
    }
    $scale = min(1, 300 / max($width, $height));
    $thumbWidth = max(1, (int)round($width * $scale));
    $thumbHeight = max(1, (int)round($height * $scale));
    $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
    imagedestroy($source);
    if (!is_dir($thumbDir) && !@mkdir($thumbDir, 0755, true) && !is_dir($thumbDir)) {
        imagedestroy($thumb);
        return '';
    }
    $saved = @imagejpeg($thumb, $thumbPath, 70);
    imagedestroy($thumb);
    return $saved ? $thumbPath : '';
}

if ((string)($_GET['size'] ?? '') === 'thumb') {
    $thumbPath = bakery_driver_photo_thumb_path($path);
    if ($thumbPath !== '') {
        $path = $thumbPath;
        $mime = 'image/jpeg';
    }
}
header_remove('Content-Type');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
exit;
