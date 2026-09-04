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
