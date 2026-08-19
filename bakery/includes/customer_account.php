<?php
/**
 * Customer account preferences — portal self-service for operational contact info.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_order_mutations.php';

/** Ensure account preference columns exist (idempotent). */
function bakery_customer_account_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    bakery_customer_order_ensure_schema($db);

    if (!table_exists($db, 'customers')) {
        return;
    }

    $columns = [
        'delivery_instructions' => "ALTER TABLE customers ADD COLUMN delivery_instructions TEXT NULL COMMENT 'Customer-facing delivery/receiving notes for drivers'",
        'ordering_contact_name' => 'ALTER TABLE customers ADD COLUMN ordering_contact_name VARCHAR(100) NULL DEFAULT NULL',
        'ordering_contact_phone' => 'ALTER TABLE customers ADD COLUMN ordering_contact_phone VARCHAR(20) NULL DEFAULT NULL',
        'ordering_contact_email' => 'ALTER TABLE customers ADD COLUMN ordering_contact_email VARCHAR(100) NULL DEFAULT NULL',
        'delivery_contact_name' => "ALTER TABLE customers ADD COLUMN delivery_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Day-of-delivery contact'",
        'delivery_contact_phone' => "ALTER TABLE customers ADD COLUMN delivery_contact_phone VARCHAR(20) NULL DEFAULT NULL COMMENT 'Day-of-delivery phone'",
        'billing_contact_name' => "ALTER TABLE customers ADD COLUMN billing_contact_name VARCHAR(100) NULL DEFAULT NULL COMMENT 'Accounts payable contact'",
        'billing_contact_email' => 'ALTER TABLE customers ADD COLUMN billing_contact_email VARCHAR(100) NULL DEFAULT NULL',
        'billing_contact_phone' => 'ALTER TABLE customers ADD COLUMN billing_contact_phone VARCHAR(20) NULL DEFAULT NULL',
    ];

    foreach ($columns as $col => $sql) {
        if (!bakery_portal_column_exists($db, 'customers', $col)) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                // Idempotent — ignore duplicate column errors.
            }
        }
    }

    $done = true;
}

/** Sections customers may edit directly through the portal. */
function bakery_customer_account_editable_sections(): array
{
    return [
        'delivery' => [
            'delivery_instructions',
            'deliver_after',
            'deliver_by',
            'delivery_contact_name',
            'delivery_contact_phone',
        ],
        'ordering' => [
            'ordering_contact_name',
            'ordering_contact_phone',
            'ordering_contact_email',
        ],
        'billing' => [
            'billing_contact_name',
            'billing_contact_email',
            'billing_contact_phone',
        ],
    ];
}

/** Fields that require staff approval before changing operational data. */
function bakery_customer_account_request_fields(): array
{
    return ['address', 'zone', 'name'];
}

/**
 * Load the customer account profile visible in the portal.
 *
 * @return array<string,mixed>
 */
function bakery_customer_account_load(PDO $db, int $customerId): array
{
    bakery_customer_account_ensure_schema($db);

    $stmt = $db->prepare(
        'SELECT id, name, address, phone, email, zone,
                deliver_after, deliver_by,
                delivery_instructions,
                delivery_contact_name, delivery_contact_phone,
                ordering_contact_name, ordering_contact_phone, ordering_contact_email,
                billing_contact_name, billing_contact_email, billing_contact_phone
         FROM customers
         WHERE id = ? AND portal_enabled = 1 AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Customer not found');
    }

    $row['receiving_hours_label'] = bakery_customer_account_receiving_hours_label(
        $row['deliver_after'] ?? null,
        $row['deliver_by'] ?? null
    );
    $row['multiple_users_supported'] = false;

    return $row;
}

function bakery_customer_account_receiving_hours_label($deliverAfter, $deliverBy): string
{
    $after = bakery_customer_account_format_time($deliverAfter);
    $by = bakery_customer_account_format_time($deliverBy);
    if ($after !== '' && $by !== '') {
        return $after . ' – ' . $by;
    }
    if ($after !== '') {
        return bakery_t('portal.account_receiving_after_only', ['time' => $after]);
    }
    if ($by !== '') {
        return bakery_t('portal.account_receiving_by_only', ['time' => $by]);
    }
    return '';
}

