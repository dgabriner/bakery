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

/** True when route closeout columns/types (waste + returned tracking) are installed. */
function bakery_inventory_closeout_ready(PDO $db): bool {
    return bakery_inventory_ready($db)
        && function_exists('column_exists')
        && column_exists($db, 'driver_load_items', 'wasted_quantity');
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

/**
 * Save the final pickup quantities for one driver, returning stock for any reduced load.
 *
 * Pickup quantities are always saved as entered. When warehouse stock is short,
 * only the available portion is reserved; the rest is recorded as a load override
 * (manager confirmed the product was physically picked up).
 */
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
        $loadStmt = $db->prepare(
            'SELECT id, status FROM driver_loads WHERE driver_id = ? AND delivery_date = ? FOR UPDATE'
        );
        $loadStmt->execute([$driverId, $date]);
        $loadRow = $loadStmt->fetch(PDO::FETCH_ASSOC);
        $loadId = $loadRow ? (int)$loadRow['id'] : 0;
        if ($loadRow && (string)$loadRow['status'] === 'reconciled') {
            throw new RuntimeException(
                'This driver route is already closed out. Reopen the closeout before changing pickup quantities.'
            );
        }
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
                $available = max(0, (int)$stockStmt->fetchColumn());
                $reserved = min($delta, $available);
                $override = $delta - $reserved;
                if ($reserved > 0) {
                    $stockUpdate->execute([$reserved, $reserved, $date, $productId]);
                    bakery_inventory_movement($db, $date, $productId, 'load', -$reserved, $driverId, $notes);
                }
                if ($override > 0) {
                    $overrideNote = trim(($notes ?? '') !== '' ? ($notes . ' — ') : '')
                        . "Load override: {$override} unit(s) picked up without finished-goods reservation.";
                    bakery_inventory_movement($db, $date, $productId, 'load', 0, $driverId, $overrideNote);
                }
            } elseif ($delta < 0) {
                $stockUpdate->execute([$delta, $delta, $date, $productId]);
                bakery_inventory_movement($db, $date, $productId, 'load_correction', -$delta, $driverId, $notes);
            }
            $itemUpdate->execute([$loadId, $productId, $quantity]);
        }
        $db->prepare("UPDATE daily_orders do INNER JOIN daily_order_assignments doa ON doa.daily_order_id = do.id SET do.status = 'out_for_delivery' WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.status NOT IN ('delivered', 'invoiced')")
            ->execute([$driverId, $date]);
        if (function_exists('bakery_customer_notify_out_for_delivery_batch')) {
            require_once __DIR__ . '/customer_notifications.php';
            bakery_customer_notify_out_for_delivery_batch($db, $driverId, $date);
        }

        if ($ownTransaction) $db->commit();
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Delivered units for one driver/product on an operating date.
 * Uses line delivered_quantity when set; otherwise ordered quantity for delivered stops.
 *
 * @return array<int, int> product_id => delivered units
 */
function bakery_inventory_driver_delivered_by_product(PDO $db, string $date, int $driverId): array {
    bakery_inventory_validate_date($date);
    $hasDeliveredQty = function_exists('column_exists')
        && column_exists($db, 'daily_order_items', 'delivered_quantity');
    $deliveredExpr = $hasDeliveredQty
        ? 'COALESCE(doi.delivered_quantity, doi.quantity)'
        : 'doi.quantity';

    $stmt = $db->prepare(
        "SELECT doi.product_id, COALESCE(SUM({$deliveredExpr}), 0) AS delivered_quantity
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN daily_order_items doi ON doi.daily_order_id = do.id
         WHERE doa.driver_id = ?
           AND doa.delivery_date = ?
           AND do.order_date = doa.delivery_date
           AND doa.delivery_status = 'delivered'
           AND do.status IN ('delivered', 'invoiced')
         GROUP BY doi.product_id"
    );
    $stmt->execute([$driverId, $date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['product_id']] = (int)$row['delivered_quantity'];
    }
    return $out;
}

