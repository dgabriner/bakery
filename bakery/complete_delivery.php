<?php
/**
 * Delivery completion API — uses shared config/database (Checkpoint 0B).
 * Auth + CSRF enforced via includes/database.php (Checkpoint 0D).
 *
 * Unified delivery status on completion (both tables set to 'delivered'):
 * - daily_order_assignments.delivery_status: pending|in_transit|delivered|failed|cancelled
 * - daily_orders.status: pending|confirmed|in_production|ready|out_for_delivery|delivered|invoiced
 */
if (!defined('ACCESS_ALLOWED')) {
    define('ACCESS_ALLOWED', true);
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/product_inventory.php';
require_once __DIR__ . '/includes/operational_timeline.php';
require_once __DIR__ . '/includes/customer_notifications.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/driver_route_prep.php';
require_once __DIR__ . '/includes/delivery_recovery.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
}

/**
 * Mark assignment and parent daily_order as delivered in one transaction.
 * Safe to call when caller already holds an open transaction.
 */
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

function bakery_mark_delivery_delivered(PDO $db, int $dailyOrderId): void {
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }

    try {
        $assignmentStmt = $db->prepare(
            "UPDATE daily_order_assignments
             SET delivery_status = 'delivered', actual_delivery_time = CURTIME()
             WHERE daily_order_id = ?"
        );
        $assignmentStmt->execute([$dailyOrderId]);

        $orderStmt = $db->prepare(
            "UPDATE daily_orders SET status = 'delivered' WHERE id = ?"
        );
        $orderStmt->execute([$dailyOrderId]);

        bakery_apply_delivery_line_quantities($db, $dailyOrderId);

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Exception $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * Align line delivered_quantity with header delivered_pieces when drivers confirm piece counts.
 */
function bakery_apply_delivery_line_quantities(PDO $db, int $dailyOrderId): void {
    $orderStmt = $db->prepare(
        'SELECT delivered_pieces FROM daily_orders WHERE id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $headerPieces = $orderStmt->fetchColumn();
    $headerPieces = ($headerPieces !== false && $headerPieces !== null && $headerPieces !== '')
        ? (int)$headerPieces
        : null;

    $itemStmt = $db->prepare(
        'SELECT id, quantity, delivered_quantity, unit_price
         FROM daily_order_items
         WHERE daily_order_id = ?
         ORDER BY id'
    );
    $itemStmt->execute([$dailyOrderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$items) {
        return;
    }

    $orderedTotal = 0;
    $hasExplicitDelivered = false;
    foreach ($items as $item) {
        $orderedTotal += (int)$item['quantity'];
        if ($item['delivered_quantity'] !== null && $item['delivered_quantity'] !== '') {
            $hasExplicitDelivered = true;
        }
    }

    if ($hasExplicitDelivered) {
        return;
    }

    $targetPieces = $headerPieces ?? $orderedTotal;
    if ($targetPieces === $orderedTotal || $orderedTotal <= 0) {
        $upd = $db->prepare(
            'UPDATE daily_order_items
             SET delivered_quantity = quantity,
                 line_total = ROUND(quantity * unit_price, 2)
             WHERE daily_order_id = ?'
        );
        $upd->execute([$dailyOrderId]);
        return;
    }

    $allocations = [];
    $allocated = 0;
    foreach ($items as $idx => $item) {
        $ordered = (int)$item['quantity'];
        if ($ordered <= 0) {
            $allocations[$idx] = 0;
            continue;
        }
        $share = ($ordered / $orderedTotal) * $targetPieces;
        $whole = (int)floor($share);
        $allocations[$idx] = $whole;
        $allocated += $whole;
    }

    $remainder = $targetPieces - $allocated;
    if ($remainder > 0) {
        $fractions = [];
        foreach ($items as $idx => $item) {
            $ordered = (int)$item['quantity'];
            if ($ordered <= 0) {
                continue;
            }
            $fractions[] = [
                'idx' => $idx,
                'frac' => (($ordered / $orderedTotal) * $targetPieces) - floor(($ordered / $orderedTotal) * $targetPieces),
            ];
        }
        usort($fractions, static function ($a, $b) {
            if ($a['frac'] === $b['frac']) {
                return $a['idx'] <=> $b['idx'];
            }
            return $b['frac'] <=> $a['frac'];
        });
        for ($i = 0; $i < $remainder && $i < count($fractions); $i++) {
            $allocations[$fractions[$i]['idx']]++;
        }
    }

    $upd = $db->prepare(
        'UPDATE daily_order_items
         SET delivered_quantity = ?, line_total = ROUND(? * unit_price, 2)
         WHERE id = ? AND daily_order_id = ?'
    );
    foreach ($items as $idx => $item) {
        $delivered = (int)($allocations[$idx] ?? 0);
        $upd->execute([$delivered, $delivered, (int)$item['id'], $dailyOrderId]);
    }
}

function bakery_delivery_invoice(PDO $db, int $dailyOrderId): array {
    $orderStmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.total_amount,
                do.delivery_order_total, do.delivery_pricing_label,
                do.delivery_confirmed_at, do.delivered_pieces,
                do.credits_taken_back, c.name AS customer_name,
                c.address AS customer_address, c.phone AS customer_phone,
                c.default_pan_dulce_price,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM daily_order_items payment_doi
                    INNER JOIN products payment_p ON payment_p.id = payment_doi.product_id
                    INNER JOIN dough_types payment_dt ON payment_dt.id = payment_p.dough_type_id
                    INNER JOIN product_lines payment_pl ON payment_pl.id = payment_dt.product_line_id
                    WHERE payment_doi.daily_order_id = do.id
                      AND payment_pl.name = \'Pan Dulce\'
                    ) THEN \'cod\' ELSE COALESCE(c.payment_collection, \'cod\') END AS payment_collection,
                COALESCE(asn_drv.name, legacy_drv.name) AS driver_name,
                COALESCE(asn.driver_id, do.driver_id) AS driver_id
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         LEFT JOIN (
             SELECT doa1.daily_order_id, doa1.driver_id
             FROM daily_order_assignments doa1
             INNER JOIN (
                 SELECT daily_order_id, MAX(id) AS max_id
                 FROM daily_order_assignments
                 GROUP BY daily_order_id
             ) latest ON latest.daily_order_id = doa1.daily_order_id AND latest.max_id = doa1.id
         ) asn ON asn.daily_order_id = do.id
         LEFT JOIN drivers asn_drv ON asn_drv.id = asn.driver_id
         LEFT JOIN drivers legacy_drv ON legacy_drv.id = do.driver_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new Exception('Order not found');
    }

    $itemStmt = $db->prepare(
        "SELECT doi.id, doi.product_id, doi.quantity, doi.delivered_quantity,
                doi.unit_price, doi.line_total, p.name AS product_name,
                p.price AS standard_price, pl.name AS product_line_name,
                dt.name AS dough_type_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ?
         ORDER BY pl.name, dt.name, p.name"
    );
    $itemStmt->execute([$dailyOrderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderedPieces = 0;
    $storedOrderTotal = 0.0;
    $hasPanDulce = false;
    $hasStorePrice = false;
    $hasStandardPrice = false;
    foreach ($items as &$item) {
        $quantity = (int)$item['quantity'];
        $unitPrice = round((float)$item['unit_price'], 2);
        // Older daily orders can have a zero-priced line even though the active
        // catalog price is configured. Resolve it here so drivers use the known
        // standard price instead of being asked to enter one manually.
        if ($unitPrice <= 0) {
            $storePrice = ($item['product_line_name'] ?? '') === 'Pan Dulce'
                ? (float)($order['default_pan_dulce_price'] ?? 0)
                : 0.0;
            $standardPrice = (float)($item['standard_price'] ?? 0);
            $unitPrice = round($storePrice > 0 ? $storePrice : $standardPrice, 2);
        }
        $lineTotal = round($quantity * $unitPrice, 2);
        $orderedPieces += $quantity;
        $storedOrderTotal += $lineTotal;
        $item['quantity'] = $quantity;
        $item['unit_price'] = $unitPrice;
        $item['line_total'] = $lineTotal;
        if (($item['product_line_name'] ?? '') === 'Pan Dulce') {
            $hasPanDulce = true;
            $storePrice = $order['default_pan_dulce_price'];
            if ($storePrice !== null && (float)$storePrice > 0 && abs($unitPrice - (float)$storePrice) < 0.005) {
                $hasStorePrice = true;
            } elseif (abs($unitPrice - (float)$item['standard_price']) < 0.005) {
                $hasStandardPrice = true;
            }
        }
    }
    unset($item);

    $pricingLabel = (string)($order['delivery_pricing_label'] ?? '');
    if ($pricingLabel === '') {
        if ($hasStorePrice && $hasStandardPrice) {
            $pricingLabel = 'Mixed Pan Dulce pricing';
        } elseif ($hasStorePrice) {
            $pricingLabel = 'Store price';
        } elseif ($hasPanDulce) {
            $pricingLabel = 'Standard price';
        } else {
            $pricingLabel = 'Order pricing';
        }
    }

    // Until a delivery is confirmed, item lines are the source of truth. This
    // also handles legacy orders whose header total was zero before pricing was set.
    $orderTotal = $order['delivery_confirmed_at'] !== null && $order['delivery_order_total'] !== null
        ? round((float)$order['delivery_order_total'], 2)
        : round($storedOrderTotal, 2);
    $averagePrice = $orderedPieces > 0 ? round($orderTotal / $orderedPieces, 4) : 0.0;

    return [
        'order' => $order,
        'items' => $items,
        'ordered_pieces' => $orderedPieces,
        'order_total' => $orderTotal,
        'average_price' => $averagePrice,
        'pricing_label' => $pricingLabel,
    ];
}

function bakery_delivery_pricing_missing(array $invoice): bool {
    if ($invoice['ordered_pieces'] <= 0) {
        return false;
    }
    if ($invoice['order_total'] <= 0 || $invoice['average_price'] <= 0) {
        return true;
    }
    foreach ($invoice['items'] as $item) {
        if ((int)$item['quantity'] > 0 && (float)$item['unit_price'] <= 0) {
            return true;
        }
    }
    return false;
}

/** Persist valid catalog/store prices for historical zero-priced order lines. */
function bakery_delivery_repair_missing_item_prices(PDO $db, int $dailyOrderId): void {
    $stmt = $db->prepare(
        "SELECT doi.id, doi.quantity, doi.unit_price, p.price AS standard_price,
                pl.name AS product_line_name, c.default_pan_dulce_price
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ? AND doi.quantity > 0 AND doi.unit_price <= 0"
    );
    $stmt->execute([$dailyOrderId]);
    $update = $db->prepare(
        'UPDATE daily_order_items SET unit_price = ?, line_total = ? WHERE id = ? AND daily_order_id = ?'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $storePrice = ($item['product_line_name'] ?? '') === 'Pan Dulce'
            ? (float)($item['default_pan_dulce_price'] ?? 0)
            : 0.0;
        $price = round($storePrice > 0 ? $storePrice : (float)$item['standard_price'], 2);
        if ($price > 0) {
            $update->execute([$price, round((int)$item['quantity'] * $price, 2), (int)$item['id'], $dailyOrderId]);
        }
    }
}

function bakery_apply_driver_price(PDO $db, int $dailyOrderId, float $pricePerPiece): array {
    $pricePerPiece = round($pricePerPiece, 2);
    if ($pricePerPiece <= 0) {
        throw new Exception('Enter a price greater than zero');
    }

    $itemStmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ?'
    );
    $itemStmt->execute([$dailyOrderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderTotal = 0.0;
    $orderedPieces = 0;
    $updateStmt = $db->prepare(
        'UPDATE daily_order_items SET unit_price = ?, line_total = ? WHERE id = ? AND daily_order_id = ?'
    );
    foreach ($items as $item) {
        $quantity = (int)$item['quantity'];
        $lineTotal = round($quantity * $pricePerPiece, 2);
        $updateStmt->execute([$pricePerPiece, $lineTotal, (int)$item['id'], $dailyOrderId]);
        $orderedPieces += $quantity;
        $orderTotal += $lineTotal;
    }

    $orderTotal = round($orderTotal, 2);
    $label = 'Driver-entered price';

    $orderStmt = $db->prepare(
        'UPDATE daily_orders
         SET delivery_order_total = ?, delivery_pricing_label = ?
         WHERE id = ?'
    );
    $orderStmt->execute([$orderTotal, $label, $dailyOrderId]);

    return [
        'ordered_pieces' => $orderedPieces,
        'order_total' => $orderTotal,
        'average_price' => $orderedPieces > 0 ? round($orderTotal / $orderedPieces, 4) : 0.0,
        'pricing_label' => $label,
    ];
}

function bakery_delivery_summary(PDO $db, int $dailyOrderId): array {
    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    return [
        'ordered_pieces' => $invoice['ordered_pieces'],
        'order_total' => $invoice['order_total'],
        'average_price' => $invoice['average_price'],
        'pricing_label' => $invoice['pricing_label'],
        'pricing_missing' => bakery_delivery_pricing_missing($invoice),
    ];
}

function bakery_delivery_has_photo(PDO $db, int $dailyOrderId): bool
{
    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    if (!$ctx || !table_exists($db, 'driver_photos')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT 1 FROM driver_photos
         WHERE customer_id = ? AND delivery_date = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$ctx['customer_id'], $ctx['order_date']]);
    return (bool)$stmt->fetchColumn();
}

function bakery_delivery_gps_payload(array $post): array
{
    $gps = bakery_operational_gps_from_input($post);
    return [
        'latitude' => $gps['latitude'],
        'longitude' => $gps['longitude'],
        'accuracy_m' => $gps['accuracy_m'],
        'status' => $gps['status'],
    ];
}

if (PHP_SAPI !== 'cli') {
try {
    $db = check_mysql_connection();

    if (!isset($_POST['action'])) {
        throw new Exception('Action is required');
    }

    $action = $_POST['action'];

    switch ($action) {
        case 'get_delivery_summary':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            $summary = bakery_delivery_summary($db, $dailyOrderId);
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            $orderStmt = $db->prepare('SELECT delivered_pieces, credits_taken_back, total_amount, delivery_confirmed_at, amount_collected FROM daily_orders WHERE id = ?');
            $orderStmt->execute([$dailyOrderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) {
                throw new Exception('Order not found');
            }
            $paymentCollection = $invoice['order']['payment_collection'] ?? 'cod';
            if (!in_array($paymentCollection, ['cod', 'signature'], true)) {
                $paymentCollection = 'signature';
            }
            echo json_encode([
                'success' => true,
                'ordered_pieces' => $summary['ordered_pieces'],
                'order_total' => $summary['order_total'],
                'average_price' => $summary['average_price'],
                'pricing_label' => $summary['pricing_label'],
                'pricing_missing' => $summary['pricing_missing'],
                'order_date' => $invoice['order']['order_date'] ?? '',
                'customer_name' => $invoice['order']['customer_name'] ?? '',
                'customer_address' => $invoice['order']['customer_address'] ?? '',
                'driver_id' => (int)($invoice['order']['driver_id'] ?? 0),
                'driver_name' => (string)($invoice['order']['driver_name'] ?? ''),
                'delivered_pieces' => $order['delivered_pieces'] === null ? $summary['ordered_pieces'] : (int)$order['delivered_pieces'],
                'credits_taken_back' => (int)$order['credits_taken_back'],
                'saved_total' => (float)$order['total_amount'],
                'amount_collected' => $order['amount_collected'] !== null ? (float)$order['amount_collected'] : null,
                'payment_collection' => $paymentCollection,
                'is_saved' => $order['delivery_confirmed_at'] !== null,
                'items' => $invoice['items'],
            ]);
            break;

        case 'get_delivery_invoice':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            echo json_encode([
                'success' => true,
                'invoice' => [
                    'daily_order_id' => (int)$invoice['order']['id'],
                    'date' => $invoice['order']['order_date'],
                    'customer_name' => $invoice['order']['customer_name'],
                    'customer_address' => $invoice['order']['customer_address'],
                    'driver_id' => (int)($invoice['order']['driver_id'] ?? 0),
                    'driver_name' => (string)($invoice['order']['driver_name'] ?? ''),
                    'status' => $invoice['order']['status'],
                    'ordered_pieces' => $invoice['ordered_pieces'],
                    'delivered_pieces' => $invoice['order']['delivered_pieces'] === null ? $invoice['ordered_pieces'] : (int)$invoice['order']['delivered_pieces'],
                    'credits_taken_back' => (int)$invoice['order']['credits_taken_back'],
                    'billable_pieces' => max(0, (int)($invoice['order']['delivered_pieces'] ?? $invoice['ordered_pieces']) - (int)$invoice['order']['credits_taken_back']),
                    'price_per_piece' => $invoice['average_price'],
                    'order_total' => $invoice['order_total'],
                    'total' => (float)$invoice['order']['total_amount'],
                    'pricing_label' => $invoice['pricing_label'],
                    'confirmed_at' => $invoice['order']['delivery_confirmed_at'],
                    'items' => $invoice['items'],
                ],
            ]);
            break;

        case 'confirm_delivery':
            if (!isset($_POST['daily_order_id'], $_POST['delivered_pieces'], $_POST['credits_taken_back'])) {
                throw new Exception('Delivery pieces and credits are required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            $deliveredPieces = filter_var($_POST['delivered_pieces'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $creditsTakenBack = filter_var($_POST['credits_taken_back'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($deliveredPieces === false || $creditsTakenBack === false) {
                throw new Exception('Enter whole numbers of pieces and credits');
            }
            if ($creditsTakenBack > $deliveredPieces) {
                throw new Exception('Credits taken back cannot exceed pieces delivered');
            }

            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            $summary = [
                'ordered_pieces' => $invoice['ordered_pieces'],
                'order_total' => $invoice['order_total'],
                'average_price' => $invoice['average_price'],
                'pricing_label' => $invoice['pricing_label'],
                'pricing_missing' => bakery_delivery_pricing_missing($invoice),
            ];
            $billablePieces = $deliveredPieces - $creditsTakenBack;

            if ($summary['pricing_missing']) {
                if (!isset($_POST['price_per_piece'])) {
                    throw new Exception('Enter a price per piece for this customer');
                }
                $driverPrice = filter_var($_POST['price_per_piece'], FILTER_VALIDATE_FLOAT);
                if ($driverPrice === false || $driverPrice <= 0) {
                    throw new Exception('Enter a valid price per piece greater than zero');
                }
            }

            $custStmt = $db->prepare(
                "SELECT CASE WHEN EXISTS (
                            SELECT 1
                            FROM daily_order_items payment_doi
                            INNER JOIN products payment_p ON payment_p.id = payment_doi.product_id
                            INNER JOIN dough_types payment_dt ON payment_dt.id = payment_p.dough_type_id
                            INNER JOIN product_lines payment_pl ON payment_pl.id = payment_dt.product_line_id
                            WHERE payment_doi.daily_order_id = do.id
                              AND payment_pl.name = 'Pan Dulce'
                        ) THEN 'cod' ELSE COALESCE(c.payment_collection, 'cod') END
                 FROM daily_orders do
                 JOIN customers c ON c.id = do.customer_id
                 WHERE do.id = ?"
            );
            $custStmt->execute([$dailyOrderId]);
            $paymentCollection = (string)($custStmt->fetchColumn() ?: 'signature');
            if (!in_array($paymentCollection, ['cod', 'signature'], true)) {
                $paymentCollection = 'signature';
            }

            $amountCollected = null;
            if ($paymentCollection === 'cod') {
                if (!isset($_POST['amount_collected'])) {
                    throw new Exception('Cash collected amount is required for COD customers');
                }
                $amountCollected = filter_var($_POST['amount_collected'], FILTER_VALIDATE_FLOAT);
                if ($amountCollected === false || $amountCollected < 0) {
                    throw new Exception('Enter a valid cash amount collected');
                }
                $amountCollected = round($amountCollected, 2);
            }

            $db->beginTransaction();

            bakery_delivery_repair_missing_item_prices($db, $dailyOrderId);
            // Reload after repairing historical zero-priced lines so the delivery
            // total and the persisted invoice always agree.
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            $summary = [
                'ordered_pieces' => $invoice['ordered_pieces'],
                'order_total' => $invoice['order_total'],
                'average_price' => $invoice['average_price'],
                'pricing_label' => $invoice['pricing_label'],
                'pricing_missing' => bakery_delivery_pricing_missing($invoice),
            ];

            if ($summary['pricing_missing']) {
                $summary = bakery_apply_driver_price($db, $dailyOrderId, (float)$driverPrice);
            }

            $pricePerPiece = $summary['average_price'];
            $total = round($billablePieces * $pricePerPiece, 2);

            $tot = $db->prepare(
                'UPDATE daily_orders
                 SET delivered_pieces = ?, credits_taken_back = ?, total_amount = ?,
                     delivery_order_total = ?,
                     delivery_pricing_label = COALESCE(delivery_pricing_label, ?),
                     amount_collected = ?,
                     delivery_confirmed_at = NOW()
                 WHERE id = ?'
            );
            // Snapshot the billable total (after credits), not the pre-delivery ordered total.
            $tot->execute([$deliveredPieces, $creditsTakenBack, $total, $total, $summary['pricing_label'], $amountCollected, $dailyOrderId]);
            if ($tot->rowCount() === 0) {
                throw new Exception('Could not save the delivery invoice');
            }
            bakery_mark_delivery_delivered($db, $dailyOrderId);
            $db->commit();

            $ctx = bakery_operational_order_context($db, $dailyOrderId);
            $customerLabel = $ctx['customer_name'] ?? 'customer';
            $user = bakery_current_user();
            $actorName = $user['display_name'] ?? 'Driver';
            bakery_operational_log_delivery(
                $db,
                BAKERY_OP_DELIVERY_COMPLETED,
                $dailyOrderId,
                $actorName . ' completed delivery to ' . $customerLabel,
                [
                    'ordered_pieces' => $invoice['ordered_pieces'],
                    'delivered_pieces' => $deliveredPieces,
                    'credits_taken_back' => $creditsTakenBack,
                    'total' => $total,
                    'amount_collected' => $amountCollected,
                    'payment_collection' => $paymentCollection,
                    'photo_attached' => bakery_delivery_has_photo($db, $dailyOrderId),
                ],
                bakery_delivery_gps_payload($_POST)
            );
            bakery_customer_notify_delivery_completed($db, $dailyOrderId);
            bakery_customer_notify_invoice_available($db, $dailyOrderId);

            echo json_encode([
                'success' => true,
                'message' => 'Delivery confirmed.',
                'delivered_pieces' => $deliveredPieces,
                'credits_taken_back' => $creditsTakenBack,
                'billable_pieces' => $billablePieces,
                'price_per_piece' => $pricePerPiece,
                'total' => $total,
                'amount_collected' => $amountCollected,
                'payment_collection' => $paymentCollection,
            ]);
            break;

        case 'mark_delivered':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            bakery_mark_delivery_delivered($db, $dailyOrderId);

            $ctx = bakery_operational_order_context($db, $dailyOrderId);
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            $user = bakery_current_user();
            $actorName = $user['display_name'] ?? 'Driver';
            bakery_operational_log_delivery(
                $db,
                BAKERY_OP_DELIVERY_MARKED,
                $dailyOrderId,
                $actorName . ' marked delivery complete for ' . ($ctx['customer_name'] ?? 'customer'),
                [
                    'ordered_pieces' => $invoice['ordered_pieces'],
                    'delivered_pieces' => $invoice['ordered_pieces'],
                    'photo_attached' => bakery_delivery_has_photo($db, $dailyOrderId),
                ],
                bakery_delivery_gps_payload($_POST)
            );

            echo json_encode([
                'success' => true,
                'message' => 'Delivery marked as completed successfully'
            ]);
            break;

        case 'skip_stop':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            if (!isset($_POST['reason'])) {
                throw new Exception('A reason is required to skip this stop');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            bakery_skip_delivery_stop($db, $dailyOrderId, (string)$_POST['reason']);
            echo json_encode([
                'success' => true,
                'message' => function_exists('bakery_t') ? bakery_t('driver.skip_success') : 'Stop skipped — moving to your next stop.',
            ]);
            break;

        case 'report_failed_stop':
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            if ($dailyOrderId <= 0 && $assignmentId <= 0) {
                throw new Exception('Daily order ID is required');
            }
            if ($dailyOrderId > 0) {
                bakery_delivery_assert_driver_access($db, $dailyOrderId);
            }
            if ($assignmentId <= 0) {
                $find = $db->prepare(
                    'SELECT id FROM daily_order_assignments WHERE daily_order_id = ? ORDER BY id DESC LIMIT 1'
                );
                $find->execute([$dailyOrderId]);
                $assignmentId = (int)$find->fetchColumn();
            }
            $case = bakery_delivery_recovery_report_failure($db, $assignmentId, $_POST);
            echo json_encode([
                'success' => true,
                'message' => function_exists('bakery_t') ? bakery_t('exception_desk.reported') : 'Failed stop reported. HQ will recover it.',
                'recovery_case_id' => (int)($case['id'] ?? 0),
                'delivery_status' => 'failed',
            ]);
            break;

        case 'unskip_stop':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            bakery_unskip_delivery_stop($db, $dailyOrderId);
            echo json_encode([
                'success' => true,
                'message' => function_exists('bakery_t') ? bakery_t('driver.unskip_success') : 'Stop restored to your route.',
            ]);
            break;

        case 'reorder_route':
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $deliveryDate = trim((string)($_POST['date'] ?? ''));
            $rawIds = $_POST['order_ids'] ?? '';
            if (is_array($rawIds)) {
                $orderIds = $rawIds;
            } else {
                $orderIds = preg_split('/\s*,\s*/', trim((string)$rawIds), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $result = bakery_driver_reorder_remaining_stops($db, $driverId, $deliveryDate, $orderIds);
            echo json_encode([
                'success' => true,
                'message' => function_exists('bakery_t')
                    ? bakery_t('driver.route_order_saved')
                    : 'Route updated.',
                'stops' => $result['stops'],
                'next_daily_order_id' => $result['next_daily_order_id'],
            ]);
            break;

        case 'plan_search':
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $deliveryDate = trim((string)($_POST['date'] ?? ''));
            $query = (string)($_POST['q'] ?? $_POST['query'] ?? '');
            $found = bakery_driver_plan_search($db, $driverId, $deliveryDate, $query);
            echo json_encode([
                'success' => true,
                'query' => $found['query'],
                'unassigned' => $found['unassigned'],
                'usual' => $found['usual'],
                'matches' => $found['matches'],
            ]);
            break;

        case 'plan_add_stop':
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $deliveryDate = trim((string)($_POST['date'] ?? ''));
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $takeFromOther = (string)($_POST['take'] ?? $_POST['take_from_other'] ?? '') === '1';
            $added = bakery_driver_plan_add_stop($db, $driverId, $deliveryDate, $customerId, $takeFromOther);
            echo json_encode([
                'success' => !empty($added['ok']),
                'code' => $added['code'],
                'message' => $added['message'],
                'error' => empty($added['ok']) ? $added['message'] : null,
                'customer_id' => $added['customer_id'],
                'customer_name' => $added['customer_name'],
                'daily_order_id' => $added['daily_order_id'],
                'other_driver_name' => $added['other_driver_name'],
                'taken_from_other' => $added['taken_from_other'],
            ]);
            break;

        case 'plan_remove_stop':
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $deliveryDate = trim((string)($_POST['date'] ?? ''));
            $dailyOrderId = (int)($_POST['daily_order_id'] ?? 0);
            bakery_driver_remove_assignment($db, $dailyOrderId, $driverId, $deliveryDate);
            echo json_encode([
                'success' => true,
                'message' => function_exists('bakery_t')
                    ? bakery_t('driver.prep_removed')
                    : 'Stop removed from your route.',
            ]);
            break;

        case 'get_order_items':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);

            $orderStmt = $db->prepare(
                "SELECT do.id, c.name AS customer_name
                 FROM daily_orders do
                 JOIN customers c ON c.id = do.customer_id
                 WHERE do.id = ?"
            );
            $orderStmt->execute([$dailyOrderId]);
            $order = $orderStmt->fetch();
            if (!$order) {
                throw new Exception('Order not found');
            }

            $stmt = $db->prepare(
                "SELECT doi.id, doi.quantity, doi.unit_price, doi.line_total, p.name AS product_name
                 FROM daily_order_items doi
                 JOIN products p ON p.id = doi.product_id
                 WHERE doi.daily_order_id = ?
                 ORDER BY p.name"
            );
            $stmt->execute([$dailyOrderId]);
            $items = $stmt->fetchAll();

            $html = '<div style="padding: 10px;">';
            $html .= '<h3 style="margin-top:0;">Modify Order — ' . htmlspecialchars($order['customer_name']) . '</h3>';
            $html .= '<form id="modify-order-form">';
            if (empty($items)) {
                $html .= '<p>No items on this order.</p>';
            } else {
                foreach ($items as $item) {
                    $html .= '<div style="margin-bottom:12px;">';
                    $html .= '<label style="display:block;font-weight:600;">' .
                        htmlspecialchars($item['product_name']) .
                        ' <span style="font-weight:400;color:#666;">($' .
                        number_format((float)$item['unit_price'], 2) . ')</span></label>';
                    $html .= '<input type="number" min="0" step="1" name="quantity_' . (int)$item['id'] . '" value="' .
                        (int)$item['quantity'] . '" style="width:100%;padding:8px;">';
                    $html .= '</div>';
                }
            }
            $html .= '</form>';
            $html .= '<div style="display:flex;gap:8px;margin-top:16px;">';
            $html .= '<button type="button" onclick="saveModifiedOrder()" style="flex:1;padding:10px;background:#28a745;color:#fff;border:none;border-radius:4px;">Save & Deliver</button>';
            $html .= '<button type="button" onclick="closeCompleteDeliveryModal()" style="flex:1;padding:10px;background:#6c757d;color:#fff;border:none;border-radius:4px;">Cancel</button>';
            $html .= '</div></div>';

            echo json_encode(['success' => true, 'html' => $html, 'items' => $items]);
            break;

        case 'update_order_and_deliver':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            if (!isset($_POST['updates'])) {
                throw new Exception('Updates are required');
            }

            $dailyOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $dailyOrderId);
            $updates = json_decode($_POST['updates'], true);
            if (!is_array($updates)) {
                throw new Exception('Invalid updates payload');
            }

            $db->beginTransaction();

            foreach ($updates as $itemId => $quantity) {
                $itemId = (int)$itemId;
                $quantity = (int)$quantity;
                if ($quantity < 0) {
                    throw new Exception('Delivered quantity cannot be negative');
                }
                $upd = $db->prepare(
                    'UPDATE daily_order_items
                     SET delivered_quantity = ?, line_total = (? * unit_price)
                     WHERE id = ? AND daily_order_id = ?'
                );
                $upd->execute([$quantity, $quantity, $itemId, $dailyOrderId]);
            }

            $sum = $db->prepare(
                'SELECT COALESCE(SUM(delivered_quantity), 0),
                        COALESCE(SUM(line_total), 0)
                 FROM daily_order_items WHERE daily_order_id = ?'
            );
            $sum->execute([$dailyOrderId]);
            $totals = $sum->fetch(PDO::FETCH_NUM);
            $deliveredPieces = (int)($totals[0] ?? 0);
            $orderTotal = round((float)($totals[1] ?? 0), 2);

            $tot = $db->prepare(
                'UPDATE daily_orders
                 SET total_amount = ?, delivered_pieces = ?, delivery_order_total = ?,
                     delivery_confirmed_at = NOW()
                 WHERE id = ?'
            );
            $tot->execute([$orderTotal, $deliveredPieces, $orderTotal, $dailyOrderId]);

            bakery_mark_delivery_delivered($db, $dailyOrderId);

            $db->commit();

            $ctx = bakery_operational_order_context($db, $dailyOrderId);
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            $user = bakery_current_user();
            $actorName = $user['display_name'] ?? 'Driver';
            bakery_operational_log_delivery(
                $db,
                BAKERY_OP_DELIVERY_MODIFIED,
                $dailyOrderId,
                $actorName . ' modified order and delivered to ' . ($ctx['customer_name'] ?? 'customer'),
                [
                    'ordered_pieces' => $invoice['ordered_pieces'],
                    'delivered_pieces' => $deliveredPieces,
                    'total' => $orderTotal,
                    'updates' => $updates,
                    'photo_attached' => bakery_delivery_has_photo($db, $dailyOrderId),
                ],
                bakery_delivery_gps_payload($_POST)
            );

            echo json_encode([
                'success' => true,
                'message' => 'Order updated and delivery completed',
                'total' => $orderTotal
            ]);
            break;

        default:
            throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
}
