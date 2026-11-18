<?php
/**
 * Proof-of-delivery gate for marking a stop delivered.
 *
 * Whether a Before photo is enough lives in BAKERY_DELIVERY_BEFORE_PHOTO_COUNTS.
 * It is true, so a stop is never blocked in the field for lack of an After
 * photo. After and Receipt still count. Reports use includes/stop_proof.php
 * and do not read this constant. This file only decides whether a confirm
 * may proceed, and logs a no-photo delivery with the driver's reason.
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

/**
 * Danny's decision. True: an arrival photo still lets the driver mark delivered.
 * Set this to false later to require an After photo at the gate.
 */
const BAKERY_DELIVERY_BEFORE_PHOTO_COUNTS = true;

function bakery_delivery_is_proof_photo_type(string $type): bool
{
    if (bakery_route_summary_is_arrival_photo($type)) {
        return BAKERY_DELIVERY_BEFORE_PHOTO_COUNTS;
    }
    return bakery_route_summary_is_pod_photo($type);
}

/** @return list<string> */
function bakery_delivery_proof_skip_reasons(): array
{
    return ['camera_broken', 'customer_declined', 'no_safe_spot', 'other'];
}

function bakery_delivery_normalize_proof_skip_reason($reason): string
{
    $reason = strtolower(trim((string)$reason));
    if ($reason === '') {
        return '';
    }
    return in_array($reason, bakery_delivery_proof_skip_reasons(), true) ? $reason : 'other';
}

function bakery_delivery_has_proof_photo(PDO $db, int $dailyOrderId): bool
{
    foreach (bakery_delivery_proof_photo_types($db, $dailyOrderId) as $type) {
        if (bakery_delivery_is_proof_photo_type($type)) {
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

function bakery_delivery_log_proof_skip(PDO $db, int $dailyOrderId, string $reason = ''): void
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
        'Driver marked the stop delivered with no photo',
        [
            'daily_order_id' => $dailyOrderId,
            'customer_id' => $customerId > 0 ? $customerId : null,
            'driver_id' => $driverId > 0 ? $driverId : null,
            'assignment_id' => is_array($ctx) ? ($ctx['assignment_id'] ?? null) : null,
            'operational_date' => is_array($ctx) ? (string)($ctx['order_date'] ?? '') : null,
            'metadata' => array_filter([
                'explicit' => true,
                'reason' => bakery_delivery_normalize_proof_skip_reason($reason),
            ], static fn($value): bool => $value !== ''),
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
function bakery_delivery_assert_proof_for_confirm(PDO $db, int $dailyOrderId, bool $explicitSkip, string $skipReason = ''): void
{
    $status = bakery_delivery_order_status($db, $dailyOrderId);
    if (in_array($status, ['delivered', 'invoiced'], true)) {
        return;
    }
    if ($explicitSkip && !bakery_delivery_has_proof_photo($db, $dailyOrderId)) {
        bakery_delivery_log_proof_skip($db, $dailyOrderId, $skipReason);
    }
    if (bakery_delivery_has_proof_photo($db, $dailyOrderId) || bakery_delivery_proof_skip_logged($db, $dailyOrderId)) {
        return;
    }
    $message = function_exists('bakery_t')
        ? bakery_t('driver.proof_required')
        : 'Take the arrival photo, or choose No photo and a reason, before this stop can be marked delivered.';
    throw new Exception($message);
}