/**
 * Open stop counts for a driver on the operating date (blocks closeout).
 *
 * @return array{pending:int,in_transit:int,failed:int,open:int}
 */
function bakery_inventory_driver_open_stops(PDO $db, string $date, int $driverId): array {
    $stmt = $db->prepare(
        "SELECT
            SUM(CASE WHEN doa.delivery_status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN doa.delivery_status = 'in_transit' THEN 1 ELSE 0 END) AS in_transit,
            SUM(CASE WHEN doa.delivery_status = 'failed' THEN 1 ELSE 0 END) AS failed
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.driver_id = ?
           AND doa.delivery_date = ?
           AND do.order_date = doa.delivery_date
           AND doa.delivery_status <> 'cancelled'"
    );
    $stmt->execute([$driverId, $date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $pending = (int)($row['pending'] ?? 0);
    $inTransit = (int)($row['in_transit'] ?? 0);
    $failed = (int)($row['failed'] ?? 0);
    return [
        'pending' => $pending,
        'in_transit' => $inTransit,
        'failed' => $failed,
        'open' => $pending + $inTransit,
    ];
}

/**
 * Build per-product closeout lines for one driver on the operating date.
 *
 * @return list<array<string,mixed>>
 */
function bakery_inventory_closeout_lines(PDO $db, string $date, int $driverId): array {
    bakery_inventory_validate_date($date);
    $deliveredByProduct = bakery_inventory_driver_delivered_by_product($db, $date, $driverId);
    $hasWasteCol = bakery_inventory_closeout_ready($db);

    $stmt = $db->prepare(
        'SELECT li.product_id, p.name AS product_name,
                li.loaded_quantity, li.returned_quantity'
        . ($hasWasteCol ? ', li.wasted_quantity' : ', 0 AS wasted_quantity')
        . ', dl.status AS load_status
         FROM driver_loads dl
         JOIN driver_load_items li ON li.driver_load_id = dl.id
         JOIN products p ON p.id = li.product_id
         WHERE dl.driver_id = ? AND dl.delivery_date = ?
         ORDER BY p.name'
    );
    $stmt->execute([$driverId, $date]);
    $lines = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int)$row['product_id'];
        $seen[$productId] = true;
        $loaded = (int)$row['loaded_quantity'];
        $delivered = (int)($deliveredByProduct[$productId] ?? 0);
        $returned = (int)$row['returned_quantity'];
        $wasted = (int)$row['wasted_quantity'];
        $isReconciled = (string)$row['load_status'] === 'reconciled';
        $remaining = max(0, $loaded - $delivered);
        $lines[] = [
            'product_id' => $productId,
            'product_name' => (string)$row['product_name'],
            'loaded_quantity' => $loaded,
            'delivered_quantity' => $delivered,
            'returned_quantity' => $isReconciled ? $returned : $remaining,
            'wasted_quantity' => $isReconciled ? $wasted : 0,
            'suggested_returned' => $remaining,
            'balance' => $loaded - $delivered - ($isReconciled ? $returned : $remaining) - ($isReconciled ? $wasted : 0),
        ];
    }

    // Include delivered-only products (under-loaded vans) so the math is visible.
    foreach ($deliveredByProduct as $productId => $delivered) {
        if (isset($seen[$productId]) || $delivered <= 0) {
            continue;
        }
        $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ?');
        $nameStmt->execute([$productId]);
        $lines[] = [
            'product_id' => $productId,
            'product_name' => (string)($nameStmt->fetchColumn() ?: ('Product #' . $productId)),
            'loaded_quantity' => 0,
            'delivered_quantity' => $delivered,
            'returned_quantity' => 0,
            'wasted_quantity' => 0,
            'suggested_returned' => 0,
            'balance' => 0 - $delivered,
        ];
    }

    usort($lines, static function ($a, $b) {
        return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
    });
    return $lines;
}

