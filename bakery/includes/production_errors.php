<?php
/**
 * Register a shutdown handler that surfaces fatal errors when BAKERY_SHOW_ERRORS=1 in .env.
 * Use temporarily on production, then turn off.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_show_errors_enabled(): bool
{
    $raw = $_ENV['BAKERY_SHOW_ERRORS'] ?? getenv('BAKERY_SHOW_ERRORS') ?: '';
    $flag = strtolower((string)$raw);
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function bakery_register_production_error_probe(): void
{
    if (PHP_SAPI === 'cli' || !bakery_show_errors_enabled()) {
        return;
    }

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if (!$err) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }
        if (!(defined('IS_LOCAL') && IS_LOCAL)) {
            // Never print file paths or messages on a shared host; the error
            // boundary already logged an error_id and rendered the generic page.
            error_log('BAKERY_SHOW_ERRORS is set on a non-local host; detail suppressed.');
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            http_response_code(500);
        }
        echo "FATAL: {$err['message']}\n";
        echo "File: {$err['file']}:{$err['line']}\n";
        echo "Turn off BAKERY_SHOW_ERRORS in .env after debugging.\n";
    });
}
