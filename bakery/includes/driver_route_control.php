<?php
/**
 * Driver control of their own route for today.
 *
 * Mutations reuse the manager helpers (unskip, transfer, pickup load, COD
 * turn-in, closeout reopen). This file only adds the driver scope, the
 * same-day guard, and an office log with before and after. It does not
 * text or email anyone.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/delivery_skip.php';
require_once __DIR__ . '/driver_assignments.php';
require_once __DIR__ . '/cod_turnins.php';

/**
 * Whole-number quantity, same rule as the delivery sheet parser.
 * Blank is rejected. Zero is allowed. Negatives and junk are rejected.
 */
function bakery_driver_route_parse_count(string $raw): int
{
    $text = trim($raw);
    if ($text === '' || preg_match('/^(?:0|[1-9]\d*)$/', $text) !== 1) {
        throw new InvalidArgumentException(
            function_exists('bakery_t') ? bakery_t('driver.qty_invalid') : 'Enter a whole number, or leave nothing blank.'
        );
    }
    return (int)$text;
}

function bakery_driver_route_parse_money(string $raw): float
{
    $text = trim($raw);
    if ($text === '' || !is_numeric($text)) {
        throw new InvalidArgumentException(
            function_exists('bakery_t') ? bakery_t('driver.turnin_invalid') : 'Enter the cash turn-in amount.'
        );
    }
    $amount = round((float)$text, 2);
    if ($amount < 0) {
        throw new InvalidArgumentException(
            function_exists('bakery_t') ? bakery_t('driver.turnin_invalid') : 'Enter the cash turn-in amount.'
        );
    }
    return $amount;
}

/**
 * @return array{user:array<string,mixed>,driver_id:int,date:string}
 */
function bakery_driver_route_control_actor(PDO $db, ?string $postedDate = null): array
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    if (!$user || !bakery_is_driver_route_role($user['role_slug'] ?? '')) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_own_route') : 'You can only change your own route.'
        );
    }
    $today = date('Y-m-d');
    if ($postedDate !== null && $postedDate !== $today) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_today_only') : 'You can only change today\'s route.'
        );
    }
    $driverId = bakery_route_worker_driver_id($db, $user, $today);
    if ($driverId <= 0) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_own_route') : 'You can only change your own route.'
        );
    }
    return ['user' => $user, 'driver_id' => $driverId, 'date' => $today];
}

function bakery_driver_route_control_is_closed(PDO $db, int $driverId, string $date): bool
{
    if ($driverId <= 0 || !function_exists('table_exists') || !table_exists($db, 'driver_loads')) {
        return false;
    }
    $stmt = $db->prepare('SELECT status FROM driver_loads WHERE driver_id = ? AND delivery_date = ? LIMIT 1');
    $stmt->execute([$driverId, $date]);
    return (string)($stmt->fetchColumn() ?: '') === 'reconciled';
}

/**
 * @return array<string,mixed>
 */
function bakery_driver_route_control_stop(PDO $db, int $dailyOrderId, bool $allowClosed = false): array
{
    $actor = bakery_driver_route_control_actor($db);
    if ($dailyOrderId <= 0) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_own_route') : 'You can only change your own route.'
        );
    }
    $stmt = $db->prepare(
        'SELECT doa.id AS assignment_id, doa.daily_order_id, doa.driver_id, doa.delivery_date,
                doa.delivery_status, do.customer_id, do.order_date, c.name AS customer_name
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         JOIN customers c ON c.id = do.customer_id
         WHERE doa.daily_order_id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_own_route') : 'You can only change your own route.'
        );
    }
    if ((string)$row['delivery_date'] !== $actor['date'] || (string)$row['order_date'] !== $actor['date']) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_today_only') : 'You can only change today\'s route.'
        );
    }
    if ((int)$row['driver_id'] !== $actor['driver_id']) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_own_route') : 'You can only change your own route.'
        );
    }
    if (!$allowClosed && bakery_driver_route_control_is_closed($db, $actor['driver_id'], $actor['date'])) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_closed') : 'Reopen the route before changing stops.'
        );
    }
    $row['actor'] = $actor;
    return $row;
}

/**
 * @param array<string,mixed> $before
 * @param array<string,mixed> $after
 * @param array<string,mixed> $extra
 */
function bakery_driver_route_control_log(
    PDO $db,
    string $eventType,
    string $summary,
    string $date,
    int $driverId,
    array $before,
    array $after,
    array $extra = []
): void {
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    bakery_record_operational_event($db, $eventType, $summary, [
        'operational_date' => $date,
        'driver_id' => $driverId,
        'customer_id' => $extra['customer_id'] ?? null,
        'daily_order_id' => $extra['daily_order_id'] ?? null,
        'assignment_id' => $extra['assignment_id'] ?? null,
        'metadata' => [
            'before' => $before,
            'after' => $after,
            'actor_user_id' => (int)($user['id'] ?? 0),
            'office_attention' => !empty($extra['office_attention']),
        ],
    ]);
}

