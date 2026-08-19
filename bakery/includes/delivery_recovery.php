<?php
/**
 * Auditable failed-stop recovery workflow. Delivery status remains canonical on
 * daily_order_assignments; this service only records the manager disposition.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';

function bakery_delivery_recovery_reason_codes(): array
{
    return [
        'recipient_unavailable' => 'Recipient unavailable',
        'access_issue' => 'Access issue',
        'unsafe_conditions' => 'Unsafe conditions',
        'vehicle_issue' => 'Vehicle issue',
        'product_issue' => 'Product issue',
        'customer_request' => 'Customer request',
        'payment_issue' => 'Payment issue',
        'other' => 'Other',
    ];
}

function bakery_delivery_recovery_transition_allowed(string $from, string $to): bool
{
    $allowed = [
        'open' => ['acknowledged', 'retry_scheduled', 'reassigned', 'resolved'],
        'acknowledged' => ['retry_scheduled', 'reassigned', 'resolved'],
        'retry_scheduled' => ['reassigned', 'resolved'],
        'reassigned' => ['resolved'],
        'resolved' => ['closed'],
        'closed' => [],
    ];
    return in_array($to, $allowed[$from] ?? [], true);
}

function bakery_delivery_recovery_note($value, bool $required = false): string
{
    $note = trim((string)$value);
    if (strlen($note) > 2000) {
        throw new InvalidArgumentException('Manager note must be 2,000 characters or fewer');
    }
    if ($required && $note === '') {
        throw new InvalidArgumentException('A manager note is required');
    }
    return $note;
}

/** True when the signed-in role may run this recovery command. */
function bakery_delivery_recovery_actor_may(string $action): bool
{
    $action = strtolower(trim($action));
    if ($action === 'report_failure') {
        return function_exists('bakery_user_has_role')
            && bakery_user_has_role(['administrator', 'manager', 'driver']);
    }
    return function_exists('bakery_user_has_role')
        && bakery_user_has_role(['administrator', 'manager']);
}

/** @return array<string,mixed> Normalized input for a recovery command. */
function bakery_delivery_recovery_validate_input(string $action, array $input): array
{
    $action = strtolower(trim($action));
    $note = bakery_delivery_recovery_note($input['manager_note'] ?? '', in_array($action, [
        'report_failure', 'retry', 'reassign', 'resolve', 'close', 'update_handoffs',
    ], true));
    $out = ['action' => $action, 'manager_note' => $note];

    if ($action === 'report_failure') {
        $reason = strtolower(trim((string)($input['reason_code'] ?? '')));
        if (!isset(bakery_delivery_recovery_reason_codes()[$reason])) {
            throw new InvalidArgumentException('Choose a valid failure reason');
        }
        if ($reason === 'other' && $note === '') {
            throw new InvalidArgumentException('Other failure reasons need an explanatory note');
        }
        return $out + ['reason_code' => $reason, 'state' => 'open'];
    }

    if ($action === 'retry') {
        $retryAt = trim((string)($input['retry_at'] ?? ''));
        $retryAt = str_replace('T', ' ', $retryAt);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $retryAt)) {
            $retryAt .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $retryAt);
        if (!$date || $date->format('Y-m-d H:i:s') !== $retryAt || $date->getTimestamp() <= time()) {
            throw new InvalidArgumentException('Retry needs a future date and time');
        }
        return $out + ['retry_at' => $retryAt, 'state' => 'retry_scheduled'];
    }

    if ($action === 'reassign') {
        $driverId = (int)($input['to_driver_id'] ?? 0);
        if ($driverId <= 0) {
            throw new InvalidArgumentException('Choose an active driver for reassignment');
        }
        return $out + ['to_driver_id' => $driverId, 'state' => 'reassigned'];
    }

    if ($action === 'resolve') {
        return $out + ['state' => 'resolved'];
    }
    if ($action === 'close') {
        return $out + ['state' => 'closed'];
    }
    if ($action === 'acknowledge') {
        return $out + ['state' => 'acknowledged'];
    }
    if ($action === 'update_handoffs') {
        $communication = strtolower(trim((string)($input['communication_status'] ?? 'pending')));
        $billing = strtolower(trim((string)($input['billing_handoff'] ?? 'review_needed')));
        if (!in_array($communication, ['not_needed', 'pending', 'contacted', 'unable_to_reach'], true)) {
            throw new InvalidArgumentException('Choose a valid customer communication status');
        }
        if (!in_array($billing, ['not_needed', 'review_needed', 'credit_requested', 'credit_issued', 'not_billable'], true)) {
            throw new InvalidArgumentException('Choose a valid billing handoff');
        }
        return $out + [
            'communication_status' => $communication,
            'communication_note' => bakery_delivery_recovery_note($input['communication_note'] ?? ''),
            'billing_handoff' => $billing,
        ];
    }
    throw new InvalidArgumentException('Unknown recovery action');
}

