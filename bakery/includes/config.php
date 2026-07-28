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
bakery_load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

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

define('APP_ENV', bakery_app_env());
define('IS_LOCAL', APP_ENV === 'local' || APP_ENV === 'development' || APP_ENV === 'dev');

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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' maps.googleapis.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: maps.gstatic.com; connect-src 'self' maps.googleapis.com");
    }
}

/**
 * Database configuration — required env vars; no production password fallbacks.
 */
try {
    define('DB_HOST', bakery_env('DB_HOST'));
    define('DB_PORT', bakery_env('DB_PORT', '3306'));
    define('DB_NAME', bakery_env('DB_NAME'));
    define('DB_USER', bakery_env('DB_USER'));
    define('DB_PASS', bakery_env('DB_PASS'));
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Configuration error: required database environment variables are not set.\n";
    echo "For local development: copy bakery/.env.example to bakery/.env and configure bakerysf_local.\n";
    echo "For production: set DB_HOST, DB_NAME, DB_USER, DB_PASS via Apache/panel env or external config.\n";
    if (IS_LOCAL || isDevelopment()) {
        echo "\nDetail: " . $e->getMessage() . "\n";
    }
    exit(1);
}

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', 'utf8mb4_unicode_ci');

/**
 * Safety rails: local mode must never target production database hosts/names.
 */
function bakery_assert_safe_database_target() {
    $host = strtolower(DB_HOST);
    $name = strtolower(DB_NAME);

    $blockedHostFragments = ['sourflour.org', 'dreamhost'];
    foreach ($blockedHostFragments as $fragment) {
        if (strpos($host, $fragment) !== false) {
            throw new RuntimeException(
                'Refusing to connect: DB_HOST looks like a production host (' . $fragment . '). ' .
                'Local development must use 127.0.0.1 / localhost and database bakerysf_local.'
            );
        }
    }

    if (IS_LOCAL || isDevelopment()) {
        if ($name === 'bakerysf') {
            throw new RuntimeException(
                'Refusing to connect: local APP_ENV cannot use production database name bakerysf. ' .
                'Use bakerysf_local.'
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

// Application URL
if (isset($_SERVER['HTTP_HOST'])) {
    $configuredBase = $_ENV['BASE_URL'] ?? getenv('BASE_URL');
    if ($configuredBase) {
        define('BASE_URL', rtrim($configuredBase, '/') . '/');
    } elseif (IS_LOCAL || isDevelopment()) {
        define('BASE_URL', '/bakery/');
    } else {
        $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
        define('BASE_URL', ($scriptDir === '/') ? '/' : $scriptDir . '/');
    }
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
    if (isHTTPS()) {
        ini_set('session.cookie_secure', '1');
    }
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['last_regeneration'])) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

date_default_timezone_set('America/Los_Angeles');

define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024);
define('ALLOWED_UPLOAD_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx']);
define('DEFAULT_PAGE_SIZE', 25);
define('MAX_PAGE_SIZE', 100);
define('CACHE_ENABLED', !DEBUG_MODE);
define('CACHE_TTL', 300);

// Integration flags
define('MAIL_DRIVER', strtolower(bakery_env('MAIL_DRIVER', IS_LOCAL ? 'log' : 'smtp')));
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
