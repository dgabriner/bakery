<?php
/**
 * Application Configuration
 *
 * Loads environment via a minimal .env file (local) or process/Apache env (production).
 * Production credential fallbacks have been removed (Checkpoint 0B).
 *
 * @package BakeryManagement
 * @version 1.1
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/env_loader.php';

// Load bakery/.env when present (local development). Never commit .env.
$bakeryRoot = dirname(__DIR__);
bakery_load_env_file($bakeryRoot . DIRECTORY_SEPARATOR . '.env');

/**
 * Truthy env flag helper (1/true/yes/on).
 */
if (!function_exists('bakery_env_flag')) {
    function bakery_env_flag($name, $default = false) {
        $raw = $_ENV[$name] ?? getenv($name);
        if ($raw === false || $raw === null || $raw === '') {
            return (bool) $default;
        }
        return in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
    }
}

// Opt-in: local APP_ENV may use DreamHost credentials from .env.production.pull
// when USE_PROD_DB=true. Keeps local DB_* in .env untouched for easy switching.
$useProdDbRequested = bakery_env_flag('USE_PROD_DB', false);
if ($useProdDbRequested) {
    $pullEnv = $bakeryRoot . DIRECTORY_SEPARATOR . '.env.production.pull';
    if (!is_readable($pullEnv)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "USE_PROD_DB=true but bakery/.env.production.pull is missing.\n";
        echo "Copy .env.production.pull.example and set PROD_DB_*, or run:\n";
        echo "  php scripts/switch_db.php local\n";
        exit(1);
    }
    bakery_load_env_file($pullEnv);
}

$prodErr = __DIR__ . '/production_errors.php';
if (is_readable($prodErr)) {
    require_once $prodErr;
    if (function_exists('bakery_register_production_error_probe')) {
        bakery_register_production_error_probe();
    }
}

/**
 * Enhanced HTTPS detection for various hosting environments
 *
 * @return bool True if request is over HTTPS
 */
function isHTTPS() {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        return true;
    }
    return false;
}

/**
 * Detect if running in development / local environment
 *
 * @return bool
 */
function isDevelopment() {
    $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
    if (in_array($appEnv, ['local', 'development', 'dev'], true)) {
        return true;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    return in_array($hostWithoutPort, ['localhost', '127.0.0.1', '::1'], true) ||
           strpos($hostWithoutPort, '.local') !== false;
}

/**
 * @return string
 */
function bakery_app_env() {
    $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: ''));
    if ($appEnv !== '') {
        return $appEnv;
    }
    return isDevelopment() ? 'local' : 'production';
}

function bakery_request_host() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    return strtolower((string)preg_replace('/:\d+$/', '', $host));
}

function bakery_is_staging_host() {
    return bakery_request_host() === 'staging.sourflour.org';
}

function bakery_is_live_bakery_host() {
    $host = bakery_request_host();
    return $host === 'bakery.sourflour.org' || $host === 'www.bakery.sourflour.org';
}

define('APP_ENV', bakery_app_env());
define('IS_LOCAL', APP_ENV === 'local' || APP_ENV === 'development' || APP_ENV === 'dev');
define('IS_STAGING', APP_ENV === 'staging' || bakery_is_staging_host());

// Force HTTPS in production only (no debug exemptions)
if (PHP_SAPI !== 'cli' && !isHTTPS() && !IS_LOCAL && !isDevelopment()) {
    $redirectURL = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header("Location: $redirectURL", true, 301);
    exit();
}

if (PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (!IS_LOCAL && !isDevelopment()) {
        // Allow Google Maps JS API tiles/fonts, plus the Sour Flour Google tag (gtag.js)
        // on bakery and hosted staging. Do not disable this CSP.
        header(
            "Content-Security-Policy: " .
            "default-src 'self'; " .
            "script-src 'self' 'unsafe-inline' https://maps.googleapis.com https://maps.gstatic.com https://www.googletagmanager.com https://*.googletagmanager.com https://www.google-analytics.com https://www.googleadservices.com https://www.google.com; " .
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
            "img-src 'self' data: blob: https://maps.gstatic.com https://maps.googleapis.com https://*.googleapis.com https://*.gstatic.com https://*.ggpht.com https://*.google.com https://*.googleusercontent.com https://www.google-analytics.com https://www.googletagmanager.com https://*.google-analytics.com https://*.googletagmanager.com https://*.g.doubleclick.net; " .
            "font-src 'self' https://fonts.gstatic.com; " .
            "connect-src 'self' https://maps.googleapis.com https://www.googletagmanager.com https://www.google-analytics.com https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com https://www.googleadservices.com https://*.g.doubleclick.net; " .
            "frame-src https://www.googletagmanager.com https://www.googleadservices.com; " .
            "worker-src 'self' blob:"
        );
    }
}

/**
 * Database configuration — required env vars; no production password fallbacks.
 * When USE_PROD_DB=true (local only), runtime DB_* come from PROD_DB_* in .env.production.pull.
 */
define('USE_PROD_DB', $useProdDbRequested && (IS_LOCAL || isDevelopment()));

