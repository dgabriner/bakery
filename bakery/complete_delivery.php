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
require_once __DIR__ . '/includes/delivery_skip.php';
require_once __DIR__ . '/includes/customer_portal.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
}

/**
 * Mark assignment and parent daily_order as delivered in one transaction.
 * Safe to call when caller already holds an open transaction.
 */
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

/**
 * Unified Pan Dulce catalog price when all Pan Dulce products share one rate.
 * Returns 0 when the catalog is empty or mixed.
 */
function bakery_pan_dulce_catalog_standard_price(PDO $db): float {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $row = $db->query(
            "SELECT MIN(p.price) AS min_price, MAX(p.price) AS max_price
             FROM products p
             JOIN dough_types dt ON dt.id = p.dough_type_id
             JOIN product_lines pl ON pl.id = dt.product_line_id
             WHERE pl.name = 'Pan Dulce'"
        )->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cached = 0.0;
        return $cached;
    }
    if (!$row || $row['min_price'] === null) {
        $cached = 0.0;
        return $cached;
    }
    $min = round((float)$row['min_price'], 2);
    $max = round((float)$row['max_price'], 2);
    $cached = ($min > 0 && abs($min - $max) < 0.005) ? $min : 0.0;
    return $cached;
}

/**
 * Resolve a zero/blank line price using store pan dulce default, customer
 * pricing tiers, and catalog standard — so drivers are not asked for a price
 * that is already on file.
 */
function bakery_delivery_resolve_line_unit_price(PDO $db, array $order, array $item): float {
    $unitPrice = round((float)($item['unit_price'] ?? 0), 2);
    if ($unitPrice > 0) {
        return $unitPrice;
    }

    $customer = [
        'id' => (int)($order['customer_id'] ?? 0),
        'pricing_tier' => $order['pricing_tier'] ?? 'retail',
        'default_pan_dulce_price' => $order['default_pan_dulce_price'] ?? null,
    ];
    $product = [
        'id' => (int)($item['product_id'] ?? 0),
        'price' => (float)($item['standard_price'] ?? $item['price'] ?? 0),
        'wholesale_price' => $item['wholesale_price'] ?? null,
        'product_line_name' => $item['product_line_name'] ?? '',
    ];

    $resolved = round((float)bakery_resolve_customer_price($db, $customer, $product), 2);
    if ($resolved > 0) {
        return $resolved;
    }

    $catalogStandard = bakery_pan_dulce_catalog_standard_price($db);
    if ($catalogStandard > 0) {
        return $catalogStandard;
    }

    return 0.0;
}