/**
 * Board summary: every driver with a load or assignments for the operating date.
 *
 * @return list<array<string,mixed>>
 */
function bakery_inventory_closeout_board(PDO $db, string $date): array {
    bakery_inventory_validate_date($date);
    if (!bakery_inventory_ready($db)) {
        return [];
    }

    $drivers = [];
    $assignStmt = $db->prepare(
        "SELECT doa.driver_id, d.name AS driver_name,
                COUNT(DISTINCT doa.daily_order_id) AS stop_count,
                SUM(CASE WHEN doa.delivery_status IN ('pending','in_transit') THEN 1 ELSE 0 END) AS open_stops,
                SUM(CASE WHEN doa.delivery_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_stops,
                SUM(CASE WHEN doa.delivery_status = 'failed' THEN 1 ELSE 0 END) AS failed_stops
         FROM daily_order_assignments doa
         JOIN drivers d ON d.id = doa.driver_id
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.delivery_date = ?
           AND do.order_date = doa.delivery_date
           AND doa.delivery_status <> 'cancelled'
         GROUP BY doa.driver_id, d.name"
    );
    $assignStmt->execute([$date]);
    foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $drivers[(int)$row['driver_id']] = [
            'driver_id' => (int)$row['driver_id'],
            'driver_name' => (string)$row['driver_name'],
            'stop_count' => (int)$row['stop_count'],
            'open_stops' => (int)$row['open_stops'],
            'delivered_stops' => (int)$row['delivered_stops'],
            'failed_stops' => (int)$row['failed_stops'],
            'loaded_units' => 0,
            'load_status' => null,
            'load_id' => null,
            'needs_closeout' => false,
            'is_reconciled' => false,
        ];
    }

    $loadStmt = $db->prepare(
        'SELECT dl.id, dl.driver_id, dl.status, d.name AS driver_name,
                COALESCE(SUM(li.loaded_quantity), 0) AS loaded_units
         FROM driver_loads dl
         JOIN drivers d ON d.id = dl.driver_id
         LEFT JOIN driver_load_items li ON li.driver_load_id = dl.id
         WHERE dl.delivery_date = ?
         GROUP BY dl.id, dl.driver_id, dl.status, d.name'
    );
    $loadStmt->execute([$date]);
    foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $driverId = (int)$row['driver_id'];
        if (!isset($drivers[$driverId])) {
            $drivers[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => (string)$row['driver_name'],
                'stop_count' => 0,
                'open_stops' => 0,
                'delivered_stops' => 0,
                'failed_stops' => 0,
                'loaded_units' => 0,
                'load_status' => null,
                'load_id' => null,
                'needs_closeout' => false,
                'is_reconciled' => false,
            ];
        }
        $drivers[$driverId]['load_id'] = (int)$row['id'];
        $drivers[$driverId]['load_status'] = (string)$row['status'];
        $drivers[$driverId]['loaded_units'] = (int)$row['loaded_units'];
        $drivers[$driverId]['is_reconciled'] = (string)$row['status'] === 'reconciled';
    }

    $board = [];
    foreach ($drivers as $driver) {
        $hasLoad = (int)$driver['loaded_units'] > 0 || !empty($driver['load_id']);
        $hasWork = (int)$driver['stop_count'] > 0;
        $driver['needs_closeout'] = $hasWork && $hasLoad && empty($driver['is_reconciled']);
        // Drivers with stops but no load row still need attention if they delivered product.
        if ($hasWork && !$hasLoad && (int)$driver['delivered_stops'] > 0) {
            $driver['needs_closeout'] = true;
        }
        $board[] = $driver;
    }

    usort($board, static function ($a, $b) {
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    return $board;
}

/**
 * Count drivers still needing route closeout for Daily Run gating.
 *
 * @return array{unreconciled:int,open_routes:int,reconciled:int,drivers_with_loads:int}
 */
function bakery_inventory_closeout_stats(PDO $db, string $date): array {
    $board = bakery_inventory_closeout_board($db, $date);
    $unreconciled = 0;
    $reconciled = 0;
    $withLoads = 0;
    foreach ($board as $row) {
        if ((int)$row['loaded_units'] > 0 || !empty($row['load_id'])) {
            $withLoads++;
        }
        if (!empty($row['is_reconciled'])) {
            $reconciled++;
        } elseif (!empty($row['needs_closeout'])) {
            $unreconciled++;
        }
    }
    return [
        'unreconciled' => $unreconciled,
        'open_routes' => $unreconciled,
        'reconciled' => $reconciled,
        'drivers_with_loads' => $withLoads,
    ];
}

/**
 * Reconcile one driver's load: loaded = delivered + returned + wasted.
 * Posts return / waste / delivery movements on the FG ledger and marks the load reconciled.
 *
 * @param array<int, array{returned?:int|string, wasted?:int|string}> $lines product_id => quantities
 */
function bakery_inventory_reconcile_driver_load(
    PDO $db,
    string $date,
    int $driverId,
    array $lines,
    ?string $notes = null
): void {
    if ($driverId <= 0) {
        throw new InvalidArgumentException('Choose a driver.');
    }
    if (!bakery_inventory_closeout_ready($db)) {
        throw new RuntimeException('Route closeout is not installed. Run the database migrations first.');
    }
    bakery_inventory_validate_date($date);

    $open = bakery_inventory_driver_open_stops($db, $date, $driverId);
    if ($open['open'] > 0) {
        throw new RuntimeException(
            'Finish or resolve open stops before closing this route ('
            . $open['open'] . ' still pending or in transit).'
        );
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $loadStmt = $db->prepare(
            'SELECT id, status FROM driver_loads WHERE driver_id = ? AND delivery_date = ? FOR UPDATE'
        );
        $loadStmt->execute([$driverId, $date]);
        $load = $loadStmt->fetch(PDO::FETCH_ASSOC);
        if (!$load) {
            throw new RuntimeException('No pickup load exists for this driver on this date.');
        }
        $loadId = (int)$load['id'];
        if ((string)$load['status'] === 'reconciled') {
            throw new RuntimeException('This route is already closed out.');
        }

        $itemStmt = $db->prepare(
            'SELECT product_id, loaded_quantity, returned_quantity, wasted_quantity
             FROM driver_load_items WHERE driver_load_id = ? FOR UPDATE'
        );
        $itemStmt->execute([$loadId]);
        $items = [];
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(int)$row['product_id']] = $row;
        }

        $deliveredByProduct = bakery_inventory_driver_delivered_by_product($db, $date, $driverId);
        // Only reconcile products on the load (plus any explicit line overrides).
        $productIds = array_unique(array_merge(array_keys($items), array_map('intval', array_keys($lines))));

        $stockUpdate = $db->prepare(
            'UPDATE product_inventory_days
             SET available_quantity = available_quantity + ?,
                 loaded_quantity = loaded_quantity - ?
             WHERE delivery_date = ? AND product_id = ?'
        );
        $itemUpdate = $db->prepare(
            'UPDATE driver_load_items
             SET returned_quantity = ?, wasted_quantity = ?
             WHERE driver_load_id = ? AND product_id = ?'
        );
        $itemInsert = $db->prepare(
            'INSERT INTO driver_load_items (driver_load_id, product_id, loaded_quantity, returned_quantity, wasted_quantity)
             VALUES (?, ?, 0, ?, ?)'
        );

        $totalReturned = 0;
        $totalWasted = 0;
        $totalDelivered = 0;

        foreach ($productIds as $productId) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $loaded = (int)($items[$productId]['loaded_quantity'] ?? 0);
            $delivered = (int)($deliveredByProduct[$productId] ?? 0);
            $raw = $lines[$productId] ?? $lines[(string)$productId] ?? [];
            if (!is_array($raw)) {
                $raw = [];
            }
            $returned = filter_var($raw['returned'] ?? 0, FILTER_VALIDATE_INT);
            $wasted = filter_var($raw['wasted'] ?? 0, FILTER_VALIDATE_INT);
            if ($returned === false || $returned < 0 || $wasted === false || $wasted < 0) {
                throw new InvalidArgumentException('Returned and waste quantities must be whole numbers of zero or more.');
            }

            if ($loaded === 0 && $delivered === 0 && $returned === 0 && $wasted === 0) {
                continue;
            }

            if ($delivered + $returned + $wasted !== $loaded) {
                $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ?');
                $nameStmt->execute([$productId]);
                $productName = (string)($nameStmt->fetchColumn() ?: ('Product #' . $productId));
                throw new RuntimeException(
                    "Closeout must balance for {$productName}: loaded {$loaded} ≠ delivered {$delivered} + returned {$returned} + waste {$wasted}."
                );
            }

            bakery_inventory_ensure_day($db, $date, $productId);
            $lock = $db->prepare(
                'SELECT available_quantity, loaded_quantity FROM product_inventory_days
                 WHERE delivery_date = ? AND product_id = ? FOR UPDATE'
            );
            $lock->execute([$date, $productId]);
            $lock->fetch(PDO::FETCH_ASSOC);

            $noteBase = trim((string)($notes ?? ''));

            if ($returned > 0) {
                // Return van stock to warehouse availability.
                $stockUpdate->execute([$returned, $returned, $date, $productId]);
                bakery_inventory_movement(
                    $db,
                    $date,
                    $productId,
                    'return',
                    $returned,
                    $driverId,
                    $noteBase !== '' ? $noteBase : 'Route closeout return'
                );
            }
            if ($wasted > 0) {
                // Waste leaves loaded custody without returning to available.
                $stockUpdate->execute([0, $wasted, $date, $productId]);
                bakery_inventory_movement(
                    $db,
                    $date,
                    $productId,
                    'waste',
                    -$wasted,
                    $driverId,
                    $noteBase !== '' ? $noteBase : 'Route closeout waste'
                );
            }
            if ($delivered > 0) {
                // Delivered units exit loaded custody (sold / left with customers).
                $stockUpdate->execute([0, $delivered, $date, $productId]);
                bakery_inventory_movement(
                    $db,
                    $date,
                    $productId,
                    'delivery',
                    -$delivered,
                    $driverId,
                    $noteBase !== '' ? $noteBase : 'Route closeout delivered'
                );
            }

            if (isset($items[$productId])) {
                $itemUpdate->execute([$returned, $wasted, $loadId, $productId]);
            } else {
                $itemInsert->execute([$loadId, $productId, $returned, $wasted]);
            }

            $totalReturned += $returned;
            $totalWasted += $wasted;
            $totalDelivered += $delivered;
        }

        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $hasReconciledAt = function_exists('column_exists')
            && column_exists($db, 'driver_loads', 'reconciled_at');
        if ($hasReconciledAt) {
            $db->prepare(
                'UPDATE driver_loads
                 SET status = ?, notes = COALESCE(?, notes),
                     reconciled_at = CURRENT_TIMESTAMP,
                     reconciled_by_user_id = ?
                 WHERE id = ?'
            )->execute(['reconciled', $notes, $user['id'] ?? null, $loadId]);
        } else {
            $db->prepare(
                'UPDATE driver_loads SET status = ?, notes = COALESCE(?, notes) WHERE id = ?'
            )->execute(['reconciled', $notes, $loadId]);
        }

        if (function_exists('bakery_record_operational_event')) {
            $eventType = defined('BAKERY_OP_DRIVER_ROUTE_CLOSED')
                ? BAKERY_OP_DRIVER_ROUTE_CLOSED
                : 'driver_route_closed';
            bakery_record_operational_event($db, $eventType,
                'Closed route for driver #' . $driverId, [
                'operational_date' => $date,
                'driver_id' => $driverId,
                'metadata' => [
                    'returned_units' => $totalReturned,
                    'wasted_units' => $totalWasted,
                    'delivered_units' => $totalDelivered,
                ],
            ]);
        }

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Reopen a reconciled driver load and reverse the closeout ledger effects
 * so pickup/closeout can be corrected.
 */
