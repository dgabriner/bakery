<?php
/**
 * Skip / restore a dated stop. Shared by My Route and Manager phone sheets.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';

/**
 * Mark an assignment stop as skipped (cancelled) with a required reason.
 */
function bakery_skip_delivery_stop(PDO $db, int $dailyOrderId, string $reason): void {
    $reason = trim($reason);
    if ($reason === '') {
        throw new Exception('A reason is required to skip this stop');
    }
    if (strlen($reason) > 500) {
        throw new Exception('Skip reason must be 500 characters or fewer');
    }

    $checkStmt = $db->prepare(
        'SELECT doa.id, doa.delivery_status
         FROM daily_order_assignments doa
         WHERE doa.daily_order_id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $checkStmt->execute([$dailyOrderId]);
    $assignment = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        throw new Exception('Stop not found on any route');
    }

    $currentStatus = (string)($assignment['delivery_status'] ?? 'pending');
    if (in_array($currentStatus, ['delivered', 'cancelled'], true)) {
        throw new Exception('This stop has already been completed or skipped');
    }

    $skipNote = 'Skipped: ' . $reason;
    $hasNotesColumn = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes');

    if ($hasNotesColumn) {
        $notesStmt = $db->prepare('SELECT notes FROM daily_order_assignments WHERE id = ?');
        $notesStmt->execute([(int)$assignment['id']]);
        $existingNotes = trim((string)($notesStmt->fetchColumn() ?: ''));
        $combinedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $skipNote : $skipNote;
        $updateStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'cancelled', notes = ?
             WHERE id = ?"
        );
        $updateStmt->execute([$combinedNotes, (int)$assignment['id']]);
    } else {
        $updateStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'cancelled'
             WHERE id = ?"
        );
        $updateStmt->execute([(int)$assignment['id']]);
    }

    if ($updateStmt->rowCount() === 0) {
        throw new Exception('Could not skip this stop');
    }

    bakery_order_leave_out_for_delivery($db, $dailyOrderId);

    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    if ($ctx) {
        bakery_record_operational_event($db, BAKERY_OP_DELIVERY_SKIPPED, 'Skipped delivery to ' . $ctx['customer_name'], [
            'operational_date' => $ctx['order_date'],
            'customer_id' => (int)$ctx['customer_id'],
            'daily_order_id' => $dailyOrderId,
            'assignment_id' => $ctx['assignment_id'] !== null ? (int)$ctx['assignment_id'] : null,
            'driver_id' => $ctx['driver_id'] !== null ? (int)$ctx['driver_id'] : bakery_operational_driver_id(),
            'metadata' => ['reason' => $reason],
        ]);
    }
}

/**
 * Restore a skipped (cancelled) stop back to pending on the driver's route.
 */
function bakery_unskip_delivery_stop(PDO $db, int $dailyOrderId): void {
    $checkStmt = $db->prepare(
        'SELECT doa.id, doa.delivery_status
         FROM daily_order_assignments doa
         WHERE doa.daily_order_id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $checkStmt->execute([$dailyOrderId]);
    $assignment = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$assignment) {
        throw new Exception('Stop not found on any route');
    }

    $currentStatus = (string)($assignment['delivery_status'] ?? 'pending');
    if ($currentStatus !== 'cancelled') {
        throw new Exception('Only skipped stops can be restored');
    }

    $restoreNote = 'Restored to route by driver';
    $hasNotesColumn = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes');

    if ($hasNotesColumn) {
        $notesStmt = $db->prepare('SELECT notes FROM daily_order_assignments WHERE id = ?');
        $notesStmt->execute([(int)$assignment['id']]);
        $existingNotes = trim((string)($notesStmt->fetchColumn() ?: ''));
        $combinedNotes = $existingNotes !== '' ? $existingNotes . "\n" . $restoreNote : $restoreNote;
        $updateStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'pending', notes = ?
             WHERE id = ?"
        );
        $updateStmt->execute([$combinedNotes, (int)$assignment['id']]);
    } else {
        $updateStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'pending'
             WHERE id = ?"
        );
        $updateStmt->execute([(int)$assignment['id']]);
    }

    $verifyStmt = $db->prepare('SELECT delivery_status FROM daily_order_assignments WHERE id = ?');
    $verifyStmt->execute([(int)$assignment['id']]);
    if ((string)($verifyStmt->fetchColumn() ?: '') !== 'pending') {
        throw new Exception('Could not restore this stop');
    }

    bakery_order_restore_out_for_delivery_if_loaded($db, $dailyOrderId);

    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    if ($ctx) {
        bakery_record_operational_event($db, BAKERY_OP_DELIVERY_UNSKIPPED, 'Restored skipped stop for ' . $ctx['customer_name'], [
            'operational_date' => $ctx['order_date'],
            'customer_id' => (int)$ctx['customer_id'],
            'daily_order_id' => $dailyOrderId,
            'assignment_id' => $ctx['assignment_id'] !== null ? (int)$ctx['assignment_id'] : null,
            'driver_id' => $ctx['driver_id'] !== null ? (int)$ctx['driver_id'] : bakery_operational_driver_id(),
        ]);
    }
}

/**
 * Assignment cancelled means this stop is not on the van. Pull the dated order
 * off out_for_delivery so staff screens do not keep showing it as rolling.
 * daily_orders has no cancelled enum — ready is "baked, not on this route."
 */
function bakery_order_leave_out_for_delivery(PDO $db, int $dailyOrderId): void
{
    if ($dailyOrderId <= 0) {
        return;
    }
    $db->prepare(
        "UPDATE daily_orders
         SET status = 'ready'
         WHERE id = ? AND status = 'out_for_delivery'"
    )->execute([$dailyOrderId]);
}

/**
 * After restoring a skipped stop, put the order back on the van only when that
 * driver still has an open (loaded, not reconciled) pickup for the date.
 */
function bakery_order_restore_out_for_delivery_if_loaded(PDO $db, int $dailyOrderId): void
{
    if ($dailyOrderId <= 0) {
        return;
    }
    $stmt = $db->prepare(
        'SELECT doa.driver_id, doa.delivery_date, do.status
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.daily_order_id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    if (in_array((string)$row['status'], ['delivered', 'invoiced'], true)) {
        return;
    }
    if (!function_exists('table_exists') || !table_exists($db, 'driver_loads')) {
        return;
    }
    $load = $db->prepare(
        "SELECT status FROM driver_loads
         WHERE driver_id = ? AND delivery_date = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $load->execute([(int)$row['driver_id'], (string)$row['delivery_date']]);
    if ((string)($load->fetchColumn() ?: '') !== 'loaded') {
        return;
    }
    $db->prepare(
        "UPDATE daily_orders
         SET status = 'out_for_delivery'
         WHERE id = ? AND status NOT IN ('delivered', 'invoiced')"
    )->execute([$dailyOrderId]);
}
