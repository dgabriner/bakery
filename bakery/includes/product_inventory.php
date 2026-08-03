<?php
/** Finished-goods inventory helpers. All callers run inside the operations UI. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_inventory_ready(PDO $db): bool {
    return table_exists($db, 'product_inventory_days')
        && table_exists($db, 'inventory_movements')
        && table_exists($db, 'driver_loads')
        && table_exists($db, 'driver_load_items');
}

function bakery_inventory_validate_date(string $date): string {
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Use a valid delivery date.');
    }
    return $date;
}

function bakery_inventory_ensure_day(PDO $db, string $date, int $productId): void {
    $stmt = $db->prepare(
        'INSERT IGNORE INTO product_inventory_days (delivery_date, product_id) VALUES (?, ?)'
    );
    $stmt->execute([$date, $productId]);
}

function bakery_inventory_movement(PDO $db, string $date, int $productId, string $type, int $delta, ?int $driverId = null, ?string $notes = null): void {
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $stmt = $db->prepare(
        'INSERT INTO inventory_movements
         (delivery_date, product_id, movement_type, quantity_delta, driver_id, notes, created_by_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$date, $productId, $type, $delta, $driverId, $notes, $user['id'] ?? null]);
}

function bakery_inventory_record_production(PDO $db, string $date, int $productId, int $quantity, ?string $notes = null): void {
    if ($productId <= 0 || $quantity <= 0) {
        throw new InvalidArgumentException('Production quantity must be at least one unit.');
    }
    bakery_inventory_validate_date($date);
    bakery_inventory_ensure_day($db, $date, $productId);
    $stmt = $db->prepare(
        'UPDATE product_inventory_days
         SET available_quantity = available_quantity + ?, produced_quantity = produced_quantity + ?
         WHERE delivery_date = ? AND product_id = ?'
    );
    $stmt->execute([$quantity, $quantity, $date, $productId]);
    bakery_inventory_movement($db, $date, $productId, 'production', $quantity, null, $notes);
}

function bakery_inventory_set_count(PDO $db, string $date, int $productId, int $quantity, ?string $notes = null): void {
    if ($productId <= 0 || $quantity < 0) {
        throw new InvalidArgumentException('Count quantity cannot be negative.');
    }
    bakery_inventory_validate_date($date);
    bakery_inventory_ensure_day($db, $date, $productId);
    $current = $db->prepare(
        'SELECT available_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ? FOR UPDATE'
    );
    $current->execute([$date, $productId]);
    $oldQuantity = (int)$current->fetchColumn();
    $stmt = $db->prepare(
        'UPDATE product_inventory_days SET available_quantity = ?, counted_quantity = ? WHERE delivery_date = ? AND product_id = ?'
    );
    $stmt->execute([$quantity, $quantity, $date, $productId]);
    bakery_inventory_movement($db, $date, $productId, 'count', $quantity - $oldQuantity, null, $notes);
}

/** Save the final pickup quantities for one driver, returning stock for any reduced load. */
function bakery_inventory_save_driver_load(PDO $db, string $date, int $driverId, array $quantities, ?string $notes = null): void {
    if ($driverId <= 0) {
        throw new InvalidArgumentException('Choose a driver.');
    }
    bakery_inventory_validate_date($date);
    foreach ($quantities as $productId => $quantity) {
        if ((int)$productId <= 0 || filter_var($quantity, FILTER_VALIDATE_INT) === false || (int)$quantity < 0) {
            throw new InvalidArgumentException('Load quantities must be whole numbers of zero or more.');
        }
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) $db->beginTransaction();
    try {
        $loadStmt = $db->prepare('SELECT id FROM driver_loads WHERE driver_id = ? AND delivery_date = ? FOR UPDATE');
        $loadStmt->execute([$driverId, $date]);
        $loadId = (int)$loadStmt->fetchColumn();
        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        if (!$loadId) {
            $insert = $db->prepare('INSERT INTO driver_loads (driver_id, delivery_date, notes, created_by_user_id) VALUES (?, ?, ?, ?)');
            $insert->execute([$driverId, $date, $notes, $user['id'] ?? null]);
            $loadId = (int)$db->lastInsertId();
        } else {
            $db->prepare('UPDATE driver_loads SET notes = ? WHERE id = ?')->execute([$notes, $loadId]);
        }

        $oldStmt = $db->prepare('SELECT loaded_quantity FROM driver_load_items WHERE driver_load_id = ? AND product_id = ? FOR UPDATE');
        $stockStmt = $db->prepare('SELECT available_quantity FROM product_inventory_days WHERE delivery_date = ? AND product_id = ? FOR UPDATE');
        $stockUpdate = $db->prepare('UPDATE product_inventory_days SET available_quantity = available_quantity - ?, loaded_quantity = loaded_quantity + ? WHERE delivery_date = ? AND product_id = ?');
        $itemUpdate = $db->prepare('INSERT INTO driver_load_items (driver_load_id, product_id, loaded_quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE loaded_quantity = VALUES(loaded_quantity)');
        foreach ($quantities as $productId => $rawQuantity) {
            $productId = (int)$productId;
            $quantity = (int)$rawQuantity;
            bakery_inventory_ensure_day($db, $date, $productId);
            $oldStmt->execute([$loadId, $productId]);
            $oldQuantity = (int)$oldStmt->fetchColumn();
            $delta = $quantity - $oldQuantity;
            if ($delta > 0) {
                $stockStmt->execute([$date, $productId]);
                $available = (int)$stockStmt->fetchColumn();
                if ($available < $delta) {
                    throw new RuntimeException('Not enough available inventory for this driver load.');
                }
            }
            if ($delta !== 0) {
                $stockUpdate->execute([$delta, $delta, $date, $productId]);
                bakery_inventory_movement($db, $date, $productId, $delta > 0 ? 'load' : 'load_correction', -$delta, $driverId, $notes);
            }
            $itemUpdate->execute([$loadId, $productId, $quantity]);
        }
        $db->prepare("UPDATE daily_orders do INNER JOIN daily_order_assignments doa ON doa.daily_order_id = do.id SET do.status = 'out_for_delivery' WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.status NOT IN ('delivered', 'invoiced')")
            ->execute([$driverId, $date]);
        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
