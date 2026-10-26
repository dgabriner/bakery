<?php
/**
 * Daily Orders action handlers — pure functions for page and API dispatch.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/order_reprice.php';
require_once __DIR__ . '/daily_order_skip.php';

function bakery_order_line_no_charge_ready(PDO $db): bool
{
    return function_exists('column_exists') && column_exists($db, 'daily_order_items', 'is_no_charge');
}

function bakery_order_line_is_no_charge(array $item): bool
{
    return (int)($item['is_no_charge'] ?? 0) === 1;
}

/** @return list<string> */
function bakery_no_charge_reasons(): array
{
    return ['comp', 'sample', 'replacement', 'donation'];
}

function bakery_no_charge_normalize_reason($reason): ?string
{
    $reason = strtolower(trim((string)$reason));
    if ($reason === '') {
        return null;
    }
    if (!in_array($reason, bakery_no_charge_reasons(), true)) {
        throw new InvalidArgumentException('Unknown no-charge reason');
    }
    return $reason;
}

function bakery_order_line_no_charge_select(PDO $db): string
{
    if (bakery_order_line_no_charge_ready($db)) {
        return 'doi.is_no_charge, doi.no_charge_reason';
    }
    return '0 AS is_no_charge, NULL AS no_charge_reason';
}

function bakery_daily_orders_update_order_total(PDO $db, int $orderId): void
{
    bakery_daily_order_recompute_total($db, $orderId);
}

function bakery_daily_orders_validate_date(string $date): void
{
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new Exception('Invalid order date');
    }
}

function bakery_daily_orders_action_preview_generate(PDO $db, array $input, ?array $user = null): array
{
    $date = $input['date'] ?? '';
    bakery_daily_orders_validate_date($date);

    return [
        'success' => true,
        'preview' => bakery_demand_review_preview_generate($db, $date),
    ];
}

function bakery_daily_orders_action_generate_from_standing(PDO $db, array $input, ?array $user = null): array
{
    $date = $input['date'] ?? '';
    $overwriteChanged = ($input['overwrite_changed'] ?? '0') === '1';
    bakery_daily_orders_validate_date($date);

    try {
        $result = bakery_generate_daily_orders_from_standing($db, $date, [
            'overwrite_changed' => $overwriteChanged,
        ]);

        return array_merge(['success' => true], $result);
    } catch (Exception $e) {
        error_log('Error generating orders: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'Failed to generate orders: ' . bakery_error_message_for_user($e),
        ];
    }
}

function bakery_daily_orders_action_generate_week_from_standing(PDO $db, array $input, ?array $user = null): array
{
    $date = $input['date'] ?? '';
    $overwriteChanged = ($input['overwrite_changed'] ?? '0') === '1';
    bakery_daily_orders_validate_date($date);

    try {
        $result = bakery_generate_daily_orders_week($db, $date, [
            'overwrite_changed' => $overwriteChanged,
        ]);

        return array_merge(['success' => true], $result);
    } catch (Exception $e) {
        error_log('Error generating week orders: ' . $e->getMessage());

        return [
            'success' => false,
            'error' => 'Failed to generate week: ' . bakery_error_message_for_user($e),
        ];
    }
}

function bakery_daily_orders_action_create_dated_order(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    $result = bakery_staff_create_dated_order($db, $customerId, $date);

    return [
        'success' => true,
        'daily_order_id' => $result['daily_order_id'],
        'created' => $result['created'],
        'item_count' => $result['item_count'],
        'message' => $result['created']
            ? 'Dated order created for ' . $result['customer_name']
            : 'That customer already has a dated order. Opening it now.',
    ];
}

function bakery_daily_orders_action_set_dated_quantity(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);
    $result = bakery_demand_set_dated_quantity($db, $customerId, $date, $productId, $quantity);

    return ['success' => true] + $result;
}

function bakery_daily_orders_action_copy_prior_to_dated(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    $result = bakery_demand_copy_prior_quantities_to_dated($db, $customerId, $date);

    return ['success' => true] + $result;
}

