<?php
/**
 * Proof-of-delivery gate for marking a stop delivered.
 *
 * Arrival (Before) photos stay separate from proof (After, Receipt). That
 * split lives in bakery_route_summary_is_pod_photo. This file only decides
 * whether a confirm may proceed, and logs an explicit after-photo skip.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_summary.php';
require_once __DIR__ . '/operational_timeline.php';

function bakery_delivery_proof_photo_types(PDO $db, int $dailyOrderId): array
{
    $ctx = function_exists('bakery_operational_order_context')
        ? bakery_operational_order_context($db, $dailyOrderId)
        : null;
    if (!$ctx || !function_exists('table_exists') || !table_exists($db, 'driver_photos')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT photo_type FROM driver_photos WHERE customer_id = ? AND delivery_date = ?'
    );
    $stmt->execute([(int)$ctx['customer_id'], (string)$ctx['order_date']]);
    $types = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $type) {
        $types[] = (string)$type;
    }
    return $types;
}

function bakery_delivery_has_proof_photo(PDO $db, int $dailyOrderId): bool
{
    foreach (bakery_delivery_proof_photo_types($db, $dailyOrderId) as $type) {
        if (bakery_route_summary_is_pod_photo($type)) {
            return true;
        }
    }
    return false;
}

function bakery_delivery_proof_skip_logged(PDO $db, int $dailyOrderId): bool
{
    if ($dailyOrderId <= 0 || !function_exists('table_exists') || !table_exists($db, 'operational_events')) {
        return false;
    }
    $stmt = $db->prepare(
        "SELECT 1 FROM operational_events
         WHERE daily_order_id = ? AND event_type = ?
         LIMIT 1"
    );
    $stmt->execute([$dailyOrderId, BAKERY_OP_DELIVERY_PROOF_SKIPPED]);
    return (bool)$stmt->fetchColumn();
}

function bakery_delivery_log_proof_skip(PDO $db, int $dailyOrderId): void
{
    if ($dailyOrderId <= 0 || bakery_delivery_proof_skip_logged($db, $dailyOrderId)) {
        return;
    }
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    $driverId = is_array($ctx) ? (int)($ctx['driver_id'] ?? 0) : 0;
    $customerId = is_array($ctx) ? (int)($ctx['customer_id'] ?? 0) : 0;
    bakery_record_operational_event(
        $db,
        BAKERY_OP_DELIVERY_PROOF_SKIPPED,
        'Driver skipped the after photo before marking the stop delivered',
        [
            'daily_order_id' => $dailyOrderId,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'driver_id' => $driverId > 0 ? $driverId : null,
            'assignment_id' => is_array($ctx) ? ($ctx['assignment_id'] ?? null) : null,
            'operational_date' => is_array($ctx) ? (string)($ctx['order_date'] ?? '') : null,
            'metadata' => ['explicit' => true],
        ]
    );
}

function bakery_delivery_order_status(PDO $db, int $dailyOrderId): string
{
    if ($dailyOrderId <= 0) {
        return '';
    }
    $stmt = $db->prepare('SELECT status FROM daily_orders WHERE id = ?');
    $stmt->execute([$dailyOrderId]);
    $status = $stmt->fetchColumn();
    return is_string($status) ? $status : '';
}

/**
 * Block a not-yet-delivered stop unless it has a proof photo or an explicit skip.
 * An explicit skip is logged once. Already delivered stops can be saved again.
 */
function bakery_delivery_assert_proof_for_confirm(PDO $db, int $dailyOrderId, bool $explicitSkip): void
{
    $status = bakery_delivery_order_status($db, $dailyOrderId);
    if (in_array($status, ['delivered', 'invoiced'], true)) {
        return;
    }
    if ($explicitSkip && !bakery_delivery_has_proof_photo($db, $dailyOrderId)) {
        bakery_delivery_log_proof_skip($db, $dailyOrderId);
    }
    if (bakery_delivery_has_proof_photo($db, $dailyOrderId) || bakery_delivery_proof_skip_logged($db, $dailyOrderId)) {
        return;
    }
    $message = function_exists('bakery_t')
        ? bakery_t('driver.proof_required')
        : 'Take the after photo, or choose Skip leaving photo, before this stop can be marked delivered.';
    throw new Exception($message);
}
