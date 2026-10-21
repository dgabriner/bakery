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
header_remove('Content-Type');
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
readfile($path);
exit;