function bakery_daily_orders_action_apply_standing_to_dated(PDO $db, array $input, ?array $user = null): array
{
    $customerId = (int)($input['customer_id'] ?? 0);
    $date = (string)($input['date'] ?? '');
    $result = bakery_demand_apply_standing_to_dated($db, $customerId, $date);

    return ['success' => true] + $result;
}

function bakery_daily_orders_action_update_quantity(PDO $db, array $input, ?array $user = null): array
{
    $itemId = (int)($input['item_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);

    $stmt = $db->prepare(
        'SELECT doi.daily_order_id, doi.quantity, doi.unit_price, doi.product_id, p.name AS product_name,
                do.order_date, do.customer_id, c.name AS customer_name
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         JOIN products p ON p.id = doi.product_id
         WHERE doi.id = ?'
    );
    $stmt->execute([$itemId]);
    $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);

    $dailyOrderId = $itemRow ? (int)$itemRow['daily_order_id'] : null;
    $oldQty = $itemRow ? (int)$itemRow['quantity'] : null;
    $auditBefore = $itemRow ? [
        'id' => $itemId,
        'quantity' => $itemRow['quantity'],
        'unit_price' => $itemRow['unit_price'],
    ] : null;

    if ($quantity <= 0) {
        // A removed dated line stays as quantity 0. Deleting it looks like a
        // missing standing line, and the next sync copies standing back.
        $stmt = $db->prepare('
            UPDATE daily_order_items
            SET quantity = 0, line_total = 0
            WHERE id = ?
        ');
        $stmt->execute([$itemId]);
        bakery_audit_daily_line_diff($db, $auditBefore, null);
    } else {
        $stmt = $db->prepare('
            UPDATE daily_order_items 
            SET quantity = ?, line_total = quantity * unit_price
            WHERE id = ?
        ');
        $stmt->execute([$quantity, $itemId]);
        bakery_audit_daily_line_diff($db, $auditBefore, [
            'id' => $itemId,
            'quantity' => $quantity,
            'unit_price' => $itemRow['unit_price'] ?? null,
        ]);
    }

    if ($dailyOrderId) {
        bakery_daily_orders_update_order_total($db, $dailyOrderId);
        if ($quantity <= 0) {
            bakery_daily_order_block_standing_fill_if_empty($db, (int)$dailyOrderId);
        } elseif (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
            bakery_daily_order_set_standing_fill_blocked($db, (int)$dailyOrderId, false);
        }
    }

    if ($itemRow && $oldQty !== null && $oldQty !== $quantity) {
        $label = $quantity <= 0 ? 'removed' : 'changed';
        bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
            'Order quantity ' . $label . ' for ' . $itemRow['customer_name'] . ' - ' . $itemRow['product_name'], [
            'operational_date' => $itemRow['order_date'],
            'customer_id' => (int)$itemRow['customer_id'],
            'daily_order_id' => (int)$itemRow['daily_order_id'],
            'product_id' => (int)$itemRow['product_id'],
            'metadata' => [
                'product_name' => $itemRow['product_name'],
                'old_quantity' => $oldQty,
                'new_quantity' => max(0, $quantity),
            ],
        ]);
    }

    return ['success' => true];
}

function bakery_daily_orders_action_update_status(PDO $db, array $input, ?array $user = null): array
{
    $orderId = (int)($input['order_id'] ?? 0);
    $status = (string)($input['status'] ?? '');

    $allowedStatuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new Exception('Invalid status');
    }

    $prevStmt = $db->prepare(
        'SELECT do.status, do.order_date, do.customer_id, c.name AS customer_name
         FROM daily_orders do JOIN customers c ON c.id = do.customer_id WHERE do.id = ?'
    );
    $prevStmt->execute([$orderId]);
    $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('UPDATE daily_orders SET status = ? WHERE id = ?');
    $stmt->execute([$status, $orderId]);

    if ($prev && (string)$prev['status'] !== $status) {
        $eventType = $status === 'invoiced'
            ? BAKERY_OP_INVOICE_GENERATED
            : BAKERY_OP_DAILY_ORDER_STATUS_CHANGED;
        bakery_record_operational_event($db, $eventType,
            'Order status set to ' . str_replace('_', ' ', $status) . ' for ' . $prev['customer_name'], [
            'operational_date' => $prev['order_date'],
            'customer_id' => (int)$prev['customer_id'],
            'daily_order_id' => $orderId,
            'metadata' => [
                'old_status' => $prev['status'],
                'new_status' => $status,
            ],
        ]);
    }

    return ['success' => true];
}

function bakery_daily_orders_action_add_item(PDO $db, array $input, ?array $user = null): array
{
    $orderId = (int)($input['order_id'] ?? 0);
    $productId = (int)($input['product_id'] ?? 0);
    $quantity = (int)($input['quantity'] ?? 0);
    if ($orderId <= 0 || $productId <= 0 || $quantity <= 0) {
        return ['success' => false, 'error' => 'Invalid item'];
    }

    $orderStmt = $db->prepare(
        'SELECT c.id AS customer_id, c.pricing_tier, c.default_pan_dulce_price
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$orderId]);
    $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$orderRow) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    $productStmt = $db->prepare(
        'SELECT p.id, p.price, p.wholesale_price, pl.name AS product_line_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE p.id = ?'
    );
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return ['success' => false, 'error' => 'Product not found'];
    }

    $noCharge = (string)($input['is_no_charge'] ?? '0') === '1';
    $reason = null;
    if ($noCharge) {
        if (!bakery_order_line_no_charge_ready($db)) {
            return ['success' => false, 'error' => 'No charge is not available'];
        }
        try {
            $reason = bakery_no_charge_normalize_reason($input['no_charge_reason'] ?? '');
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'error' => 'Unknown no-charge reason'];
        }
    }

    $unitPrice = bakery_resolve_customer_price($db, [
        'id' => (int)$orderRow['customer_id'],
        'pricing_tier' => $orderRow['pricing_tier'] ?? 'retail',
        'default_pan_dulce_price' => $orderRow['default_pan_dulce_price'] ?? null,
    ], [
        'id' => $productId,
        'price' => $product['price'] ?? 0,
        'wholesale_price' => $product['wholesale_price'] ?? null,
        'product_line_name' => $product['product_line_name'] ?? '',
    ]);

    $auditBefore = bakery_audit_daily_line_lookup($db, $orderId, $productId);

    if (bakery_order_reprice_zero_catalog_blocked($product, (float)$unitPrice, $noCharge)) {
        return [
            'success' => false,
            'error' => bakery_t('reprice.zero_block'),
            'code' => 'zero_catalog',
        ];
    }

    if ($noCharge) {
        $unitPrice = 0.0;
        $stmt = $db->prepare(
            'INSERT INTO daily_order_items
                (daily_order_id, product_id, quantity, unit_price, line_total, is_no_charge, no_charge_reason)
             VALUES (?, ?, ?, 0, 0, 1, ?)
             ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                is_no_charge = 1,
                no_charge_reason = VALUES(no_charge_reason),
                unit_price = 0,
                line_total = 0'
        );
        $stmt->execute([$orderId, $productId, $quantity, $reason]);
    } else {
        $lineTotal = round($quantity * $unitPrice, 2);
        $stmt = $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                quantity = quantity + VALUES(quantity),
                unit_price = VALUES(unit_price),
                line_total = quantity * unit_price'
        );
        $stmt->execute([$orderId, $productId, $quantity, $unitPrice, $lineTotal]);
    }

    bakery_daily_orders_update_order_total($db, $orderId);
    bakery_audit_daily_line_diff($db, $auditBefore, bakery_audit_daily_line_lookup($db, $orderId, $productId));

    $orderMeta = $db->prepare(
        'SELECT do.order_date, do.customer_id, c.name AS customer_name, p.name AS product_name
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         JOIN products p ON p.id = ?
         WHERE do.id = ?'
    );
    $orderMeta->execute([$productId, $orderId]);
    $meta = $orderMeta->fetch(PDO::FETCH_ASSOC);
    if ($meta) {
        bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_ITEM_ADDED,
            'Added ' . $quantity . ' × ' . $meta['product_name'] . ' to ' . $meta['customer_name'], [
            'operational_date' => $meta['order_date'],
            'customer_id' => (int)$meta['customer_id'],
            'daily_order_id' => $orderId,
            'product_id' => $productId,
            'metadata' => ['quantity' => $quantity, 'product_name' => $meta['product_name']],
        ]);
    }

    return ['success' => true];
}