try {
    if ($useProdDbRequested && !IS_LOCAL && !isDevelopment()) {
        throw new RuntimeException('USE_PROD_DB=true is only allowed when APP_ENV is local/development.');
    }
    if (USE_PROD_DB) {
        define('DB_HOST', bakery_env('PROD_DB_HOST'));
        define('DB_PORT', bakery_env('PROD_DB_PORT', '3306'));
        define('DB_NAME', bakery_env('PROD_DB_NAME'));
        define('DB_USER', bakery_env('PROD_DB_USER'));
        define('DB_PASS', bakery_env('PROD_DB_PASS'));
    } else {
        define('DB_HOST', bakery_env('DB_HOST'));
        define('DB_PORT', bakery_env('DB_PORT', '3306'));
        define('DB_NAME', bakery_env('DB_NAME'));
        define('DB_USER', bakery_env('DB_USER'));
        define('DB_PASS', bakery_env('DB_PASS'));
    }
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration error: required database environment variables are not set.\n";
    echo "For local development: copy bakery/.env.example to bakery/.env and configure bakerysf_local.\n";
    echo "To use production from local: set USE_PROD_DB=true and configure .env.production.pull (PROD_DB_*).\n";
    echo "For production: set DB_HOST, DB_NAME, DB_USER, DB_PASS via Apache/panel env or external config.\n";
    if (IS_LOCAL || isDevelopment()) {
        echo "\nDetail: " . $e->getMessage() . "\n";
    }
    exit(1);
}

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

/**
 * When false, runtime code must not run idempotent CREATE/ALTER migrations.
 * Local USE_PROD_DB sessions talk to live production — schema is managed on deploy.
 */
function bakery_runtime_schema_ddl_allowed(): bool {
    return !(defined('USE_PROD_DB') && USE_PROD_DB);
}

// Local app → remote DreamHost: allow slower connect + many round-trips per page.
if (PHP_SAPI !== 'cli' && defined('USE_PROD_DB') && USE_PROD_DB && defined('IS_LOCAL') && IS_LOCAL) {
    @ini_set('max_execution_time', '180');
    @ini_set('default_socket_timeout', '120');
}

/**
 * Safety rails:
 * - Default local mode must never target production hosts/names.
 * - USE_PROD_DB=true explicitly allows production, but requires PROD-looking credentials.
 */
function bakery_assert_safe_database_target() {
    $host = strtolower(DB_HOST);
    $name = strtolower(DB_NAME);

    if (defined('IS_STAGING') && IS_STAGING) {
        if (USE_PROD_DB) {
            throw new RuntimeException('USE_PROD_DB is not allowed on staging.');
        }
        if ($name === 'bakerysf') {
            throw new RuntimeException('Refusing: staging cannot use production database bakerysf.');
        }
        if ($name !== 'bakerysoftware') {
            throw new RuntimeException('Refusing: staging requires database bakerysoftware, got ' . DB_NAME);
        }
        $looksDreamhost = (strpos($host, 'sourflour') !== false || strpos($host, 'dreamhost') !== false);
        if (!$looksDreamhost) {
            throw new RuntimeException('Refusing: staging database host must be the DreamHost MySQL host.');
        }
        return;
    }

    if (bakery_is_live_bakery_host() && $name === 'bakerysoftware') {
        throw new RuntimeException('Refusing: live bakery host cannot use staging database bakerysoftware.');
    }

    if (!IS_LOCAL && !isDevelopment() && APP_ENV === 'production' && $name === 'bakerysoftware') {
        throw new RuntimeException('Refusing: production APP_ENV cannot use staging database bakerysoftware.');
    }

    if (USE_PROD_DB) {
        $looksProd = (
            strpos($host, 'sourflour') !== false ||
            strpos($host, 'dreamhost') !== false ||
            $name === 'bakerysf'
        );
        if (!$looksProd) {
            throw new RuntimeException(
                'USE_PROD_DB=true but PROD_DB_HOST/NAME do not look like production ' .
                '(expected sourflour/dreamhost host or bakerysf).'
            );
        }
        if (strpos($name, '_local') !== false || strpos($name, 'test') !== false) {
            throw new RuntimeException(
                'USE_PROD_DB=true but database name looks nonproduction: ' . DB_NAME
            );
        }
        return;
    }

    if (IS_LOCAL || isDevelopment()) {
        $blockedHostFragments = ['sourflour.org', 'dreamhost'];
        foreach ($blockedHostFragments as $fragment) {
            if (strpos($host, $fragment) !== false) {
                throw new RuntimeException(
                    'Refusing to connect: DB_HOST looks like a production host (' . $fragment . '). ' .
                    'Use bakerysf_local, or set USE_PROD_DB=true (php scripts/switch_db.php prod).'
                );
            }
        }

        if ($name === 'bakerysf' || $name === 'bakerysoftware') {
            throw new RuntimeException(
                'Refusing to connect: local APP_ENV cannot use hosted database name ' . DB_NAME . '. ' .
                'Use bakerysf_local / bakerysf_stage_local, or set USE_PROD_DB=true only for live bakerysf.'
            );
        }
        if (strpos($name, '_local') === false && strpos($name, 'test') === false && strpos($name, 'dev') === false) {
            throw new RuntimeException(
                'Refusing to connect: local DB_NAME must contain _local, test, or dev. Got: ' . DB_NAME
            );
        }
    }
}

