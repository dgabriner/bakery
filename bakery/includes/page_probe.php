<?php
/**
 * Plain-text step probe for diagnosing blank driver pages on production.
 * Append ?probe=1 to driver_list.php or driver_assignment.php while debugging.
 *
 * Probe mode intentionally bypasses database.php's auth redirect so failures
 * are visible as text instead of an empty/redirected response.
 *
 * IMPORTANT: Do not echo anything before config.php runs — session_start() must
 * happen before output or login state appears as "inactive" (false positive).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_page_probe_active(): bool
{
    return isset($_GET['probe']) && (string)$_GET['probe'] === '1';
}

/**
 * Arm probe mode before any includes. Must not echo — session starts in config.php.
 */
function bakery_page_probe_arm(string $pageLabel): void
{
    if (!bakery_page_probe_active()) {
        return;
    }

    if (!defined('BAKERY_SKIP_REQUEST_SECURITY')) {
        define('BAKERY_SKIP_REQUEST_SECURITY', true);
    }

    $GLOBALS['bakery_page_probe_label'] = $pageLabel;

    register_shutdown_function(static function (): void {
        if (!bakery_page_probe_active()) {
            return;
        }
        $err = error_get_last();
        if (!$err) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }
        echo "\nSHUTDOWN FATAL: {$err['message']}\n";
        echo "File: {$err['file']}:{$err['line']}\n";
    });
}

function bakery_page_probe_begin_output(): void
{
    if (!bakery_page_probe_active()) {
        return;
    }
    if (!empty($GLOBALS['bakery_page_probe_output_started'])) {
        return;
    }
    $GLOBALS['bakery_page_probe_output_started'] = true;

    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    @ob_implicit_flush(true);

    $label = $GLOBALS['bakery_page_probe_label'] ?? 'page';
    bakery_page_probe_step("=== {$label} probe ===");
    bakery_page_probe_step('PHP ' . PHP_VERSION);
    bakery_page_probe_step('SAPI ' . PHP_SAPI);
    bakery_page_probe_step('probe build=20260729c');
}

function bakery_page_probe_step(string $label): void
{
    if (!bakery_page_probe_active()) {
        return;
    }

    bakery_page_probe_begin_output();
    echo $label . "\n";
    if (function_exists('flush')) {
        @flush();
    }
}

function bakery_page_probe_finish(string $message = 'DONE'): void
{
    bakery_page_probe_step($message);
    bakery_page_probe_step('Remove ?probe=1 when finished.');
}

/**
 * Manual bootstrap for probe mode — does NOT run bakery_enforce_request_security().
 * Loads config (session) BEFORE any echo so login cookies are readable.
 *
 * @param list<string> $allowedRoles
 */
function bakery_page_probe_bootstrap(string $scriptLabel, array $allowedRoles): void
{
    global $db;

    // Session must start before any output
    require_once __DIR__ . '/config.php';
    bakery_page_probe_begin_output();
    bakery_page_probe_step('A config OK (session started before output)');
    bakery_page_probe_step('   APP_ENV=' . (defined('APP_ENV') ? APP_ENV : '?'));
    bakery_page_probe_step('   BASE_URL=' . (defined('BASE_URL') ? BASE_URL : '?'));
    bakery_page_probe_step('   cookie_path=' . (ini_get('session.cookie_path') ?: '(default)'));
    bakery_page_probe_step('   DB_HOST=' . (defined('DB_HOST') ? DB_HOST : '?'));
    bakery_page_probe_step('   DB_NAME=' . (defined('DB_NAME') ? DB_NAME : '?'));
    bakery_page_probe_step('   SCRIPT_NAME=' . ($_SERVER['SCRIPT_NAME'] ?? ''));

    bakery_page_probe_step('B loading database.php (auth gate skipped)');
    require_once __DIR__ . '/database.php';
    bakery_page_probe_step('C database.php included');

    if (!isset($db) || !($db instanceof PDO)) {
        bakery_page_probe_step('D $db missing — connecting manually');
        try {
            $db = check_mysql_connection();
            bakery_page_probe_step('E manual connect OK');
        } catch (Throwable $e) {
            bakery_page_probe_step('E CONNECT FAILED: ' . $e->getMessage());
            bakery_page_probe_finish('STOPPED — database connection');
            exit;
        }
    } else {
        bakery_page_probe_step('D $db already set by database.php');
    }

    bakery_page_probe_step('E bakery_get_drivers=' . (function_exists('bakery_get_drivers') ? 'yes' : 'NO'));
    bakery_page_probe_step('F bakery_json_for_html=' . (function_exists('bakery_json_for_html') ? 'yes' : 'NO'));
    bakery_page_probe_step('G bakery_current_user=' . (function_exists('bakery_current_user') ? 'yes' : 'NO'));

    if (!function_exists('bakery_get_drivers') || !function_exists('bakery_json_for_html')) {
        bakery_page_probe_step('FAIL: includes/common_functions.php is outdated or missing on server');
        bakery_page_probe_finish('STOPPED — redeploy full ZIP');
        exit;
    }

    $sessionState = session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive';
    $sessionId = session_status() === PHP_SESSION_ACTIVE ? (session_id() ?: '(empty)') : '';
    bakery_page_probe_step('H session ' . $sessionState . ($sessionId !== '' ? ' id=' . $sessionId : ''));
    bakery_page_probe_step('   has user_id in session=' . (!empty($_SESSION['user_id']) ? 'yes' : 'no'));

    $user = bakery_current_user();
    if (!$user) {
        $loginUrl = (defined('BASE_URL') ? BASE_URL : '/') . 'login.php';
        bakery_page_probe_step('AUTH FAIL: not logged in');
        bakery_page_probe_step('Normal driver_list.php redirects to login (empty body looks blank).');
        bakery_page_probe_step('Fix: log in at ' . $loginUrl . ' in this same browser, then retry ?probe=1');
        bakery_page_probe_step('Use the same path consistently (e.g. only /6/… — do not mix with /bakery/).');
        bakery_page_probe_finish('STOPPED — login required');
        exit;
    }

    bakery_page_probe_step('I AUTH user=' . $user['email'] . ' role=' . $user['role_slug']);

    if (!bakery_user_has_role($allowedRoles)) {
        bakery_page_probe_step('AUTH FAIL: role "' . $user['role_slug'] . '" cannot access ' . $scriptLabel);
        bakery_page_probe_step('Allowed: ' . implode(', ', $allowedRoles));
        bakery_page_probe_finish('STOPPED — insufficient role');
        exit;
    }

    bakery_page_probe_step('J AUTH OK for ' . $scriptLabel);

    try {
        $drivers = bakery_get_drivers($db);
        bakery_page_probe_step('K drivers count=' . count($drivers));
    } catch (Throwable $e) {
        bakery_page_probe_step('K bakery_get_drivers FAILED: ' . $e->getMessage());
        bakery_page_probe_finish('STOPPED — drivers query');
        exit;
    }

    bakery_page_probe_step('L bootstrap complete — continuing page load');
}

/** @deprecated kept so older driver_list.php copies do not fatal */
function bakery_page_probe_prepare_database(): void
{
    if (!defined('BAKERY_SKIP_REQUEST_SECURITY')) {
        define('BAKERY_SKIP_REQUEST_SECURITY', true);
    }
}

/** @deprecated */
function bakery_page_probe_report_auth(string $scriptLabel, array $allowedRoles): void
{
    bakery_page_probe_bootstrap($scriptLabel, $allowedRoles);
}