function bakery_daily_orders_action_reprice_from_catalog(PDO $db, array $input, ?array $user = null): array
{
    $user = $user ?? (function_exists('bakery_current_user') ? bakery_current_user() : null);
    $result = bakery_order_reprice_apply_order($db, (int)($input['order_id'] ?? 0), $user, null, true);
    if (!$result['ok']) {
        return [
            'success' => false,
            'error' => $result['message'] ?? bakery_t('reprice.order_refused'),
        ];
    }
    return [
        'success' => true,
        'updated' => (int)$result['updated'],
        'message' => $result['message'],
    ];
}

function bakery_daily_orders_action_apply_pan_dulce_standard_to_order(PDO $db, array $input, ?array $user = null): array
{
    $orderId = (int)($input['order_id'] ?? 0);
    $multiplier = isset($input['multiplier']) ? (float)$input['multiplier'] : 1.0;

    $result = bakery_apply_pan_dulce_daily_standard($db, $orderId, $multiplier);
    bakery_daily_orders_update_order_total($db, $orderId);

    bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_ITEM_ADDED,
        'Applied Pan Dulce standard (' . $multiplier . '×) to ' . $result['customer_name'], [
        'operational_date' => $result['order_date'],
        'customer_id' => $result['customer_id'],
        'daily_order_id' => $orderId,
        'metadata' => [
            'multiplier' => $multiplier,
            'products' => $result['products'],
            'updated' => $result['updated'],
        ],
    ]);

    return ['success' => true] + $result;
}