function bakery_delivery_recovery_ready(PDO $db): bool
{
    return table_exists($db, 'delivery_recovery_cases');
}

function bakery_delivery_recovery_event(PDO $db, array $case, string $action, array $metadata = []): void
{
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    bakery_record_operational_event($db, 'delivery_recovery_' . $action, 'Delivery recovery ' . str_replace('_', ' ', $action), [
        'operational_date' => $case['delivery_date'],
        'daily_order_id' => (int)$case['daily_order_id'],
        'assignment_id' => (int)($case['active_assignment_id'] ?: $case['failed_assignment_id']),
        'driver_id' => $case['reassigned_to_driver_id'] ?: $case['original_driver_id'],
        'metadata' => array_merge([
            'recovery_case_id' => (int)$case['id'],
            'failed_assignment_id' => (int)$case['failed_assignment_id'],
            'workflow_state' => $case['workflow_state'],
        ], $metadata),
    ]);
}

function bakery_delivery_recovery_case(PDO $db, int $caseId, bool $forUpdate = false): array
{
    $stmt = $db->prepare('SELECT * FROM delivery_recovery_cases WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->execute([$caseId]);
    $case = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$case) {
        throw new RuntimeException('Recovery case not found');
    }
    return $case;
}

function bakery_delivery_recovery_assert_report_access(array $assignment): void
{
    if (!bakery_delivery_recovery_actor_may('report_failure')) {
        throw new RuntimeException('You cannot report a failed stop');
    }
    if (function_exists('bakery_user_has_role') && bakery_user_has_role(['administrator', 'manager'])) {
        return;
    }
    $driverId = 0;
    if (function_exists('bakery_get_selected_driver_id')) {
        $driverId = (int)bakery_get_selected_driver_id();
    }
    if ($driverId <= 0 && function_exists('bakery_current_user')) {
        $driverId = (int)(bakery_current_user()['driver_id'] ?? 0);
    }
    if ($driverId <= 0 || (int)($assignment['driver_id'] ?? 0) !== $driverId) {
        throw new RuntimeException('You can only report a failed stop on your own route');
    }
}

