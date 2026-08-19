<?php
/**
 * Secure delivery photo serving for authenticated portal customers.
 * Does not expose filesystem paths; verifies customer ownership server-side.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_delivery.php';

$customer = bakery_portal_customer($db);
if (!$customer) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$customerId = (int)$customer['id'];
$photoId = (int)($_GET['id'] ?? 0);

if ($photoId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid photo';
    exit;
}

$photo = bakery_customer_delivery_photo_for_customer($db, $customerId, $photoId);
if (!$photo) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo not found';
    exit;
}

$filePath = dirname(__DIR__) . '/uploads/driver_photos/' . ltrim((string)$photo['file_path'], '/');
$realBase = realpath(dirname(__DIR__) . '/uploads/driver_photos');
$realFile = is_file($filePath) ? realpath($filePath) : false;

if ($realFile === false || $realBase === false || strpos($realFile, $realBase) !== 0) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Photo unavailable';
    exit;
}

$mime = (string)($photo['mime_type'] ?? '');
if ($mime === '' || !preg_match('#^image/(jpeg|jpg|png|webp)$#i', $mime)) {
    $mime = 'image/jpeg';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($realFile));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

readfile($realFile);
exit;
