<?php
/**
 * Learning-center media viewer — streams lesson photos/videos stored under
 * storage/sfb_media/. Files never sit on a public path; this endpoint is the
 * single gate: signed-in portal baker or staff, path guard, nosniff.
 * GET sfb_media.php?f=YYYY/MM/name.ext
 *
 * text_media.php is the house precedent; range requests keep phone video
 * playback working.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/sf_baker.php';

$isStaff = bakery_user_has_role(['administrator', 'manager']);
$mediaCustomerId = 0;
if (!$isStaff) {
    // Portal customers with SF Baker access may watch; everyone else bounces.
    require_once __DIR__ . '/includes/customer_portal.php';
    try {
        $mediaCustomer = bakery_sfb_require_access($db);
        $mediaCustomerId = (int)$mediaCustomer['id'];
    } catch (Throwable $e) {
        header('Location: ' . BASE_URL . 'customer_login.php');
        exit;
    }
}

$relative = (string)($_GET['f'] ?? '');
if ($relative === '' || !bakery_sfb_media_path_safe($relative)) {
    http_response_code(404);
    exit('Not found');
}

$root = dirname(__DIR__);
$mediaBase = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sfb_media';
$absolute = $mediaBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
$realBase = realpath($mediaBase);
$realFile = realpath($absolute);
if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('Not found');
}

// Paid-class media answers 404 to non-entitled bakers (migration 068).
if ($mediaCustomerId > 0 && bakery_sfb_media_path_locked($db, $relative, $mediaCustomerId)) {
    http_response_code(404);
    exit('Not found');
}

$contentType = bakery_sfb_media_content_type($relative);
$fileSize = (int)filesize($realFile);

header_remove('Content-Type');
header('Content-Type: ' . $contentType);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($realFile) . '"');

$start = 0;
$end = $fileSize - 1;
$range = (string)($_SERVER['HTTP_RANGE'] ?? '');
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = (int)$m[2];
    }
    if ($end >= $fileSize) {
        $end = $fileSize - 1;
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
} else {
    http_response_code(200);
}

header('Content-Length: ' . (string)($end - $start + 1));

$handle = fopen($realFile, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Server error');
}
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(64 * 1024, $remaining));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $remaining -= strlen($chunk);
}
fclose($handle);
exit;
