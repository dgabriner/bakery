<?php
/**
 * Sour Flour Google tag (GT-5MGVGM88) on bakery/staging hosts.
 * Filesystem + function checks; no database.
 * Usage: php tests/run_google_analytics_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

$root = dirname(__DIR__);
$failures = 0;

function ga_assert($label, $condition) {
    global $failures;
    if (!$condition) {
        echo "FAIL  $label\n";
        $failures++;
        return;
    }
    echo "PASS  $label\n";
}

$includePath = $root . '/includes/google_analytics.php';
ga_assert('shared google_analytics include exists', is_file($includePath));
if (!is_file($includePath)) {
    echo "FAIL  cannot continue without includes/google_analytics.php\n";
    exit(1);
}

if (!getenv('DB_HOST')) {
    putenv('APP_ENV=local');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_NAME=bakerysf_test');
    putenv('DB_USER=x');
    putenv('DB_PASS=x');
    $_ENV['APP_ENV'] = 'local';
    $_ENV['DB_HOST'] = '127.0.0.1';
    $_ENV['DB_NAME'] = 'bakerysf_test';
    $_ENV['DB_USER'] = 'x';
    $_ENV['DB_PASS'] = 'x';
}
require_once $root . '/includes/config.php';
require_once $includePath;

$configSrc = (string)file_get_contents($root . '/includes/config.php');
$stagingHost = '';
if (preg_match("/function bakery_is_staging_host\\(\\)\\s*\\{\\s*return bakery_request_host\\(\\) === '([^']+)'/", $configSrc, $stagingMatch)) {
    $stagingHost = $stagingMatch[1];
}

$src = (string)file_get_contents($includePath);
ga_assert('tag id is GT-5MGVGM88', bakery_google_analytics_tag_id() === 'GT-5MGVGM88');
ga_assert('gtag.js loads GT-5MGVGM88', strpos($src, 'googletagmanager.com/gtag/js?id=') !== false);
ga_assert('gtag config uses GT-5MGVGM88', strpos($src, "gtag('config'") !== false && strpos($src, 'GT-5MGVGM88') !== false);
ga_assert('does not emit Universal Analytics UA-2133452-10', strpos($src, 'UA-2133452-10') === false);
ga_assert('does not load legacy ga.js', strpos($src, 'ga.js') === false && strpos($src, 'google-analytics.com/ga.js') === false);
ga_assert('does not invent a GTM- container', strpos($src, 'GTM-') === false);
ga_assert('does not gtag-config the GA4 destination G-FEZ1KFZKPK', strpos($src, "gtag('config', 'G-FEZ1KFZKPK')") === false);
ga_assert('does not gtag-config the Ads destination AW-987675312', strpos($src, "gtag('config', 'AW-987675312')") === false);

ga_assert('loads on bakery.sourflour.org', bakery_google_analytics_should_load('bakery.sourflour.org') === true);
ga_assert('loads on www.bakery.sourflour.org', bakery_google_analytics_should_load('www.bakery.sourflour.org') === true);
ga_assert('config defines bakery_is_staging_host host', $stagingHost !== '');
ga_assert('loads on staging host', $stagingHost !== '' && bakery_google_analytics_should_load($stagingHost) === true);
ga_assert('loads on bakery host with port', bakery_google_analytics_should_load('bakery.sourflour.org:443') === true);
ga_assert('skips localhost', bakery_google_analytics_should_load('localhost') === false);
ga_assert('skips localhost with port', bakery_google_analytics_should_load('localhost:8080') === false);
ga_assert('skips 127.0.0.1', bakery_google_analytics_should_load('127.0.0.1') === false);
ga_assert('skips ::1', bakery_google_analytics_should_load('::1') === false);
ga_assert('skips bracketed ::1', bakery_google_analytics_should_load('[::1]') === false);
ga_assert('skips gourmetgastronomer.com', bakery_google_analytics_should_load('gourmetgastronomer.com') === false);
ga_assert('skips empty host', bakery_google_analytics_should_load('') === false);

unset($GLOBALS['BAKERY_GOOGLE_ANALYTICS_RENDERED']);
ob_start();
bakery_google_analytics_render('localhost');
$localOut = ob_get_clean();
ga_assert('renders nothing on localhost', trim($localOut) === '');

unset($GLOBALS['BAKERY_GOOGLE_ANALYTICS_RENDERED']);
ob_start();
bakery_google_analytics_render('bakery.sourflour.org');
$liveOut = ob_get_clean();
ga_assert('renders gtag.js on bakery host', strpos($liveOut, 'https://www.googletagmanager.com/gtag/js?id=GT-5MGVGM88') !== false);
ga_assert('renders gtag config GT-5MGVGM88', strpos($liveOut, "gtag('config', 'GT-5MGVGM88')") !== false);
ga_assert('rendered snippet has no UA-2133452-10', strpos($liveOut, 'UA-2133452-10') === false);

ob_start();
bakery_google_analytics_render('bakery.sourflour.org');
$dupOut = ob_get_clean();
ga_assert('second render in the same request is empty', trim($dupOut) === '');

unset($GLOBALS['BAKERY_GOOGLE_ANALYTICS_RENDERED']);
ob_start();
bakery_google_analytics_render($stagingHost !== '' ? $stagingHost : 'bakery.sourflour.org');
$stageOut = ob_get_clean();
ga_assert('renders gtag.js on staging host', $stagingHost !== '' && strpos($stageOut, 'GT-5MGVGM88') !== false);

$wrappers = [
    'login.php' => (string)file_get_contents($root . '/login.php'),
    'includes/header.php' => (string)file_get_contents($root . '/includes/header.php'),
    'includes/portal_styles.php' => (string)file_get_contents($root . '/includes/portal_styles.php'),
    'customer_login.php' => (string)file_get_contents($root . '/customer_login.php'),
    'customer_qr_login.php' => (string)file_get_contents($root . '/customer_qr_login.php'),
    'guias.php' => (string)file_get_contents($root . '/guias.php'),
    'sfb_join.php' => (string)file_get_contents($root . '/sfb_join.php'),
    'starter.php' => (string)file_get_contents($root . '/starter.php'),
    'survey.php' => (string)file_get_contents($root . '/survey.php'),
];
foreach ($wrappers as $name => $body) {
    ga_assert("$name requires google_analytics.php", strpos($body, 'google_analytics.php') !== false);
}

$config = (string)file_get_contents($root . '/includes/config.php');
ga_assert('config still sends Content-Security-Policy header', strpos($config, 'Content-Security-Policy:') !== false);
ga_assert('CSP is not disabled or emptied', strpos($config, "default-src 'self'") !== false);

preg_match('/script-src[^;]+;/', $config, $scriptSrc);
preg_match('/connect-src[^;]+;/', $config, $connectSrc);
preg_match('/img-src[^;]+;/', $config, $imgSrc);
preg_match('/frame-src[^;]+;/', $config, $frameSrc);
$script = $scriptSrc[0] ?? '';
$connect = $connectSrc[0] ?? '';
$img = $imgSrc[0] ?? '';
$frame = $frameSrc[0] ?? '';
ga_assert('script-src allows googletagmanager.com', strpos($script, 'googletagmanager.com') !== false);
ga_assert('script-src allows google-analytics.com', strpos($script, 'google-analytics.com') !== false);
ga_assert('script-src allows googleadservices.com', strpos($script, 'googleadservices.com') !== false);
ga_assert('connect-src allows googletagmanager.com', strpos($connect, 'googletagmanager.com') !== false);
ga_assert('connect-src allows google-analytics.com', strpos($connect, 'google-analytics.com') !== false);
ga_assert('img-src allows googletagmanager.com', strpos($img, 'googletagmanager.com') !== false);
ga_assert('img-src allows google-analytics.com', strpos($img, 'google-analytics.com') !== false);
ga_assert('frame-src allows googletagmanager.com', strpos($frame, 'googletagmanager.com') !== false);
ga_assert('script-src still allows Maps', strpos($script, 'maps.googleapis.com') !== false);

$htaccess = (string)file_get_contents($root . '/.htaccess');
ga_assert('.htaccess does not set a blocking CSP', stripos($htaccess, 'Content-Security-Policy') === false);

$header = $wrappers['includes/header.php'];
ga_assert('header meta CSP stays upgrade-insecure-requests only', strpos($header, 'upgrade-insecure-requests') !== false);

$eduJsPath = $root . '/breadeducation/js/gtag.js';
ga_assert('breadeducation shared gtag.js exists', is_file($eduJsPath));
$eduJs = is_file($eduJsPath) ? (string)file_get_contents($eduJsPath) : '';
ga_assert('breadeducation gtag.js configures GT-5MGVGM88', strpos($eduJs, 'GT-5MGVGM88') !== false);
ga_assert('breadeducation gtag.js loads googletagmanager gtag', strpos($eduJs, 'googletagmanager.com/gtag/js?id=GT-5MGVGM88') !== false);
ga_assert('breadeducation gtag.js does not emit UA-2133452-10', strpos($eduJs, 'UA-2133452-10') === false);
ga_assert('breadeducation gtag.js does not invent a GTM- container', strpos($eduJs, 'GTM-') === false);
ga_assert('breadeducation gtag.js does not gtag-config G-FEZ1KFZKPK', strpos($eduJs, "gtag('config', 'G-FEZ1KFZKPK')") === false);
ga_assert('breadeducation gtag.js skips localhost', strpos($eduJs, 'localhost') !== false);
ga_assert('breadeducation gtag.js skips 127.0.0.1', strpos($eduJs, '127.0.0.1') !== false);
ga_assert('breadeducation gtag.js allowlists bakery.sourflour.org', strpos($eduJs, "'bakery.sourflour.org'") !== false);

$eduHtaccess = (string)file_get_contents($root . '/breadeducation/.htaccess');
ga_assert('breadeducation .htaccess does not set CSP', stripos($eduHtaccess, 'Content-Security-Policy') === false);

$eduSnippet = 'src="/breadeducation/js/gtag.js"';
$eduHtmlCount = 0;
$eduMissing = [];
$eduDupes = [];
$eduCspMeta = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/breadeducation', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'html') {
        continue;
    }
    $eduHtmlCount++;
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root . '/breadeducation/')));
    $html = (string)file_get_contents($file->getPathname());
    $hits = substr_count($html, $eduSnippet);
    if ($hits !== 1) {
        if ($hits === 0) {
            $eduMissing[] = $relative;
        } else {
            $eduDupes[] = $relative . ':' . $hits;
        }
    }
    if (stripos($html, 'Content-Security-Policy') !== false) {
        $eduCspMeta[] = $relative;
    }
}
ga_assert('breadeducation has HTML pages', $eduHtmlCount >= 70);
ga_assert(
    'every breadeducation HTML page includes shared gtag.js once' . ($eduMissing === [] && $eduDupes === [] ? '' : ' missing=' . implode(',', $eduMissing) . ' dupes=' . implode(',', $eduDupes)),
    $eduMissing === [] && $eduDupes === []
);
ga_assert('breadeducation HTML pages do not set a CSP meta', $eduCspMeta === []);

$spotlight = [
    'index.html',
    'sourdough/sourdough.html',
    'technique/bake.html',
    'classes/classes.html',
    'journal/sf-baker.html',
    'TEMPLATE.html',
];
foreach ($spotlight as $relative) {
    $html = (string)file_get_contents($root . '/breadeducation/' . $relative);
    ga_assert("$relative head loads shared gtag.js", strpos($html, $eduSnippet) !== false);
}

exit($failures > 0 ? 1 : 0);
