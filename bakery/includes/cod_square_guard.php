<?php
/**
 * Stop a Square-paid stop from also taking COD cash.
 *
 * Prepaid means daily_orders.square_invoice_id is set, or a tender is
 * recorded (square_status PAID / PARTIALLY_PAID, or square_paid_at).
 * A manager can allow COD afterward; the reason is stored and logged.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';

function bakery_stop_square_prepaid(array $row): bool
{
    if (trim((string)($row['square_invoice_id'] ?? '')) !== '') {
        return true;
    }
    $status = strtoupper(trim((string)($row['square_status'] ?? '')));
    if ($status === 'PAID' || $status === 'PARTIALLY_PAID') {
        return true;
    }
    return trim((string)($row['square_paid_at'] ?? '')) !== '';
}

function bakery_cod_cash_blocked(array $row): bool
{
    if (!bakery_stop_square_prepaid($row)) {
        return false;
    }
    return trim((string)($row['cod_square_override_reason'] ?? '')) === '';
}

function bakery_cod_user_can_override(?array $user): bool
{
    $role = (string)($user['role_slug'] ?? '');
    return $role === 'administrator' || $role === 'manager';
}

/**
 * @param array{id?:int,role_slug?:string}|null $user
 */
function bakery_cod_record_square_override(PDO $db, int $orderId, string $reason, ?array $user): void
{
    $reason = trim($reason);
    if ($orderId <= 0) {
        throw new RuntimeException('Order is required');
    }
    if ($reason === '') {
        throw new RuntimeException('A reason is required to collect COD on a Square prepaid stop');
    }
    if (strlen($reason) > 255) {
        $reason = substr($reason, 0, 255);
    }
    if (!bakery_cod_user_can_override($user)) {
        throw new RuntimeException('Only a manager can allow COD cash on a Square prepaid stop');
    }
    if (!function_exists('column_exists') || !column_exists($db, 'daily_orders', 'cod_square_override_reason')) {
        throw new RuntimeException('COD override is not installed');
    }
    $userId = (int)($user['id'] ?? 0);
    $stmt = $db->prepare(
        'UPDATE daily_orders
         SET cod_square_override_reason = ?, cod_square_override_user_id = ?, cod_square_override_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$reason, $userId > 0 ? $userId : null, $orderId]);
    bakery_record_operational_event($db, 'cod_square_override', 'COD Square override: ' . $reason, [
        'daily_order_id' => $orderId,
        'actor_user_id' => $userId > 0 ? $userId : null,
        'actor_role' => (string)($user['role_slug'] ?? ''),
        'metadata' => [
            'reason' => $reason,
        ],
    ]);
}

/**
 * Square and override columns for one order. Missing columns stay blank.
 *
 * @return array{square_invoice_id:string,square_status:string,square_paid_at:string,cod_square_override_reason:string}
 */
function bakery_cod_square_guard_row(PDO $db, int $orderId): array
{
    $blank = [
        'square_invoice_id' => '',
        'square_status' => '',
        'square_paid_at' => '',
        'cod_square_override_reason' => '',
    ];
    if ($orderId <= 0 || !function_exists('column_exists') || !column_exists($db, 'daily_orders', 'square_invoice_id')) {
        return $blank;
    }
    $overrideSql = column_exists($db, 'daily_orders', 'cod_square_override_reason')
        ? 'cod_square_override_reason'
        : 'NULL AS cod_square_override_reason';
    $stmt = $db->prepare(
        "SELECT square_invoice_id, square_status, square_paid_at, {$overrideSql}
         FROM daily_orders WHERE id = ?"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $blank;
    }
    return [
        'square_invoice_id' => trim((string)($row['square_invoice_id'] ?? '')),
        'square_status' => trim((string)($row['square_status'] ?? '')),
        'square_paid_at' => trim((string)($row['square_paid_at'] ?? '')),
        'cod_square_override_reason' => trim((string)($row['cod_square_override_reason'] ?? '')),
    ];
}
