<?php
/**
 * Text media viewer — streams MMS images stored under storage/text_media/.
 *
 * Staff-only (administrator/manager). Media files never sit on a public path;
 * this endpoint is the single gate: role check, ledger lookup, path guard.
 * GET text_media.php?id=<text_messages id>&i=<media index>
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/text_comms.php';
require_once __DIR__ . '/includes/text_comms_media.php';

bakery_require_role(['administrator', 'manager']);

$id = (int)($_GET['id'] ?? 0);
$index = (int)($_GET['i'] ?? 0);

if ($id <= 0 || !bakery_text_messages_ready($db)) {
    http_response_code(404);
    exit('Not found');
}

$stmt = $db->prepare('SELECT id, media_json FROM text_messages WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Not found');
}

$media = bakery_text_media_decode((string)($row['media_json'] ?? ''));
if (!isset($media[$index])) {
    http_response_code(404);
    exit('Not found');
}

$descriptor = $media[$index];
$relative = (string)($descriptor['path'] ?? '');
$contentType = (string)($descriptor['content_type'] ?? 'application/octet-stream');

// Only allow whitelisted content types to render inline; everything else downloads.
$inlineTypes = [
    'image/jpeg' => true,
    'image/jpg' => true,
    'image/png' => true,
    'image/gif' => true,
    'image/webp' => true,
    'application/pdf' => false,
];

if (!bakery_text_media_path_safe($relative)) {
    error_log("text media viewer: unsafe path requested for row $id");
    http_response_code(400);
    exit('Bad request');
}

$root = dirname(__DIR__);
$absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
$realBase = realpath($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'text_media');
$realFile = realpath($absolute);
if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0 || !is_file($realFile)) {
    http_response_code(404);
    exit('Not found');
}

header_remove('Content-Type');
header('Content-Type: ' . $contentType);
header('Content-Length: ' . (string)filesize($realFile));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header(
    'Content-Disposition: '
    . ((isset($inlineTypes[$contentType]) && $inlineTypes[$contentType]) ? 'inline' : 'attachment')
    . '; filename="' . basename($realFile) . '"'
);

readfile($realFile);
exit;
