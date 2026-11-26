<?php
/**
 * Opaque client request ids for idempotent driver photo/confirm writes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Normalize a client-supplied request id. Empty string means "not provided".
 */
function bakery_normalize_client_request_id($raw): string
{
    $id = trim((string)$raw);
    if ($id === '' || strlen($id) > 64) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
        return '';
    }
    return $id;
}

function bakery_daily_orders_confirm_request_id_ready(PDO $db): bool
{
    return function_exists('column_exists') && column_exists($db, 'daily_orders', 'confirm_request_id');
}

function bakery_driver_photos_client_request_id_ready(PDO $db): bool
{
    return function_exists('column_exists') && column_exists($db, 'driver_photos', 'client_request_id');
}

/**
 * True when a confirm write lost the race on confirm_request_id.
 * The unique index is the server-side guard; the caller returns the first save.
 */
function bakery_is_duplicate_confirm_error(Throwable $e): bool
{
    $message = $e->getMessage();
    if (stripos($message, 'Duplicate entry') !== false) {
        return true;
    }
    if (stripos($message, 'confirm_request_id') !== false && stripos($message, 'duplicate') !== false) {
        return true;
    }
    if ($e instanceof PDOException) {
        $state = (string)($e->errorInfo[0] ?? '');
        $code = (string)($e->errorInfo[1] ?? '');
        if ($state === '23000' || $code === '1062') {
            return true;
        }
    }
    return false;
}
