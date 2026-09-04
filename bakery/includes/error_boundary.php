<?php
/**
 * One error boundary (Mission 34).
 *
 * - Uncaught exceptions, warnings, and fatals are logged with a short error_id.
 * - Non-local requests get a generic bilingual page or {"success":false,
 *   "error":"internal","error_id":...} — never SQL text, paths, or traces.
 * - Local keeps PHP's own display so developers see the trace.
 *
 * Pages that catch their own exceptions should render
 * bakery_error_message_for_user($e) instead of $e->getMessage().
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_error_boundary_is_local(): bool
{
    return defined('IS_LOCAL') && IS_LOCAL;
}

function bakery_error_id(): string
{
    return date('md') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/** Log detail once and return the short id a user can quote back to staff. */
function bakery_error_log_throwable(Throwable $e, string $context = ''): string
{
    $id = bakery_error_id();
    $line = sprintf(
        '[error_id %s]%s %s: %s in %s:%d',
        $id,
        $context !== '' ? ' [' . $context . ']' : '',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
    error_log($line);
    if (function_exists('app_log')) {
        app_log($line, 'error');
    }
    return $id;
}

/**
 * Exceptions our own helpers raise for users (RuntimeException with a plain
 * sentence, InvalidArgumentException, DomainException) pass through. Anything
 * infrastructural — PDOException, ErrorException, TypeError, Error — is logged
 * and replaced by a generic message carrying an error_id.
 */
function bakery_error_message_for_user(Throwable $e, string $context = ''): string
{
    $isUserFacing = ($e instanceof InvalidArgumentException || $e instanceof DomainException
        || ($e instanceof RuntimeException && !($e instanceof PDOException) && !($e instanceof UnexpectedValueException)))
        && !bakery_error_message_looks_technical($e->getMessage());
    if ($isUserFacing) {
        return $e->getMessage();
    }
    $id = bakery_error_log_throwable($e, $context);
    if (bakery_error_boundary_is_local()) {
        return $e->getMessage() . ' [error_id ' . $id . ']';
    }
    $generic = function_exists('bakery_t') ? bakery_t('error.internal') : 'Something went wrong. Please try again; if it keeps happening tell the office.';
    return $generic . ' (' . $id . ')';
}

function bakery_error_message_looks_technical(string $message): bool
{
    return (bool)preg_match('/SQLSTATE|syntax error|Unknown column|Table \'|Duplicate entry|Integrity constraint|Call to |Undefined |stack trace|\.php:\d+|\.php on line|Uncaught/i', $message);
}

function bakery_error_boundary_wants_json(): bool
{
    if (function_exists('bakery_wants_json')) {
        return bakery_wants_json();
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return strpos($accept, 'application/json') !== false
        || substr(basename($_SERVER['SCRIPT_NAME'] ?? ''), -8) === '_api.php';
}

function bakery_error_boundary_render(string $errorId): void
{
    $generic = function_exists('bakery_t') ? bakery_t('error.internal') : 'Something went wrong. Please try again; if it keeps happening tell the office.';
    $wantsJson = bakery_error_boundary_wants_json();
    if (headers_sent() && !$wantsJson) {
        // Mid-page fatal: the HTML is already half out; leave a traceable marker.
        echo "\n<!-- error_id {$errorId} -->\n";
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    if ($wantsJson) {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode(['success' => false, 'ok' => false, 'error' => 'internal', 'error_id' => $errorId, 'message' => $generic]);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($generic, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Sour Flour OS</title>"
        . '<style>body{font-family:system-ui,sans-serif;margin:0;padding:48px 20px;background:#f7f3ec;color:#1f2a24}main{max-width:32rem;margin:0 auto;background:#fff;border-radius:16px;padding:28px}h1{font-size:1.25rem;margin:0 0 12px}p{line-height:1.5}code{font-size:.95rem;background:#f1ece3;padding:2px 6px;border-radius:6px}a{color:#2f5d3a;font-weight:600;min-height:44px;display:inline-flex;align-items:center}</style></head><body><main>'
        . '<h1>' . $safe . '</h1>'
        . '<p>Error ID / ID de error: <code>' . htmlspecialchars($errorId, ENT_QUOTES, 'UTF-8') . '</code></p>'
        . '<p><a href="javascript:history.back()">&larr; Back / Volver</a></p>'
        . '</main></body></html>';
}

function bakery_error_boundary_register(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }
    $registered = true;

    set_exception_handler(static function (Throwable $e): void {
        $id = bakery_error_log_throwable($e, 'uncaught');
        if (bakery_error_boundary_is_local()) {
            // Developers see the real trace; keep PHP's own rendering.
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Uncaught ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
            return;
        }
        bakery_error_boundary_render($id);
    });

    // Warnings and notices never reach the browser outside local; they go to the log.
    set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if (bakery_error_boundary_is_local()) {
            return false; // default display for developers
        }
        error_log(sprintf('[php %d] %s in %s:%d', $severity, $message, $file, $line));
        return true;
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        if (bakery_error_boundary_is_local()) {
            return; // display_errors is on locally
        }
        $id = bakery_error_id();
        error_log(sprintf('[error_id %s] [fatal] %s in %s:%d', $id, $err['message'], $err['file'], $err['line']));
        bakery_error_boundary_render($id);
    });
}