function bakery_customer_account_format_time($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('g:i A', $ts) : $value;
}

function bakery_customer_account_normalize_time($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) {
        return strlen($value) === 5 ? $value . ':00' : $value;
    }
    $ts = strtotime($value);
    return $ts ? date('H:i:s', $ts) : null;
}

function bakery_customer_account_normalize_phone($value): string
{
    return trim((string)$value);
}

function bakery_customer_account_normalize_email($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(bakery_t('portal.account_invalid_email'));
    }
    return $value;
}

function bakery_customer_account_sanitize_text($value, int $maxLen): string
{
    $value = trim((string)$value);
    if (strlen($value) > $maxLen) {
        $value = substr($value, 0, $maxLen);
    }
    return $value;
}

/**
 * Update one editable account section for the authenticated customer.
 *
 * @param array<string,mixed> $input
 * @return array{section:string,changes:array<int,array{field:string,before:mixed,after:mixed}>}
 */
function bakery_customer_account_update_section(PDO $db, array $customer, string $section, array $input): array
{
    bakery_customer_account_ensure_schema($db);

    $sections = bakery_customer_account_editable_sections();
    if (!isset($sections[$section])) {
        throw new InvalidArgumentException('Invalid section');
    }

    $customerId = (int)$customer['id'];
    $current = bakery_customer_account_load($db, $customerId);
    $allowed = $sections[$section];
    $updates = [];
    $changes = [];

    foreach ($allowed as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }

        $raw = $input[$field];
        switch ($field) {
            case 'delivery_instructions':
                $newValue = bakery_customer_account_sanitize_text($raw, 4000);
                break;
            case 'deliver_after':
            case 'deliver_by':
                $newValue = bakery_customer_account_normalize_time($raw);
                break;
            case 'delivery_contact_name':
            case 'ordering_contact_name':
            case 'billing_contact_name':
                $newValue = bakery_customer_account_sanitize_text($raw, 100);
                break;
            case 'delivery_contact_phone':
            case 'ordering_contact_phone':
            case 'billing_contact_phone':
                $newValue = bakery_customer_account_normalize_phone($raw);
                break;
            case 'ordering_contact_email':
            case 'billing_contact_email':
                $newValue = bakery_customer_account_normalize_email($raw);
                break;
            default:
                continue 2;
        }

        $oldValue = $current[$field] ?? null;
        $oldNorm = $oldValue === null ? '' : (string)$oldValue;
        $newNorm = $newValue === null ? '' : (string)$newValue;
        if ($oldNorm === $newNorm) {
            continue;
        }

        $updates[$field] = $newValue === '' ? null : $newValue;
        $changes[] = [
            'field' => $field,
            'before' => $oldValue,
            'after' => $updates[$field],
        ];
    }

    if (!$updates) {
        return ['section' => $section, 'changes' => []];
    }

    $setParts = [];
    $params = [];
    foreach ($updates as $field => $value) {
        $setParts[] = '`' . $field . '` = ?';
        $params[] = $value;
    }
    $params[] = $customerId;

    $db->prepare('UPDATE customers SET ' . implode(', ', $setParts) . ' WHERE id = ?')
        ->execute($params);

    bakery_customer_account_record_update($db, $customer, $section, $changes);

    return ['section' => $section, 'changes' => $changes];
}

/**
 * @param array<int,array{field:string,before:mixed,after:mixed}> $changes
 */
function bakery_customer_account_record_update(PDO $db, array $customer, string $section, array $changes): void
{
    if (!$changes) {
        return;
    }

    $customerId = (int)$customer['id'];
    $fieldLabels = [];
    $metadataChanges = [];

    foreach ($changes as $change) {
        $fieldLabels[] = bakery_customer_account_field_label($change['field']);
        $metadataChanges[] = [
            'field' => $change['field'],
            'before' => bakery_customer_account_audit_value($change['before']),
            'after' => bakery_customer_account_audit_value($change['after']),
        ];
    }

    $summary = $customer['name'] . ' updated ' . $section . ' account info'
        . ' (' . implode(', ', $fieldLabels) . ')';

    bakery_portal_record_event(
        $db,
        BAKERY_OP_PORTAL_ACCOUNT_UPDATED,
        $summary,
        $customerId,
        [
            'metadata' => [
                'section' => $section,
                'changes' => $metadataChanges,
            ],
        ]
    );
}