function bakery_daily_orders_action_remove_order(PDO $db, array $input, ?array $user = null): array
{
    $orderId = (int)($input['order_id'] ?? 0);
    $date = $input['date'] ?? '';
    $confirmed = ($input['confirm_delivered'] ?? '0') === '1';
    $result = bakery_remove_empty_dated_order($db, $orderId, $date, $confirmed);
    if ($result['requires_confirmation']) {
        return [
            'success' => false,
            'requires_delivered_confirmation' => true,
            'status' => $result['status'],
        ];
    }

    return ['success' => true, 'message' => $result['message']];
}

function bakery_daily_orders_action_clear_day(PDO $db, array $input, ?array $user = null): array
{
    $date = $input['date'] ?? '';
    $confirmed = ($input['confirm_delivered'] ?? '0') === '1';
    bakery_daily_orders_validate_date($date);

    $stmt = $db->prepare("SELECT COUNT(*) FROM daily_orders WHERE order_date = ? AND status IN ('delivered', 'invoiced')");
    $stmt->execute([$date]);
    $deliveredCount = (int)$stmt->fetchColumn();

    if ($deliveredCount > 0 && !$confirmed) {
        return [
            'success' => false,
            'requires_delivered_confirmation' => true,
            'delivered_count' => $deliveredCount,
        ];
    }

    $db->beginTransaction();
    try {
        if (table_exists($db, 'driver_photos')) {
            $stmt = $db->prepare("DELETE FROM driver_photos WHERE delivery_date = ? AND customer_id IN (SELECT customer_id FROM daily_orders WHERE order_date = ?)");
            $stmt->execute([$date, $date]);
        }
        $stmt = $db->prepare('DELETE FROM daily_orders WHERE order_date = ?');
        $stmt->execute([$date]);
        $deletedCount = $stmt->rowCount();
        $db->commit();

        bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_CLEARED,
            'Cleared all daily orders for ' . date('l, F j, Y', strtotime($date)), [
            'operational_date' => $date,
            'metadata' => [
                'deleted_count' => $deletedCount,
                'had_delivered' => $deliveredCount > 0,
                'confirmed_delivered' => $confirmed,
            ],
        ]);

        return ['success' => true, 'deleted_count' => $deletedCount];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_daily_orders_action_set_no_charge(PDO $db, array $input, ?array $user = null): array
{
    if (!function_exists('bakery_order_line_no_charge_ready') || !bakery_order_line_no_charge_ready($db)) {
        throw new Exception('No-charge lines are not available until the database migration is applied');
    }

    $itemId = (int)($input['item_id'] ?? 0);
    if ($itemId <= 0) {
        throw new Exception('Order line is required');
    }

    $mark = (string)($input['is_no_charge'] ?? '0') === '1';
    if (!$mark && array_key_exists('unit_price', $input)) {
        $posted = trim((string)$input['unit_price']);
        if ($posted !== '' && is_numeric($posted) && round((float)$posted, 2) <= 0) {
            $mark = true;
        }
    }
    $reason = null;
    if ($mark) {
        $reason = bakery_no_charge_normalize_reason($input['no_charge_reason'] ?? '');
    }

    $stmt = $db->prepare(
        'SELECT doi.id, doi.daily_order_id, doi.product_id, doi.quantity, doi.unit_price,
                doi.is_no_charge, doi.no_charge_reason,
                do.order_date, do.customer_id, do.delivery_confirmed_at,
                c.name AS customer_name, c.pricing_tier, c.default_pan_dulce_price,
                p.name AS product_name, p.price, p.wholesale_price, pl.name AS product_line_name
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.id = ?'
    );
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('Order line not found');
    }
    if (!empty($row['delivery_confirmed_at'])) {
        throw new Exception('This delivery is already confirmed');
    }

    $oldPrice = $row['unit_price'] === null ? null : round((float)$row['unit_price'], 2);
    $oldFlag = (int)$row['is_no_charge'] === 1;
    $oldReason = $row['no_charge_reason'] !== null ? (string)$row['no_charge_reason'] : null;

    if ($mark) {
        $newPrice = 0.0;
        $lineTotal = 0.0;
        $db->prepare(
            'UPDATE daily_order_items
             SET is_no_charge = 1, no_charge_reason = ?, unit_price = 0, line_total = 0
             WHERE id = ?'
        )->execute([$reason, $itemId]);
    } else {
        $newPrice = 0.0;
        if (function_exists('bakery_resolve_customer_price')) {
            $newPrice = round((float)bakery_resolve_customer_price($db, [
                'id' => (int)$row['customer_id'],
                'pricing_tier' => $row['pricing_tier'] ?? 'retail',
                'default_pan_dulce_price' => $row['default_pan_dulce_price'] ?? null,
            ], [
                'id' => (int)$row['product_id'],
                'price' => (float)($row['price'] ?? 0),
                'wholesale_price' => $row['wholesale_price'] ?? null,
                'product_line_name' => $row['product_line_name'] ?? '',
            ]), 2);
        }
        $lineTotal = round((int)$row['quantity'] * $newPrice, 2);
        $db->prepare(
            'UPDATE daily_order_items
             SET is_no_charge = 0, no_charge_reason = NULL, unit_price = ?, line_total = ?
             WHERE id = ?'
        )->execute([$newPrice, $lineTotal, $itemId]);
        $reason = null;
    }

    bakery_daily_orders_update_order_total($db, (int)$row['daily_order_id']);
    bakery_audit_daily_line_diff(
        $db,
        ['id' => $itemId, 'quantity' => $row['quantity'], 'unit_price' => $oldPrice],
        ['id' => $itemId, 'quantity' => $row['quantity'], 'unit_price' => $newPrice]
    );
    if ($oldFlag !== $mark) {
        bakery_audit_write(
            $db,
            'daily_order_item',
            $itemId,
            'update',
            'is_no_charge',
            $oldFlag ? '1' : '0',
            $mark ? '1' : '0'
        );
    }

    $changed = $oldFlag !== $mark
        || $oldReason !== $reason
        || ($oldPrice === null ? $newPrice !== 0.0 : abs($oldPrice - $newPrice) >= 0.005);
    if ($changed && function_exists('bakery_record_operational_event')) {
        $eventType = defined('BAKERY_OP_LINE_NO_CHARGE_SET')
            ? BAKERY_OP_LINE_NO_CHARGE_SET
            : 'line_no_charge_set';
        bakery_record_operational_event(
            $db,
            $eventType,
            ($mark ? 'Marked no-charge' : 'Cleared no-charge') . ' for ' . $row['customer_name'] . ' - ' . $row['product_name'],
            [
                'operational_date' => $row['order_date'],
                'customer_id' => (int)$row['customer_id'],
                'daily_order_id' => (int)$row['daily_order_id'],
                'product_id' => (int)$row['product_id'],
                'actor_user_id' => $user['id'] ?? null,
                'actor_role' => $user['role_slug'] ?? null,
                'metadata' => [
                    'line_id' => $itemId,
                    'old_price' => $oldPrice,
                    'new_price' => $newPrice,
                    'is_no_charge' => $mark ? 1 : 0,
                    'no_charge_reason' => $reason,
                    'source' => 'daily_orders',
                ],
            ]
        );
    }

    return [
        'success' => true,
        'is_no_charge' => $mark ? 1 : 0,
        'no_charge_reason' => $reason,
        'unit_price' => $newPrice,
    ];
}