function bakery_inventory_reopen_driver_closeout(PDO $db, string $date, int $driverId): void {
    if ($driverId <= 0) {
        throw new InvalidArgumentException('Choose a driver.');
    }
    if (!bakery_inventory_closeout_ready($db)) {
        throw new RuntimeException('Route closeout is not installed. Run the database migrations first.');
    }
    bakery_inventory_validate_date($date);

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare(
            'SELECT id, status FROM driver_loads WHERE driver_id = ? AND delivery_date = ? FOR UPDATE'
        );
        $stmt->execute([$driverId, $date]);
        $load = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$load) {
            throw new RuntimeException('No pickup load exists for this driver on this date.');
        }
        if ((string)$load['status'] !== 'reconciled') {
            throw new RuntimeException('This route is not closed out.');
        }
        $loadId = (int)$load['id'];

        $itemStmt = $db->prepare(
            'SELECT product_id, loaded_quantity, returned_quantity, wasted_quantity
             FROM driver_load_items WHERE driver_load_id = ? FOR UPDATE'
        );
        $itemStmt->execute([$loadId]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        $deliveredByProduct = bakery_inventory_driver_delivered_by_product($db, $date, $driverId);

        $stockUpdate = $db->prepare(
            'UPDATE product_inventory_days
             SET available_quantity = available_quantity + ?,
                 loaded_quantity = loaded_quantity + ?
             WHERE delivery_date = ? AND product_id = ?'
        );
        $clearItem = $db->prepare(
            'UPDATE driver_load_items
             SET returned_quantity = 0, wasted_quantity = 0
             WHERE driver_load_id = ? AND product_id = ?'
        );

        foreach ($items as $row) {
            $productId = (int)$row['product_id'];
            $loaded = (int)$row['loaded_quantity'];
            $returned = (int)$row['returned_quantity'];
            $wasted = (int)$row['wasted_quantity'];
            $delivered = (int)($deliveredByProduct[$productId] ?? max(0, $loaded - $returned - $wasted));

            bakery_inventory_ensure_day($db, $date, $productId);
            $lock = $db->prepare(
                'SELECT id FROM product_inventory_days WHERE delivery_date = ? AND product_id = ? FOR UPDATE'
            );
            $lock->execute([$date, $productId]);

            if ($returned > 0) {
                // Undo return: take back from available into loaded.
                $stockUpdate->execute([-$returned, $returned, $date, $productId]);
                bakery_inventory_movement(
                    $db, $date, $productId, 'return', -$returned, $driverId, 'Reopen route closeout'
                );
            }
            if ($wasted > 0) {
                $stockUpdate->execute([0, $wasted, $date, $productId]);
                bakery_inventory_movement(
                    $db, $date, $productId, 'waste', $wasted, $driverId, 'Reopen route closeout'
                );
            }
            if ($delivered > 0) {
                $stockUpdate->execute([0, $delivered, $date, $productId]);
                bakery_inventory_movement(
                    $db, $date, $productId, 'delivery', $delivered, $driverId, 'Reopen route closeout'
                );
            }
            $clearItem->execute([$loadId, $productId]);
        }

        $hasReconciledAt = function_exists('column_exists')
            && column_exists($db, 'driver_loads', 'reconciled_at');
        if ($hasReconciledAt) {
            $db->prepare(
                'UPDATE driver_loads
                 SET status = ?, reconciled_at = NULL, reconciled_by_user_id = NULL
                 WHERE id = ?'
            )->execute(['loaded', $loadId]);
        } else {
            $db->prepare('UPDATE driver_loads SET status = ? WHERE id = ?')
                ->execute(['loaded', $loadId]);
        }

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