function bakery_customer_account_field_label(string $field): string
{
    $key = 'portal.account_field_' . $field;
    $label = bakery_t($key);
    return $label !== $key ? $label : $field;
}

function bakery_customer_account_audit_value($value): string
{
    if ($value === null || $value === '') {
        return '(empty)';
    }
    $text = trim((string)$value);
    if (strlen($text) > 120) {
        return substr($text, 0, 117) . '...';
    }
    return $text;
}

/**
 * Submit a request for staff to change consequential account data.
 *
 * @return array{request_id:int,field:string}
 */
function bakery_customer_account_request_change(
    PDO $db,
    array $customer,
    string $field,
    string $requestedValue,
    string $message = ''
): array {
    bakery_customer_account_ensure_schema($db);

    $allowed = bakery_customer_account_request_fields();
    if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException('This field cannot be changed through a request');
    }

    $requestedValue = bakery_customer_account_sanitize_text($requestedValue, 500);
    if ($requestedValue === '') {
        throw new InvalidArgumentException(bakery_t('portal.account_request_value_required'));
    }

    $message = trim($message);
    if ($message === '') {
        $message = bakery_t('portal.account_request_default_message', [
            'field' => bakery_customer_account_field_label($field),
        ]);
    }

    $customerId = (int)$customer['id'];
    $current = bakery_customer_account_load($db, $customerId);
    $today = date('Y-m-d');

    $metadata = [
        'field' => $field,
        'current_value' => bakery_customer_account_audit_value($current[$field] ?? null),
        'requested_value' => $requestedValue,
        'requested_at' => date('c'),
    ];

    $stmt = $db->prepare(
        'INSERT INTO customer_change_requests
         (customer_id, order_date, daily_order_id, request_type, message, status, metadata)
         VALUES (?, ?, NULL, \'account_change\', ?, \'pending\', ?)'
    );
    $stmt->execute([
        $customerId,
        $today,
        $message,
        json_encode($metadata, JSON_UNESCAPED_UNICODE),
    ]);
    $requestId = (int)$db->lastInsertId();

    bakery_portal_record_event(
        $db,
        BAKERY_OP_PORTAL_ACCOUNT_CHANGE_REQUESTED,
        $customer['name'] . ' requested a change to ' . bakery_customer_account_field_label($field),
        $customerId,
        [
            'operational_date' => $today,
            'metadata' => [
                'request_id' => $requestId,
                'field' => $field,
                'requested_value' => $requestedValue,
            ],
        ]
    );

    return ['request_id' => $requestId, 'field' => $field];
}

/**
 * Format receiving hours for driver display.
 */
function bakery_driver_receiving_hours_label(array $stop): string
{
    return bakery_customer_account_receiving_hours_label(
        $stop['deliver_after'] ?? null,
        $stop['deliver_by'] ?? null
    );
}

/**
 * Preferred phone for driver call link — day-of-delivery contact first.
 */
function bakery_driver_stop_phone(array $stop): string
{
    $deliveryPhone = trim((string)($stop['delivery_contact_phone'] ?? ''));
    if ($deliveryPhone !== '') {
        return $deliveryPhone;
    }
    return trim((string)($stop['customer_phone'] ?? ''));
}

/**
 * Combine customer delivery instructions with operational order/assignment notes.
 */
function bakery_driver_stop_notes(array $stop): string
{
    $parts = [];

    $instructions = trim((string)($stop['delivery_instructions'] ?? ''));
    if ($instructions !== '') {
        $parts[] = $instructions;
    }

    $orderNotes = trim((string)($stop['order_notes'] ?? ''));
    $assignmentNotes = trim((string)($stop['assignment_notes'] ?? ''));
    if ($orderNotes !== '') {
        $parts[] = $orderNotes;
    }
    if ($assignmentNotes !== '' && $assignmentNotes !== $orderNotes) {
        $parts[] = $assignmentNotes;
    }

    return implode("\n", $parts);
}