function bakery_delivery_invoice(PDO $db, int $dailyOrderId): array {
    $orderStmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status, do.total_amount,
                do.delivery_order_total, do.delivery_pricing_label,
                do.delivery_confirmed_at, do.delivered_pieces,
                do.credits_taken_back, c.name AS customer_name,
                c.address AS customer_address, c.phone AS customer_phone,
                c.default_pan_dulce_price, c.pricing_tier,
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
                p.price AS standard_price, p.wholesale_price,
                pl.name AS product_line_name,
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
    $storePrice = isset($order['default_pan_dulce_price']) && $order['default_pan_dulce_price'] !== ''
        ? (float)$order['default_pan_dulce_price']
        : 0.0;
    foreach ($items as &$item) {
        $quantity = (int)$item['quantity'];
        // Older daily orders can have a zero-priced line even though the store
        // default pan dulce price or catalog rate is configured. Resolve it here
        // so drivers are not blocked on the invoice step.
        $unitPrice = bakery_delivery_resolve_line_unit_price($db, $order, $item);
        $lineTotal = round($quantity * $unitPrice, 2);
        $orderedPieces += $quantity;
        $storedOrderTotal += $lineTotal;
        $item['quantity'] = $quantity;
        $item['unit_price'] = $unitPrice;
        $item['line_total'] = $lineTotal;
        $isPanDulce = strcasecmp((string)($item['product_line_name'] ?? ''), 'Pan Dulce') === 0;
        if ($isPanDulce) {
            $hasPanDulce = true;
        }
        if ($storePrice > 0 && abs($unitPrice - $storePrice) < 0.005) {
            $hasStorePrice = true;
        } elseif (abs($unitPrice - (float)$item['standard_price']) < 0.005) {
            $hasStandardPrice = true;
        }
    }
    unset($item);

    $pricingLabel = (string)($order['delivery_pricing_label'] ?? '');
    if ($pricingLabel === '') {
        if ($hasStorePrice && $hasStandardPrice) {
            $pricingLabel = 'Mixed Pan Dulce pricing';
        } elseif ($hasStorePrice) {
            $pricingLabel = 'Store price';
        } elseif ($hasPanDulce || $hasStandardPrice) {
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

/**
 * Known piece price for a customer when line prices are blank: store default
 * pan dulce rate, then unified catalog standard.
 */
function bakery_delivery_known_fallback_price(PDO $db, array $order): float {
    $storePrice = isset($order['default_pan_dulce_price']) && $order['default_pan_dulce_price'] !== ''
        ? round((float)$order['default_pan_dulce_price'], 2)
        : 0.0;
    if ($storePrice > 0) {
        return $storePrice;
    }
    return bakery_pan_dulce_catalog_standard_price($db);
}

/** Persist valid catalog/store prices for historical zero-priced order lines. */
function bakery_delivery_repair_missing_item_prices(PDO $db, int $dailyOrderId): void {
    $orderStmt = $db->prepare(
        'SELECT do.customer_id, c.default_pan_dulce_price, c.pricing_tier
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return;
    }

    $stmt = $db->prepare(
        "SELECT doi.id, doi.product_id, doi.quantity, doi.unit_price,
                p.price AS standard_price, p.wholesale_price,
                pl.name AS product_line_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ? AND doi.quantity > 0 AND doi.unit_price <= 0"
    );
    $stmt->execute([$dailyOrderId]);
    $update = $db->prepare(
        'UPDATE daily_order_items SET unit_price = ?, line_total = ? WHERE id = ? AND daily_order_id = ?'
    );
    $fallback = bakery_delivery_known_fallback_price($db, $order);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $price = bakery_delivery_resolve_line_unit_price($db, $order, $item);
        if ($price <= 0 && $fallback > 0) {
            $price = $fallback;
        }
        if ($price > 0) {
            $update->execute([$price, round((int)$item['quantity'] * $price, 2), (int)$item['id'], $dailyOrderId]);
        }
    }
}

/**
 * When line repair still leaves the invoice unpriced, apply the store default
 * pan dulce (or catalog standard) as the order piece price so drivers can
 * complete the stop without typing a known rate.
 *
 * @return array{ordered_pieces:int,order_total:float,average_price:float,pricing_label:string}|null
 */
function bakery_delivery_apply_known_price_if_missing(PDO $db, int $dailyOrderId): ?array {
    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    if (!bakery_delivery_pricing_missing($invoice)) {
        return null;
    }
    $fallback = bakery_delivery_known_fallback_price($db, $invoice['order']);
    if ($fallback <= 0) {
        return null;
    }
    $summary = bakery_apply_driver_price($db, $dailyOrderId, $fallback);
    $label = (isset($invoice['order']['default_pan_dulce_price'])
        && (float)$invoice['order']['default_pan_dulce_price'] > 0)
        ? 'Store price'
        : 'Standard price';
    $db->prepare('UPDATE daily_orders SET delivery_pricing_label = ? WHERE id = ?')
        ->execute([$label, $dailyOrderId]);
    $summary['pricing_label'] = $label;
    return $summary;
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
    bakery_delivery_repair_missing_item_prices($db, $dailyOrderId);
    bakery_delivery_apply_known_price_if_missing($db, $dailyOrderId);
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

/**
 * Confirm a delivery: assignment + order status, line delivered quantities,
 * frozen billable snapshot, and FG credit-return movements in one transaction.
 *
 * @param array{price_per_piece?:float|int|string, amount_collected?:float|int|string|null} $options
 * @return array<string,mixed>
 */
function bakery_confirm_delivery(
    PDO $db,
    int $dailyOrderId,
    int $deliveredPieces,
    int $creditsTakenBack,
    array $options = []
): array {
    if ($dailyOrderId <= 0) {
        throw new Exception('Daily order ID is required');
    }
    if ($deliveredPieces < 0 || $creditsTakenBack < 0) {
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

    $driverPrice = null;
    if ($summary['pricing_missing']) {
        $known = bakery_delivery_known_fallback_price($db, $invoice['order']);
        if ($known > 0) {
            // Store / catalog price is on file — do not block the driver.
            $driverPrice = $known;
        } elseif (!array_key_exists('price_per_piece', $options)) {
            throw new Exception('Enter a price per piece for this customer');
        } else {
            $driverPrice = filter_var($options['price_per_piece'], FILTER_VALIDATE_FLOAT);
            if ($driverPrice === false || $driverPrice <= 0) {
                throw new Exception('Enter a valid price per piece greater than zero');
            }
        }
    } elseif (array_key_exists('price_per_piece', $options)) {
        $entered = filter_var($options['price_per_piece'], FILTER_VALIDATE_FLOAT);
        if ($entered !== false && $entered > 0) {
            $driverPrice = $entered;
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
        if (!array_key_exists('amount_collected', $options)) {
            throw new Exception('Cash collected amount is required for COD customers');
        }
        $amountCollected = filter_var($options['amount_collected'], FILTER_VALIDATE_FLOAT);
        if ($amountCollected === false || $amountCollected < 0) {
            throw new Exception('Enter a valid cash amount collected');
        }
        $amountCollected = round($amountCollected, 2);
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        bakery_delivery_repair_missing_item_prices($db, $dailyOrderId);
        $appliedKnown = bakery_delivery_apply_known_price_if_missing($db, $dailyOrderId);
        $invoice = bakery_delivery_invoice($db, $dailyOrderId);
        $summary = [
            'ordered_pieces' => $invoice['ordered_pieces'],
            'order_total' => $invoice['order_total'],
            'average_price' => $invoice['average_price'],
            'pricing_label' => $appliedKnown['pricing_label'] ?? $invoice['pricing_label'],
            'pricing_missing' => bakery_delivery_pricing_missing($invoice),
        ];

        if ($summary['pricing_missing']) {
            if ($driverPrice === null || $driverPrice <= 0) {
                throw new Exception('Enter a price per piece for this customer');
            }
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
        $tot->execute([
            $deliveredPieces,
            $creditsTakenBack,
            $total,
            $total,
            $summary['pricing_label'],
            $amountCollected,
            $dailyOrderId,
        ]);
        $verify = $db->prepare('SELECT id FROM daily_orders WHERE id = ?');
        $verify->execute([$dailyOrderId]);
        if (!$verify->fetchColumn()) {
            throw new Exception('Could not save the delivery invoice');
        }
        bakery_mark_delivery_delivered($db, $dailyOrderId);
        bakery_inventory_record_delivery_credit_returns(
            $db,
            $dailyOrderId,
            $creditsTakenBack
        );
        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return [
        'success' => true,
        'message' => 'Delivery confirmed.',
        'delivered_pieces' => $deliveredPieces,
        'credits_taken_back' => $creditsTakenBack,
        'billable_pieces' => $billablePieces,
        'price_per_piece' => $pricePerPiece,
        'total' => $total,
        'amount_collected' => $amountCollected,
        'payment_collection' => $paymentCollection,
        'ordered_pieces' => (int)$invoice['ordered_pieces'],
        'invoice' => $invoice,
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

            $confirmOptions = [];
            if (isset($_POST['price_per_piece'])) {
                $confirmOptions['price_per_piece'] = $_POST['price_per_piece'];
            }
            if (isset($_POST['amount_collected'])) {
                $confirmOptions['amount_collected'] = $_POST['amount_collected'];
            }
            $confirmed = bakery_confirm_delivery(
                $db,
                $dailyOrderId,
                (int)$deliveredPieces,
                (int)$creditsTakenBack,
                $confirmOptions
            );

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
                    'ordered_pieces' => $confirmed['ordered_pieces'],
                    'delivered_pieces' => $confirmed['delivered_pieces'],
                    'credits_taken_back' => $confirmed['credits_taken_back'],
                    'total' => $confirmed['total'],
                    'amount_collected' => $confirmed['amount_collected'],
                    'payment_collection' => $confirmed['payment_collection'],
                    'photo_attached' => bakery_delivery_has_photo($db, $dailyOrderId),
                ],
                bakery_delivery_gps_payload($_POST)
            );
            bakery_customer_notify_delivery_completed($db, $dailyOrderId);
            bakery_customer_notify_invoice_available($db, $dailyOrderId);

            echo json_encode([
                'success' => true,
                'message' => $confirmed['message'],
                'delivered_pieces' => $confirmed['delivered_pieces'],
                'credits_taken_back' => $confirmed['credits_taken_back'],
                'billable_pieces' => $confirmed['billable_pieces'],
                'price_per_piece' => $confirmed['price_per_piece'],
                'total' => $confirmed['total'],
                'amount_collected' => $confirmed['amount_collected'],
                'payment_collection' => $confirmed['payment_collection'],
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
                'other_routes' => $found['other_routes'],
                'other_route_count' => $found['other_route_count'],
                'take_approval' => $found['take_approval'],
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
                'filled_standard' => !empty($added['filled_standard']),
                'filled_standard_source' => $added['filled_standard_source'] ?? 'none',
                'take_approval' => $added['take_approval'] ?? bakery_driver_plan_take_policy($db),
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