/** Report a failed assignment and create its manager recovery case. */
function bakery_delivery_recovery_report_failure(PDO $db, int $assignmentId, array $input): array
{
    if (!bakery_delivery_recovery_actor_may('report_failure')) {
        throw new RuntimeException('You cannot report a failed stop');
    }
    if (!bakery_delivery_recovery_ready($db)) {
        throw new RuntimeException('Delivery recovery is not installed. Run local migrations first.');
    }
    $data = bakery_delivery_recovery_validate_input('report_failure', $input);
    $managerAck = function_exists('bakery_user_has_role') && bakery_user_has_role(['administrator', 'manager']);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT id, daily_order_id, driver_id, delivery_date, delivery_status FROM daily_order_assignments WHERE id = ? FOR UPDATE');
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            throw new RuntimeException('Delivery assignment not found');
        }
        bakery_delivery_recovery_assert_report_access($assignment);
        if (!in_array((string)$assignment['delivery_status'], ['pending', 'in_transit', 'failed'], true)) {
            throw new RuntimeException('Only pending or in-transit stops can be reported as failed');
        }
        $existing = $db->prepare('SELECT * FROM delivery_recovery_cases WHERE failed_assignment_id = ? FOR UPDATE');
        $existing->execute([$assignmentId]);
        $case = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$case) {
            $db->prepare("UPDATE daily_order_assignments SET delivery_status = 'failed' WHERE id = ?")->execute([$assignmentId]);
            $actor = (int)(bakery_current_user()['id'] ?? 0) ?: null;
            $insert = $db->prepare(
                "INSERT INTO delivery_recovery_cases
                 (failed_assignment_id, active_assignment_id, daily_order_id, delivery_date, original_driver_id,
                  failure_reason, manager_note, workflow_state, acknowledged_at, acknowledged_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', IF(?, NOW(), NULL), IF(?, ?, NULL))"
            );
            $insert->execute([
                $assignmentId, $assignmentId, (int)$assignment['daily_order_id'], $assignment['delivery_date'],
                (int)$assignment['driver_id'], $data['reason_code'], $data['manager_note'],
                $managerAck ? 1 : 0, $managerAck ? 1 : 0, $actor,
            ]);
            $case = bakery_delivery_recovery_case($db, (int)$db->lastInsertId(), true);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    bakery_delivery_recovery_event($db, $case, 'reported', ['reason_code' => $case['failure_reason']]);
    return $case;
}

/** Apply an auditable manager decision to an existing recovery case. */
function bakery_delivery_recovery_apply(PDO $db, int $caseId, string $action, array $input): array
{
    if (!bakery_delivery_recovery_actor_may($action)) {
        throw new RuntimeException('Only managers can complete recovery or change billing handoff');
    }
    bakery_require_role(['administrator', 'manager']);
    if (!bakery_delivery_recovery_ready($db)) {
        throw new RuntimeException('Delivery recovery is not installed. Run local migrations first.');
    }
    $data = bakery_delivery_recovery_validate_input($action, $input);
    $actorId = (int)(bakery_current_user()['id'] ?? 0) ?: null;
    $case = bakery_delivery_recovery_case($db, $caseId);
    $from = (string)$case['workflow_state'];

    if ($action === 'update_handoffs') {
        $stmt = $db->prepare('UPDATE delivery_recovery_cases SET manager_note=?, customer_communication_status=?, customer_communication_note=?, billing_handoff=? WHERE id=?');
        $stmt->execute([$data['manager_note'], $data['communication_status'], $data['communication_note'], $data['billing_handoff'], $caseId]);
    } elseif ($action === 'reassign') {
        if (!bakery_delivery_recovery_transition_allowed($from, 'reassigned')) {
            throw new RuntimeException('This case cannot be reassigned from its current state');
        }
        $activeAssignmentId = (int)($case['active_assignment_id'] ?: $case['failed_assignment_id']);
        $source = $db->prepare('SELECT driver_id, delivery_status FROM daily_order_assignments WHERE id = ?');
        $source->execute([$activeAssignmentId]);
        $assignment = $source->fetch(PDO::FETCH_ASSOC);
        if (!$assignment || !in_array((string)$assignment['delivery_status'], ['failed', 'pending'], true)) {
            throw new RuntimeException('Only failed or scheduled-retry stops can be reassigned');
        }
        require_once __DIR__ . '/driver_assignments.php';
        bakery_driver_transfer_assignments($db, [(int)$case['daily_order_id']], $data['to_driver_id'], (string)$case['delivery_date'], (int)$assignment['driver_id']);
        $new = $db->prepare('SELECT id FROM daily_order_assignments WHERE daily_order_id = ? AND driver_id = ? AND delivery_date = ? LIMIT 1');
        $new->execute([(int)$case['daily_order_id'], $data['to_driver_id'], $case['delivery_date']]);
        $newAssignmentId = (int)$new->fetchColumn();
        $stmt = $db->prepare("UPDATE delivery_recovery_cases SET workflow_state='reassigned', manager_note=?, reassigned_to_driver_id=?, active_assignment_id=? WHERE id=?");
        $stmt->execute([$data['manager_note'], $data['to_driver_id'], $newAssignmentId ?: null, $caseId]);
    } else {
        $to = (string)$data['state'];
        if (!bakery_delivery_recovery_transition_allowed($from, $to)) {
            throw new RuntimeException('This recovery transition is not allowed');
        }
        if ($action === 'retry') {
            $activeAssignmentId = (int)($case['active_assignment_id'] ?: $case['failed_assignment_id']);
            $retryDate = substr($data['retry_at'], 0, 10);
            if ($retryDate !== (string)$case['delivery_date']) {
                throw new RuntimeException('Retries must stay on the selected operating date');
            }
            $time = substr($data['retry_at'], 11, 8);
            $update = $db->prepare("UPDATE daily_order_assignments SET delivery_status='pending', scheduled_delivery_time=? WHERE id=? AND delivery_status='failed'");
            $update->execute([$time, $activeAssignmentId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Only a failed stop can be scheduled for retry');
            }
            $stmt = $db->prepare("UPDATE delivery_recovery_cases SET workflow_state='retry_scheduled', manager_note=?, retry_at=? WHERE id=?");
            $stmt->execute([$data['manager_note'], $data['retry_at'], $caseId]);
        } elseif ($action === 'acknowledge') {
            $stmt = $db->prepare("UPDATE delivery_recovery_cases SET workflow_state='acknowledged', acknowledged_at=NOW(), acknowledged_by_user_id=? WHERE id=?");
            $stmt->execute([$actorId, $caseId]);
        } elseif ($action === 'resolve') {
            $stmt = $db->prepare("UPDATE delivery_recovery_cases SET workflow_state='resolved', manager_note=?, resolution_note=?, resolved_at=NOW(), resolved_by_user_id=? WHERE id=?");
            $stmt->execute([$data['manager_note'], $data['manager_note'], $actorId, $caseId]);
        } else { // close
            $stmt = $db->prepare("UPDATE delivery_recovery_cases SET workflow_state='closed', manager_note=?, resolution_note=?, closed_at=NOW(), closed_by_user_id=? WHERE id=?");
            $stmt->execute([$data['manager_note'], $data['manager_note'], $actorId, $caseId]);
        }
    }
    $updated = bakery_delivery_recovery_case($db, $caseId);
    bakery_delivery_recovery_event($db, $updated, $action, ['from_state' => $from, 'to_state' => $updated['workflow_state']]);
    return $updated;
}

/** @return list<array<string,mixed>> */
function bakery_delivery_recovery_cases_for_date(PDO $db, string $date): array
{
    if (!bakery_delivery_recovery_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT rc.*, c.name AS customer_name, od.driver_id AS active_driver_id, d.name AS active_driver_name
         FROM delivery_recovery_cases rc
         JOIN daily_orders od ON od.id = rc.daily_order_id
         JOIN customers c ON c.id = od.customer_id
         LEFT JOIN daily_order_assignments doa ON doa.id = rc.active_assignment_id
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE rc.delivery_date = ?
         ORDER BY FIELD(rc.workflow_state, "open", "acknowledged", "retry_scheduled", "reassigned", "resolved", "closed"), rc.updated_at DESC'
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Failed assignments that still need a manager recovery case. */
function bakery_delivery_recovery_untriaged_failed_stops(PDO $db, string $date): array
{
    if (!bakery_delivery_recovery_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT doa.id AS assignment_id, doa.daily_order_id, c.name AS customer_name, d.name AS driver_name
         FROM daily_order_assignments doa
         JOIN daily_orders od ON od.id = doa.daily_order_id
         JOIN customers c ON c.id = od.customer_id
         LEFT JOIN drivers d ON d.id = doa.driver_id
         LEFT JOIN delivery_recovery_cases rc ON rc.failed_assignment_id = doa.id
         WHERE doa.delivery_date = ? AND doa.delivery_status = 'failed' AND rc.id IS NULL
         ORDER BY c.name"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