try {
    bakery_assert_safe_database_target();
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Safety check failed: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * True when the PHP built-in server (or vhost) uses the bakery folder as docroot
 * (e.g. /login.php instead of /bakery/login.php).
 */
function bakery_served_at_app_root() {
    if (PHP_SAPI === 'cli') {
        return false;
    }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    return strpos($script, '/bakery/') === false;
}

/**
 * Resolve BASE_URL for web requests; honors .env but adapts for local docroot layouts.
 */
function bakery_resolve_base_url() {
    $configuredBase = $_ENV['BASE_URL'] ?? getenv('BASE_URL');
    if ($configuredBase !== false && $configuredBase !== null && $configuredBase !== '') {
        $base = rtrim((string)$configuredBase, '/') . '/';
    } elseif (IS_LOCAL || isDevelopment()) {
        $base = '/bakery/';
    } else {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = ($scriptDir === '/' || $scriptDir === '.') ? '/' : rtrim($scriptDir, '/') . '/';
    }

    if ((IS_LOCAL || isDevelopment()) && bakery_served_at_app_root()) {
        return '/';
    }

    return $base;
}

// Application URL
if (isset($_SERVER['HTTP_HOST'])) {
    define('BASE_URL', bakery_resolve_base_url());
} else {
    $configuredBase = $_ENV['BASE_URL'] ?? getenv('BASE_URL') ?: '/bakery/';
    define('BASE_URL', rtrim($configuredBase, '/') . '/');
}

define('SITE_NAME', bakery_env('APP_NAME', 'Bakery Management System'));
define('VERSION', '1.0.0');
define('DEBUG_MODE', IS_LOCAL || isDevelopment());

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    if (is_writable(dirname(__FILE__) . '/../logs/')) {
        ini_set('error_log', dirname(__FILE__) . '/../logs/error.log');
    }
}

// Session security (web only)
if (PHP_SAPI !== 'cli') {
    $cookiePath = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    // The application deliberately keeps bakery work sessions for up to 180 days.
    // PHP otherwise commonly garbage-collects session data after 24 minutes,
    // which looks like a random logout when a driver returns from the phone camera.
    $sessionLifetime = 180 * 24 * 60 * 60;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    if (isHTTPS()) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    // Scope session cookie to this deploy path (e.g. /6/ or /bakery/)
    ini_set('session.cookie_path', $cookiePath);

    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => $sessionLifetime,
            'path' => $cookiePath,
            'secure' => isHTTPS(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) {
        // A driver can return from the phone camera into several concurrent API
        // requests. Keep the previous session record until normal garbage
        // collection so a sibling request using the prior cookie does not lose
        // authentication or the CSRF token mid-workflow.
        session_regenerate_id(false);
        $_SESSION['last_regeneration'] = time();
    }
}

date_default_timezone_set('America/Los_Angeles');

require_once __DIR__ . '/i18n.php';
if (PHP_SAPI !== 'cli') {
    bakery_handle_locale_request();
}

define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);
define('ALLOWED_UPLOAD_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
define('DEFAULT_PAGE_SIZE', 25);
define('MAX_PAGE_SIZE', 100);
define('CACHE_ENABLED', !DEBUG_MODE);
define('CACHE_TTL', 300);

// Integration flags
define('MAIL_DRIVER', strtolower(bakery_env('MAIL_DRIVER', IS_LOCAL || (defined('IS_STAGING') && IS_STAGING) ? 'log' : 'smtp')));
if (defined('IS_STAGING') && IS_STAGING && MAIL_DRIVER !== 'log') {
    throw new RuntimeException('Staging must use MAIL_DRIVER=log so customer/driver mail is not sent.');
}
define('MAPS_ENABLED', filter_var(bakery_env('MAPS_ENABLED', IS_LOCAL ? 'false' : 'true'), FILTER_VALIDATE_BOOLEAN));

function redirect($path) {
    $url = BASE_URL . ltrim($path, '/');
    $url = filter_var($url, FILTER_SANITIZE_URL);
    header("Location: $url");
    exit();
}

function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function format_price($amount) {
    return '$' . number_format((float)$amount, 2);
}

function format_date($date, $format = 'M j, Y') {
    try {
        return date($format, strtotime($date));
    } catch (Exception $e) {
        return $date;
    }
}

function human_filesize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $factor = floor((strlen((string)$bytes) - 1) / 3);
    return sprintf("%.1f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
}

function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

function is_ajax_request() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function app_log($message, $level = 'info') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    $logFile = dirname(__FILE__) . '/../logs/app.log';
    if (is_dir(dirname($logFile)) && is_writable(dirname($logFile))) {
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

require_once __DIR__ . '/client_cache.php';
if (PHP_SAPI !== 'cli') {
    bakery_send_document_cache_headers();
}