function bakery_driver_route_control_unskip(PDO $db, int $dailyOrderId): void
{
    $stop = bakery_driver_route_control_stop($db, $dailyOrderId);
    if ((string)$stop['delivery_status'] !== 'cancelled') {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.unskip_error') : 'Only skipped stops can be restored.'
        );
    }
    bakery_unskip_delivery_stop($db, $dailyOrderId);
}

function bakery_driver_route_control_reopen(PDO $db, string $date, string $confirm): void
{
    $actor = bakery_driver_route_control_actor($db, $date);
    if ($confirm !== '1') {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.reopen_confirm_required') : 'Confirm before reopening the route.'
        );
    }
    if (!bakery_driver_route_control_is_closed($db, $actor['driver_id'], $actor['date'])) {
        throw new RuntimeException('This route is not closed out.');
    }
    bakery_inventory_reopen_driver_closeout($db, $actor['date'], $actor['driver_id']);
    bakery_driver_route_control_log(
        $db,
        'driver_route_reopened',
        'Driver reopened today\'s route closeout',
        $actor['date'],
        $actor['driver_id'],
        ['status' => 'reconciled'],
        ['status' => 'loaded']
    );
}

function bakery_driver_route_control_expected_cash(PDO $db, int $driverId, string $date): float
{
    if (!function_exists('column_exists') || !column_exists($db, 'daily_orders', 'amount_collected')) {
        return 0.0;
    }
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(do.amount_collected), 0)
         FROM daily_orders do
         JOIN daily_order_assignments doa ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
         WHERE doa.driver_id = ? AND doa.delivery_date = ? AND doa.delivery_status = 'delivered'"
    );
    $stmt->execute([$driverId, $date]);
    return round((float)$stmt->fetchColumn(), 2);
}

function bakery_driver_route_control_turnin(PDO $db, string $date, string $rawAmount): array
{
    $actor = bakery_driver_route_control_actor($db, $date);
    $amount = bakery_driver_route_parse_money($rawAmount);
    $before = bakery_cod_turnin_get($db, $actor['driver_id'], $actor['date']);
    $expected = bakery_driver_route_control_expected_cash($db, $actor['driver_id'], $actor['date']);
    $difference = round($amount - $expected, 2);
    bakery_cod_turnin_record($db, $actor['driver_id'], $actor['date'], $amount, (int)($actor['user']['id'] ?? 0), [
        'before' => ['amount' => $before],
        'after' => ['amount' => $amount],
        'expected_cash' => $expected,
        'difference' => $difference,
        'office_attention' => abs($difference) >= 0.01,
    ]);
    return [
        'amount' => $amount,
        'expected' => $expected,
        'difference' => $difference,
        'office_attention' => abs($difference) >= 0.01,
    ];
}

/**
 * @param array<int|string,int|string> $quantities
 */
function bakery_driver_route_control_save_load(PDO $db, string $date, array $quantities): void
{
    $actor = bakery_driver_route_control_actor($db, $date);
    if (bakery_driver_route_control_is_closed($db, $actor['driver_id'], $actor['date'])) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.control_closed') : 'Reopen the route before changing the pickup load.'
        );
    }
    $parsed = [];
    foreach ($quantities as $productId => $raw) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            continue;
        }
        $parsed[$productId] = bakery_driver_route_parse_count((string)$raw);
    }
    $before = [];
    if (table_exists($db, 'driver_loads')) {
        $stmt = $db->prepare(
            'SELECT li.product_id, li.loaded_quantity
             FROM driver_load_items li
             JOIN driver_loads dl ON dl.id = li.driver_load_id
             WHERE dl.driver_id = ? AND dl.delivery_date = ?'
        );
        $stmt->execute([$actor['driver_id'], $actor['date']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $before[(int)$row['product_id']] = (int)$row['loaded_quantity'];
        }
    }
    bakery_inventory_save_driver_load(
        $db,
        $actor['date'],
        $actor['driver_id'],
        $parsed,
        'Driver edited today\'s pickup load'
    );
    bakery_driver_route_control_log(
        $db,
        'driver_load_edited',
        'Driver edited today\'s pickup load',
        $actor['date'],
        $actor['driver_id'],
        ['loaded' => $before],
        ['loaded' => $parsed]
    );
}

