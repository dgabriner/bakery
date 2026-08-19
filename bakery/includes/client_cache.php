<?php
/**
 * Keep browsers on the current deploy: never cache HTML, and fingerprint static assets.
 * Does not clear cookies or localStorage (login, GPS, and filters stay intact).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Build id that changes whenever a deployed PHP/JS/CSS file is newer.
 */
function bakery_client_build_id() {
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $root = dirname(__DIR__);
    $max = 0;
    $scan = static function ($dir) use (&$max) {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'js', 'css'], true)) {
                continue;
            }
            $mtime = @filemtime($path);
            if ($mtime !== false) {
                $max = max($max, (int)$mtime);
            }
        }
    };

    $scan($root);
    $scan($root . DIRECTORY_SEPARATOR . 'includes');
    $scan($root . DIRECTORY_SEPARATOR . 'css');

    $id = $max > 0 ? (string)$max : '1';
    return $id;
}

/**
 * Cache-busted URL for a file under the bakery root (css/x.css, includes/x.js, …).
 */
function bakery_asset_url($relativePath) {
    $relativePath = ltrim(str_replace('\\', '/', (string)$relativePath), '/');
    $root = dirname(__DIR__);
    $fsPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $version = is_file($fsPath) ? (string)filemtime($fsPath) : bakery_client_build_id();
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return $base . $relativePath . '?v=' . rawurlencode($version);
}

/** HTML-escaped asset URL for href/src attributes. */
function bakery_asset_href($relativePath) {
    return htmlspecialchars(bakery_asset_url($relativePath), ENT_QUOTES, 'UTF-8');
}

/**
 * Prevent browsers and shared caches from serving a stale HTML document
 * (old markup, CSRF tokens, and script tags) after a production update.
 */
function bakery_send_document_cache_headers() {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    header('Cache-Control: private, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}
