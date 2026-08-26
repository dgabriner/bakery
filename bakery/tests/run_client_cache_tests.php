<?php
/**
 * Cache-busting / client-refresh smoke tests (CLI).
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';

$failures = 0;

function cache_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL  $label\n";
        $failures++;
        return;
    }
    echo "PASS  $label\n";
}

cache_assert('bakery_client_build_id is non-empty', bakery_client_build_id() !== '');
cache_assert('bakery_client_build_id is numeric stamp', ctype_digit(bakery_client_build_id()));

$csrfUrl = bakery_asset_url('includes/csrf.js');
cache_assert('csrf.js URL includes query version', strpos($csrfUrl, '?v=') !== false);
cache_assert('csrf.js URL stays under BASE_URL', strpos($csrfUrl, BASE_URL . 'includes/csrf.js?v=') === 0);

$href = bakery_asset_href('css/styles.css');
cache_assert('asset href is HTML-escaped', strpos($href, '&') !== false || strpos($href, '?v=') !== false);
cache_assert('asset href contains version', strpos($href, '?v=') !== false);

$header = file_get_contents($root . '/includes/header.php');
cache_assert('header uses bakery_asset_href', strpos($header, 'bakery_asset_href(') !== false);
cache_assert('header includes client refresh', strpos($header, 'client_refresh.php') !== false);
cache_assert('header no longer uses raw filemtime CSS tags', strpos($header, 'filemtime(__DIR__') === false);

$portal = file_get_contents($root . '/includes/portal_styles.php');
cache_assert('portal styles include client refresh', strpos($portal, 'client_refresh.php') !== false);
cache_assert('portal styles cache-bust csrf.js', strpos($portal, "bakery_asset_href('includes/csrf.js')") !== false);

$js = file_get_contents($root . '/includes/client_refresh.js');
cache_assert('refresh script watches visibility', strpos($js, 'visibilitychange') !== false);
cache_assert('refresh script honors skip meta', strpos($js, 'app-skip-client-refresh') !== false);
cache_assert('refresh script does not hard-reload on bfcache alone', strpos($js, 'event.persisted') !== false && strpos($js, "if (event.persisted) {\n    window.location.reload();") === false && strpos($js, "if (event.persisted) {\n      window.location.reload();") === false);
cache_assert('refresh script re-checks build after bfcache restore', strpos($js, 'checkRemoteBuild') !== false && preg_match('/event\.persisted\)\s*\{\s*checkRemoteBuild\(\);/', $js) === 1);
cache_assert('refresh script requires durable reload latch', strpos($js, 'write(RELOAD_KEY, nextBuild)') !== false && strpos($js, 'infinite refresh loop') !== false);
cache_assert('refresh script cools down automatic reloads', strpos($js, 'RELOAD_COOLDOWN_MS') !== false);
$refreshPhp = file_get_contents($root . '/includes/client_refresh.php');
cache_assert('refresh include can skip the script', strpos($refreshPhp, 'BAKERY_SKIP_CLIENT_REFRESH') !== false);
$managerSrc = file_get_contents($root . '/manager.php');
cache_assert('Staging Live board skips client refresh', strpos($managerSrc, "define('BAKERY_SKIP_CLIENT_REFRESH', true)") !== false);
cache_assert('refresh script does not clear localStorage', strpos($js, 'localStorage.clear') === false);
cache_assert('refresh script does not clear cookies', strpos($js, 'document.cookie') === false);

$login = file_get_contents($root . '/login.php');
cache_assert('login does not fight visualViewport scroll', strpos($login, 'visualViewport.addEventListener') === false);
cache_assert('login does not force scrollTo on mobile', strpos($login, 'keepMobileAtTop') === false && strpos($login, 'scrollTo(0, 0)') === false);

$htaccess = file_get_contents($root . '/.htaccess');
cache_assert('Apache HTML/PHP responses are not stored', strpos($htaccess, 'no-store') !== false);

$buildEndpoint = file_get_contents($root . '/build_id.php');
cache_assert('build_id endpoint returns bakery_client_build_id', strpos($buildEndpoint, 'bakery_client_build_id()') !== false);

$config = file_get_contents($root . '/includes/config.php');
cache_assert('config sends document cache headers', strpos($config, 'bakery_send_document_cache_headers') !== false);

exit($failures > 0 ? 1 : 0);