function bakery_daily_orders_action_skip_dated_day(PDO $db, array $input, ?array $user = null): array
{
    return bakery_daily_order_skip(
        $db,
        (int)($input['order_id'] ?? 0),
        (string)($input['date'] ?? ''),
        $user
    );
}

function bakery_daily_orders_action_undo_skip_dated_day(PDO $db, array $input, ?array $user = null): array
{
    return bakery_daily_order_undo_skip(
        $db,
        (int)($input['order_id'] ?? 0),
        (string)($input['date'] ?? ''),
        $user
    );
}

function bakery_daily_orders_action_skip_unassigned(PDO $db, array $input, ?array $user = null): array
{
    return bakery_daily_order_skip_unassigned(
        $db,
        (string)($input['date'] ?? ''),
        bakery_daily_order_skip_id_list($input['order_ids'] ?? ''),
        $user
    );
}

function bakery_daily_orders_dispatch(PDO $db, array $input, ?array $user = null): array
{
    $action = (string)($input['action'] ?? '');
    switch ($action) {
        case 'preview_generate':
            return bakery_daily_orders_action_preview_generate($db, $input, $user);
        case 'generate_from_standing':
            return bakery_daily_orders_action_generate_from_standing($db, $input, $user);
        case 'generate_week_from_standing':
            return bakery_daily_orders_action_generate_week_from_standing($db, $input, $user);
        case 'create_dated_order':
            return bakery_daily_orders_action_create_dated_order($db, $input, $user);
        case 'set_dated_quantity':
            return bakery_daily_orders_action_set_dated_quantity($db, $input, $user);
        case 'copy_prior_to_dated':
            return bakery_daily_orders_action_copy_prior_to_dated($db, $input, $user);
        case 'apply_standing_to_dated':
            return bakery_daily_orders_action_apply_standing_to_dated($db, $input, $user);
        case 'update_quantity':
            return bakery_daily_orders_action_update_quantity($db, $input, $user);
        case 'set_no_charge':
            return bakery_daily_orders_action_set_no_charge($db, $input, $user);
        case 'update_status':
            return bakery_daily_orders_action_update_status($db, $input, $user);
        case 'add_item':
            return bakery_daily_orders_action_add_item($db, $input, $user);
        case 'reprice_order_from_catalog':
            return bakery_daily_orders_action_reprice_from_catalog($db, $input, $user);
        case 'apply_pan_dulce_standard_to_order':
            return bakery_daily_orders_action_apply_pan_dulce_standard_to_order($db, $input, $user);
        case 'remove_order':
            return bakery_daily_orders_action_remove_order($db, $input, $user);
        case 'clear_day':
            return bakery_daily_orders_action_clear_day($db, $input, $user);
        case 'skip_dated_day':
            return bakery_daily_orders_action_skip_dated_day($db, $input, $user);
        case 'undo_skip_dated_day':
            return bakery_daily_orders_action_undo_skip_dated_day($db, $input, $user);
        case 'skip_unassigned_stops':
            return bakery_daily_orders_action_skip_unassigned($db, $input, $user);
        default:
            throw new Exception('Unknown action');
    }
}