function bakery_driver_route_control_move(PDO $db, int $dailyOrderId, int $toDriverId): void
{
    $stop = bakery_driver_route_control_stop($db, $dailyOrderId);
    if ((string)$stop['delivery_status'] === 'delivered') {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.move_delivered') : 'Delivered stops stay on your route.'
        );
    }
    if (in_array((string)$stop['delivery_status'], ['in_transit', 'failed'], true)) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.move_blocked') : 'This stop cannot be moved.'
        );
    }
    if ($toDriverId <= 0 || $toDriverId === (int)$stop['actor']['driver_id']) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.move_pick') : 'Pick another driver.'
        );
    }
    $driverStmt = $db->prepare('SELECT id, name, archived FROM drivers WHERE id = ? LIMIT 1');
    $driverStmt->execute([$toDriverId]);
    $target = $driverStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target || (int)($target['archived'] ?? 0) === 1) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.move_pick') : 'Pick another driver.'
        );
    }
    bakery_driver_transfer_assignments(
        $db,
        [$dailyOrderId],
        $toDriverId,
        (string)$stop['actor']['date'],
        (int)$stop['actor']['driver_id'],
        true
    );
    bakery_driver_route_control_log(
        $db,
        'driver_stop_moved',
        'Driver moved ' . (string)$stop['customer_name'] . ' to ' . (string)$target['name'],
        (string)$stop['actor']['date'],
        (int)$stop['actor']['driver_id'],
        [
            'driver_id' => (int)$stop['driver_id'],
            'delivery_status' => (string)$stop['delivery_status'],
        ],
        [
            'driver_id' => $toDriverId,
            'delivery_status' => 'pending',
        ],
        [
            'daily_order_id' => $dailyOrderId,
            'customer_id' => (int)$stop['customer_id'],
            'assignment_id' => (int)$stop['assignment_id'],
            'office_attention' => true,
        ]
    );
}

/**
 * @param array<int|string,int|string> $quantities
 */
function bakery_driver_route_control_save_quantities(PDO $db, int $dailyOrderId, array $quantities): void
{
    $stop = bakery_driver_route_control_stop($db, $dailyOrderId);
    if (in_array((string)$stop['delivery_status'], ['delivered', 'cancelled', 'failed'], true)) {
        throw new RuntimeException(
            function_exists('bakery_t') ? bakery_t('driver.qty_delivered') : 'Change quantities before the stop is delivered.'
        );
    }
    if (!function_exists('bakery_audit_daily_line_lookup')) {
        require_once __DIR__ . '/audit_log.php';
    }
    if (!function_exists('bakery_customer_update_daily_total')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }
    $before = [];
    $after = [];
    foreach ($quantities as $productId => $raw) {
        $productId = (int)$productId;
        if ($productId <= 0) {
            continue;
        }
        $quantity = bakery_driver_route_parse_count((string)$raw);
        $existing = bakery_audit_daily_line_lookup($db, $dailyOrderId, $productId);
        $oldQty = $existing ? (int)$existing['quantity'] : 0;
        $before[$productId] = $oldQty;
        $after[$productId] = $quantity;
        if ($quantity > 0) {
            $unitPrice = $existing ? (float)$existing['unit_price'] : 0.0;
            if (!$existing && function_exists('bakery_resolve_customer_price')) {
                $customerStmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
                $customerStmt->execute([(int)$stop['customer_id']]);
                $customer = $customerStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $productStmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
                $productStmt->execute([$productId]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($customer && $product) {
                    $unitPrice = (float)bakery_resolve_customer_price($db, $customer, $product);
                }
            }
            $lineTotal = round($quantity * $unitPrice, 2);
            if ($existing) {
                $db->prepare('UPDATE daily_order_items SET quantity = ?, line_total = ? WHERE id = ?')
                    ->execute([$quantity, $lineTotal, (int)$existing['id']]);
            } else {
                $db->prepare(
                    'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
            }
        } elseif ($existing) {
            $db->prepare('DELETE FROM daily_order_items WHERE id = ?')->execute([(int)$existing['id']]);
        }
        $updated = bakery_audit_daily_line_lookup($db, $dailyOrderId, $productId);
        bakery_audit_daily_line_diff($db, $existing, $updated);
    }
    if (function_exists('bakery_customer_update_daily_total')) {
        bakery_customer_update_daily_total($db, $dailyOrderId);
    }
    bakery_driver_route_control_log(
        $db,
        'driver_stop_quantity_changed',
        'Driver changed quantities for ' . (string)$stop['customer_name'],
        (string)$stop['actor']['date'],
        (int)$stop['actor']['driver_id'],
        ['quantities' => $before],
        ['quantities' => $after],
        [
            'daily_order_id' => $dailyOrderId,
            'customer_id' => (int)$stop['customer_id'],
            'assignment_id' => (int)$stop['assignment_id'],
        ]
    );
}

/**
 * @return list<array{id:int,name:string}>
 */
function bakery_driver_route_control_other_drivers(PDO $db, int $driverId): array
{
    $sql = 'SELECT id, name FROM drivers WHERE id <> ?';
    if (function_exists('column_exists') && column_exists($db, 'drivers', 'archived')) {
        $sql .= ' AND COALESCE(archived, 0) = 0';
    }
    $sql .= ' ORDER BY name';
    $stmt = $db->prepare($sql);
    $stmt->execute([$driverId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    return $out;
}
