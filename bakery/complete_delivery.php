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
require_once __DIR__ . '/includes/audit_log.php';
require_once __DIR__ . '/includes/product_inventory.php';
require_once __DIR__ . '/includes/operational_timeline.php';
require_once __DIR__ . '/includes/customer_notifications.php';
require_once __DIR__ . '/includes/driver_assignments.php';
require_once __DIR__ . '/includes/driver_route_prep.php';
require_once __DIR__ . '/includes/delivery_recovery.php';
require_once __DIR__ . '/includes/delivery_skip.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/client_request_id.php';
require_once __DIR__ . '/includes/daily_orders_actions.php';
require_once __DIR__ . '/includes/cod_square_guard.php';
require_once __DIR__ . '/includes/billing_header.php';
require_once __DIR__ . '/includes/delivery_proof.php';
require_once __DIR__ . '/includes/special_orders.php';

/**
 * Browser navigation (GET/HEAD) should not dump the JSON API.
 * Real delivery calls are POST and stay JSON.
 *
 * @param array<string, mixed> $query
 * @return array{kind:'redirect', location:string}|array{kind:'page', title:string, body:string, href:string, link:string}
 */
function bakery_complete_delivery_browser_get_plan(PDO $db, array $query): array
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $orderId = (int)($query['order_id'] ?? $query['daily_order_id'] ?? 0);
    if ($orderId > 0 && function_exists('table_exists') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare('SELECT order_date FROM daily_orders WHERE id = ?');
        $stmt->execute([$orderId]);
        $date = $stmt->fetchColumn();
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [
                'kind' => 'redirect',
                'location' => $base . 'driver.php?date=' . rawurlencode($date),
            ];
        }
    }
    $missing = $orderId > 0;
    return [
        'kind' => 'page',
        'title' => bakery_t('ux.delivery_get.title'),
        'body' => bakery_t($missing ? 'ux.delivery_get.missing' : 'ux.delivery_get.body'),
        'href' => $base . 'driver.php',
        'link' => bakery_t('ux.delivery_get.link'),
    ];
}

function bakery_complete_delivery_render_get_page(array $plan): void
{
    $page_title = (string)($plan['title'] ?? '');
    require __DIR__ . '/includes/header.php';
    require __DIR__ . '/includes/nav.php';
    echo '<main class="container" style="max-width:40rem;padding:1.5rem 1rem 2rem">';
    echo '<h1>' . htmlspecialchars((string)$plan['title'], ENT_QUOTES, 'UTF-8') . '</h1>';
    echo '<p>' . htmlspecialchars((string)$plan['body'], ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a class="btn btn-primary" href="' . htmlspecialchars((string)$plan['href'], ENT_QUOTES, 'UTF-8') . '">';
    echo htmlspecialchars((string)$plan['link'], ENT_QUOTES, 'UTF-8') . '</a></p>';
    echo '</main>';
    require __DIR__ . '/includes/footer.php';
}

/** Stable English label stored on the order. The confirm step translates it. */
function bakery_delivery_pricing_label_code(bool $hasStorePrice, bool $hasStandardPrice, bool $hasPanDulce): string
{
    if ($hasStorePrice && $hasStandardPrice) {
        return 'Mixed Pan Dulce pricing';
    }
    if ($hasStorePrice) {
        return 'Store price';
    }
    if ($hasPanDulce || $hasStandardPrice) {
        return 'Standard price';
    }
    return 'Order pricing';
}

/** Translate the stable pricing labels shown on the confirm step. */
function bakery_delivery_pricing_label_display(string $label): string
{
    $map = [
        'Mixed Pan Dulce pricing' => 'ux.pricing.mixed',
        'Store price' => 'ux.pricing.store',
        'Standard price' => 'ux.pricing.standard',
        'Order pricing' => 'ux.pricing.order',
        'Driver-entered price' => 'ux.pricing.driver',
    ];
    if (!isset($map[$label]) || !function_exists('bakery_t')) {
        return $label;
    }
    return bakery_t($map[$label]);
}

if (PHP_SAPI !== 'cli') {
    $browserMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($browserMethod === 'GET' || $browserMethod === 'HEAD') {
        $browserDb = check_mysql_connection();
        $browserPlan = bakery_complete_delivery_browser_get_plan($browserDb, $_GET);
        if ($browserPlan['kind'] === 'redirect') {
            header('Location: ' . $browserPlan['location']);
            exit;
        }
        bakery_complete_delivery_render_get_page($browserPlan);
        exit;
    }
    header('Content-Type: application/json');
    error_reporting(0);
    ini_set('display_errors', 0);
}

/**
 * Mark assignment and parent daily_order as delivered in one transaction.
 * Safe to call when caller already holds an open transaction.
 */
function bakery_mark_delivery_delivered(PDO $db, int $dailyOrderId, array $lineQuantities = []): void {
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

        bakery_apply_delivery_line_quantities($db, $dailyOrderId, $lineQuantities);

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
 * A total is well over the order at half again the ordered pieces, or any
 * pieces when nothing was ordered.
 */
function bakery_delivery_total_is_well_over_ordered(int $delivered, int $ordered): bool
{
    if ($delivered < 0) {
        $delivered = 0;
    }
    if ($ordered <= 0) {
        return $delivered > 0;
    }
    return $delivered > $ordered && ($delivered - $ordered) * 2 >= $ordered;
}

function bakery_delivery_product_count_mismatch_message(): string
{
    $fallback = 'Product counts must add up to the delivered total, or leave every product blank.';
    if (!function_exists('bakery_t')) {
        return $fallback;
    }
    $text = bakery_t('driver.product_counts_mismatch');
    if ($text === '' || $text === 'driver.product_counts_mismatch') {
        return $fallback;
    }
    return $text;
}

/**
 * Keep saved delivered_quantity values when every ordered line has one,
 * none of them are marked estimated, and they already add up to the
 * confirmed total. Returns null when the mix is still unknown.
 *
 * @param list<array<string, mixed>> $items
 * @return array{lines:list<array{id:int,delivered:int,estimated:int}>,unallocated:int}|null
 */
function bakery_delivery_saved_line_plan(array $items, int $targetPieces): ?array
{
    $lines = [];
    $sum = 0;
    $any = false;
    foreach ($items as $item) {
        $ordered = max(0, (int)($item['quantity'] ?? 0));
        $raw = $item['delivered_quantity'] ?? null;
        $has = $raw !== null && $raw !== '';
        if ((int)($item['delivered_quantity_estimated'] ?? 0) === 1) {
            return null;
        }
        if ($ordered > 0 && !$has) {
            return null;
        }
        $delivered = 0;
        if ($has) {
            $parsed = filter_var($raw, FILTER_VALIDATE_INT);
            if ($parsed === false || (int)$parsed < 0) {
                return null;
            }
            $delivered = (int)$parsed;
            $any = true;
            $sum += $delivered;
        }
        $lines[] = [
            'id' => (int)$item['id'],
            'delivered' => $delivered,
            'estimated' => 0,
        ];
    }
    if (!$any || $sum !== $targetPieces) {
        return null;
    }
    return ['lines' => $lines, 'unallocated' => 0];
}

/**
 * Decide line counts for one confirmed total.
 *
 * Counts the driver entered are stored as given. Line counts already saved
 * that add up to the confirmed total are kept. A total above the order does
 * not invent a per-product overage: each product stays at its ordered count
 * and the extra pieces are unallocated. A short total with no saved line
 * counts is still split in proportion, and those lines are estimated.
 *
 * @param list<array<string, mixed>> $items
 * @param array<int, int> $lineQuantities
 * @return array{lines:list<array{id:int,delivered:int,estimated:int}>,unallocated:int}
 */
function bakery_delivery_quantity_plan(array $items, int $targetPieces, array $lineQuantities = []): array
{
    $targetPieces = max(0, $targetPieces);
    $orderedTotal = 0;
    $knownIds = [];
    foreach ($items as $item) {
        $id = (int)$item['id'];
        $orderedTotal += max(0, (int)$item['quantity']);
        $knownIds[$id] = true;
    }

    if ($lineQuantities === []) {
        $saved = bakery_delivery_saved_line_plan($items, $targetPieces);
        if ($saved !== null) {
            return $saved;
        }
    }

    if ($lineQuantities !== []) {
        $sum = 0;
        $counts = [];
        foreach ($lineQuantities as $id => $count) {
            $id = (int)$id;
            if (!isset($knownIds[$id]) || filter_var($count, FILTER_VALIDATE_INT) === false || (int)$count < 0) {
                throw new Exception(bakery_delivery_product_count_mismatch_message());
            }
            $counts[$id] = (int)$count;
            $sum += (int)$count;
        }
        if ($sum !== $targetPieces) {
            throw new Exception(bakery_delivery_product_count_mismatch_message());
        }
        $lines = [];
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $lines[] = [
                'id' => $id,
                'delivered' => (int)($counts[$id] ?? 0),
                'estimated' => 0,
            ];
        }
        return ['lines' => $lines, 'unallocated' => 0];
    }

    if ($targetPieces > $orderedTotal) {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'id' => (int)$item['id'],
                'delivered' => max(0, (int)$item['quantity']),
                'estimated' => 0,
            ];
        }
        return ['lines' => $lines, 'unallocated' => $targetPieces - $orderedTotal];
    }

    if ($targetPieces === $orderedTotal || $orderedTotal <= 0) {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'id' => (int)$item['id'],
                'delivered' => max(0, (int)$item['quantity']),
                'estimated' => 0,
            ];
        }
        return ['lines' => $lines, 'unallocated' => 0];
    }

    $allocations = bakery_delivery_spread_short_quantities($items, $orderedTotal, $targetPieces);
    $lines = [];
    foreach ($items as $idx => $item) {
        $lines[] = [
            'id' => (int)$item['id'],
            'delivered' => (int)($allocations[$idx] ?? 0),
            'estimated' => 1,
        ];
    }
    return ['lines' => $lines, 'unallocated' => 0];
}

/**
 * Largest-remainder split of a short delivered total. Each share stays at or
 * under that product's ordered quantity.
 *
 * @param list<array<string, mixed>> $items
 * @return array<int, int>
 */
function bakery_delivery_spread_short_quantities(array $items, int $orderedTotal, int $targetPieces): array
{
    $allocations = [];
    $allocated = 0;
    foreach ($items as $idx => $item) {
        $ordered = max(0, (int)$item['quantity']);
        if ($ordered <= 0 || $orderedTotal <= 0) {
            $allocations[$idx] = 0;
            continue;
        }
        $share = ($ordered / $orderedTotal) * $targetPieces;
        $whole = (int)floor($share);
        $allocations[$idx] = $whole;
        $allocated += $whole;
    }

    $remainder = $targetPieces - $allocated;
    if ($remainder > 0 && $orderedTotal > 0) {
        $fractions = [];
        foreach ($items as $idx => $item) {
            $ordered = max(0, (int)$item['quantity']);
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

    return $allocations;
}

function bakery_delivery_set_unallocated_pieces(PDO $db, int $dailyOrderId, int $pieces): void
{
    if (!function_exists('column_exists') || !column_exists($db, 'daily_orders', 'unallocated_delivered_pieces')) {
        return;
    }
    $stmt = $db->prepare('UPDATE daily_orders SET unallocated_delivered_pieces = ? WHERE id = ?');
    $stmt->execute([max(0, $pieces), $dailyOrderId]);
}

/**
 * Align line delivered_quantity with the confirmed total.
 *
 * @param array<int, int> $lineQuantities
 */
function bakery_apply_delivery_line_quantities(PDO $db, int $dailyOrderId, array $lineQuantities = []): void {
    $orderStmt = $db->prepare(
        'SELECT delivered_pieces FROM daily_orders WHERE id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $headerPieces = $orderStmt->fetchColumn();
    $headerPieces = ($headerPieces !== false && $headerPieces !== null && $headerPieces !== '')
        ? (int)$headerPieces
        : null;

    $estimatedSelect = '';
    if (function_exists('column_exists') && column_exists($db, 'daily_order_items', 'delivered_quantity_estimated')) {
        $estimatedSelect = ', delivered_quantity_estimated';
    }
    $itemStmt = $db->prepare(
        'SELECT id, quantity, delivered_quantity, unit_price' . $estimatedSelect . '
         FROM daily_order_items
         WHERE daily_order_id = ?
         ORDER BY id'
    );
    $itemStmt->execute([$dailyOrderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderedTotal = 0;
    foreach ($items as $item) {
        $orderedTotal += max(0, (int)$item['quantity']);
    }
    $targetPieces = $headerPieces ?? $orderedTotal;
    $plan = bakery_delivery_quantity_plan($items, $targetPieces, $lineQuantities);

    $hasEstimated = function_exists('column_exists')
        && column_exists($db, 'daily_order_items', 'delivered_quantity_estimated');
    $sql = 'UPDATE daily_order_items
            SET delivered_quantity = ?, line_total = ROUND(? * unit_price, 2)';
    if ($hasEstimated) {
        $sql .= ', delivered_quantity_estimated = ?';
    }
    $sql .= ' WHERE id = ? AND daily_order_id = ?';
    $upd = $db->prepare($sql);
    foreach ($plan['lines'] as $line) {
        $params = [(int)$line['delivered'], (int)$line['delivered']];
        if ($hasEstimated) {
            $params[] = (int)$line['estimated'];
        }
        $params[] = (int)$line['id'];
        $params[] = $dailyOrderId;
        $upd->execute($params);
    }
    bakery_delivery_set_unallocated_pieces($db, $dailyOrderId, (int)$plan['unallocated']);
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
 *
 * @return array{price:float,source:string}
 */
function bakery_delivery_resolve_missing_price(PDO $db, array $order, array $item): array
{
    $unitPrice = round((float)($item['unit_price'] ?? 0), 2);
    if ($unitPrice > 0) {
        return ['price' => $unitPrice, 'source' => 'line'];
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
        return ['price' => $resolved, 'source' => 'customer_catalog'];
    }

    $catalogStandard = bakery_pan_dulce_catalog_standard_price($db);
    if ($catalogStandard > 0) {
        return ['price' => $catalogStandard, 'source' => 'pan_dulce_catalog_standard'];
    }

    return ['price' => 0.0, 'source' => 'unset'];
}

function bakery_delivery_resolve_line_unit_price(PDO $db, array $order, array $item): float
{
    return bakery_delivery_resolve_missing_price($db, $order, $item)['price'];
}

/**
 * @param float|null $oldPrice
 */
function bakery_delivery_log_price_repair(
    PDO $db,
    array $order,
    int $dailyOrderId,
    array $item,
    $oldPrice,
    float $newPrice,
    string $source
): void {
    if (!function_exists('bakery_record_operational_event')) {
        return;
    }
    $lineId = (int)($item['id'] ?? 0);
    $eventType = defined('BAKERY_OP_LINE_PRICE_REPAIRED')
        ? BAKERY_OP_LINE_PRICE_REPAIRED
        : 'line_price_repaired';
    bakery_record_operational_event(
        $db,
        $eventType,
        'Filled unset line price on line ' . $lineId . ' from ' . $source,
        [
            'operational_date' => $order['order_date'] ?? null,
            'customer_id' => (int)($order['customer_id'] ?? 0) ?: null,
            'daily_order_id' => $dailyOrderId,
            'product_id' => (int)($item['product_id'] ?? 0) ?: null,
            'metadata' => [
                'line_id' => $lineId,
                'old_price' => $oldPrice,
                'new_price' => round($newPrice, 2),
                'source' => $source,
            ],
        ]
    );
}

function bakery_delivery_invoice(PDO $db, int $dailyOrderId): array {
    $orderStmt = $db->prepare(
        'SELECT do.id, do.customer_id, do.order_date, do.status, do.total_amount,
                do.delivery_order_total, do.delivery_pricing_label,
                do.delivery_confirmed_at, do.delivered_pieces,
                do.credits_taken_back, c.name AS customer_name,
                c.address AS customer_address, c.phone AS customer_phone,
                c.default_pan_dulce_price, c.pricing_tier,
                ' . bakery_payment_terms_pan_dulce_collection_sql($db) . ' AS payment_collection,
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

    $noChargeSelect = bakery_order_line_no_charge_select($db);
    $itemStmt = $db->prepare(
        "SELECT doi.id, doi.product_id, doi.quantity, doi.delivered_quantity,
                doi.unit_price, doi.line_total, {$noChargeSelect}, p.name AS product_name,
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
        // so drivers are not blocked on the invoice step. A line staff marked
        // no-charge keeps the explicit $0.
        $isNoCharge = bakery_order_line_is_no_charge($item);
        $unitPrice = $isNoCharge
            ? 0.0
            : bakery_delivery_resolve_line_unit_price($db, $order, $item);
        $lineTotal = round($quantity * $unitPrice, 2);
        $orderedPieces += $quantity;
        $storedOrderTotal += $lineTotal;
        $item['quantity'] = $quantity;
        $item['is_no_charge'] = $isNoCharge ? 1 : 0;
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

    $specialItems = function_exists('bakery_special_order_invoice_lines')
        ? bakery_special_order_invoice_lines($db, $dailyOrderId)
        : [];
    $specialTotal = 0.0;
    foreach ($specialItems as $specialItem) {
        $specialTotal += (float)($specialItem['line_total'] ?? 0);
    }
    $specialTotal = round($specialTotal, 2);

    $pricingLabel = (string)($order['delivery_pricing_label'] ?? '');
    if ($pricingLabel === '') {
        $pricingLabel = bakery_delivery_pricing_label_code($hasStorePrice, $hasStandardPrice, $hasPanDulce);
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
        'special_items' => $specialItems,
        'special_total' => $specialTotal,
    ];
}

function bakery_delivery_pricing_missing(array $invoice): bool {
    $chargeablePieces = 0;
    foreach ($invoice['items'] as $item) {
        if ((int)$item['quantity'] <= 0 || bakery_order_line_is_no_charge($item)) {
            continue;
        }
        $chargeablePieces += (int)$item['quantity'];
        if ((float)$item['unit_price'] <= 0) {
            return true;
        }
    }
    if ($chargeablePieces <= 0) {
        return false;
    }
    return $invoice['order_total'] <= 0 || $invoice['average_price'] <= 0;
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

/**
 * Persist valid catalog/store prices for lines whose price was never set.
 *
 * unit_price defaults to 0.00 and writers store 0 when the catalog price is
 * unknown, so an unmarked 0 is still treated as unset. Lines with is_no_charge
 * keep $0. NULL is also unset (the column allows it; current writers do not
 * insert it).
 */
function bakery_delivery_repair_missing_item_prices(PDO $db, int $dailyOrderId): void {
    $orderStmt = $db->prepare(
        'SELECT do.customer_id, do.order_date, c.default_pan_dulce_price, c.pricing_tier
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $orderStmt->execute([$dailyOrderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return;
    }

    bakery_delivery_enforce_no_charge_prices($db, $dailyOrderId, $order);

    $noChargeClause = bakery_order_line_no_charge_ready($db) ? ' AND doi.is_no_charge = 0' : '';
    $stmt = $db->prepare(
        "SELECT doi.id, doi.product_id, doi.quantity, doi.unit_price,
                p.price AS standard_price, p.wholesale_price,
                pl.name AS product_line_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ? AND doi.quantity > 0
           AND (doi.unit_price IS NULL OR doi.unit_price <= 0)" . $noChargeClause
    );
    $stmt->execute([$dailyOrderId]);
    $update = $db->prepare(
        'UPDATE daily_order_items SET unit_price = ?, line_total = ? WHERE id = ? AND daily_order_id = ?'
    );
    $fallback = bakery_delivery_known_fallback_price($db, $order);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        if (bakery_order_line_is_no_charge($item)) {
            continue;
        }
        $fill = bakery_delivery_resolve_missing_price($db, $order, $item);
        $price = $fill['price'];
        $source = $fill['source'];
        if ($price <= 0 && $fallback > 0) {
            $price = $fallback;
            $source = 'known_fallback';
        }
        if ($price > 0) {
            $oldPrice = $item['unit_price'] === null ? null : round((float)$item['unit_price'], 2);
            $update->execute([$price, round((int)$item['quantity'] * $price, 2), (int)$item['id'], $dailyOrderId]);
            bakery_audit_daily_line_diff(
                $db,
                ['id' => (int)$item['id'], 'quantity' => $item['quantity'], 'unit_price' => $oldPrice],
                ['id' => (int)$item['id'], 'quantity' => $item['quantity'], 'unit_price' => $price]
            );
            bakery_delivery_log_price_repair($db, $order, $dailyOrderId, $item, $oldPrice, $price, $source);
        }
    }
}

/** Keep a deliberate no-charge mark at $0 even if a later edit wrote a price. */
function bakery_delivery_enforce_no_charge_prices(PDO $db, int $dailyOrderId, array $order): void
{
    if (!bakery_order_line_no_charge_ready($db)) {
        return;
    }
    $stmt = $db->prepare(
        'SELECT id, product_id, quantity, unit_price
         FROM daily_order_items
         WHERE daily_order_id = ? AND is_no_charge = 1 AND quantity > 0
           AND (unit_price IS NULL OR unit_price <> 0 OR line_total <> 0)'
    );
    $stmt->execute([$dailyOrderId]);
    $update = $db->prepare(
        'UPDATE daily_order_items SET unit_price = 0, line_total = 0 WHERE id = ? AND daily_order_id = ?'
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $oldPrice = $item['unit_price'] === null ? null : round((float)$item['unit_price'], 2);
        $update->execute([(int)$item['id'], $dailyOrderId]);
        bakery_audit_daily_line_diff(
            $db,
            ['id' => (int)$item['id'], 'quantity' => $item['quantity'], 'unit_price' => $oldPrice],
            ['id' => (int)$item['id'], 'quantity' => $item['quantity'], 'unit_price' => 0]
        );
        if ($oldPrice !== null && abs($oldPrice) < 0.005) {
            continue;
        }
        bakery_delivery_log_price_repair($db, $order, $dailyOrderId, $item, $oldPrice, 0.0, 'no_charge');
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

    $noChargeSelect = bakery_order_line_no_charge_ready($db)
        ? 'is_no_charge'
        : '0 AS is_no_charge';
    $itemStmt = $db->prepare(
        "SELECT id, quantity, unit_price, {$noChargeSelect} FROM daily_order_items WHERE daily_order_id = ?"
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
        $orderedPieces += $quantity;
        if (bakery_order_line_is_no_charge($item)) {
            continue;
        }
        $lineTotal = round($quantity * $pricePerPiece, 2);
        $updateStmt->execute([$pricePerPiece, $lineTotal, (int)$item['id'], $dailyOrderId]);
        bakery_audit_daily_line_diff(
            $db,
            ['id' => (int)$item['id'], 'quantity' => $quantity, 'unit_price' => $item['unit_price'] ?? null],
            ['id' => (int)$item['id'], 'quantity' => $quantity, 'unit_price' => $pricePerPiece]
        );
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
        'pricing_label' => bakery_delivery_pricing_label_display((string)$invoice['pricing_label']),
        'pricing_missing' => bakery_delivery_pricing_missing($invoice)
            || bakery_delivery_empty_order_needs_price($invoice),
        'order_empty' => bakery_delivery_order_is_empty($invoice),
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

/** True when the dated order has no pieces to deliver. A no-charge line with quantity is not empty. */
function bakery_delivery_order_is_empty(array $invoice): bool
{
    $pieces = 0;
    foreach ($invoice['items'] ?? [] as $item) {
        if (!empty($item['is_special_order'])) {
            continue;
        }
        $pieces += max(0, (int)($item['quantity'] ?? 0));
    }
    if ($pieces > 0) {
        return false;
    }
    foreach ($invoice['special_items'] ?? [] as $item) {
        if ((int)($item['quantity'] ?? 0) > 0) {
            return false;
        }
    }
    return true;
}

/** An empty order still needs a typed price when the catalog price is missing. */
function bakery_delivery_empty_order_needs_price(array $invoice): bool
{
    if (!bakery_delivery_order_is_empty($invoice)) {
        return false;
    }
    foreach ($invoice['items'] ?? [] as $item) {
        if (function_exists('bakery_order_line_is_no_charge') && bakery_order_line_is_no_charge($item)) {
            continue;
        }
        if ((float)($item['unit_price'] ?? 0) <= 0) {
            return true;
        }
    }
    return false;
}

function bakery_delivery_empty_order_office_message(): string
{
    $fallback = 'This order is empty. Call the office.';
    if (!function_exists('bakery_t')) {
        return $fallback;
    }
    $text = bakery_t('driver.empty_order_call_office');
    if ($text === '' || $text === 'driver.empty_order_call_office') {
        return $fallback;
    }
    return $text;
}

/**
 * Write the driver's delivered counts onto an all-zero dated order and price
 * them from the customer catalog. Deliberate no-charge lines stay at $0.
 *
 * @param array<int, int> $lineQuantities
 */
function bakery_delivery_fill_empty_order_lines(PDO $db, int $dailyOrderId, int $deliveredPieces, array $lineQuantities): void
{
    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    if (!bakery_delivery_order_is_empty($invoice)) {
        return;
    }
    $items = $invoice['items'] ?? [];
    if ($items === []) {
        throw new Exception(bakery_delivery_empty_order_office_message());
    }

    $counts = [];
    if ($lineQuantities !== []) {
        $sum = 0;
        foreach ($items as $item) {
            $id = (int)$item['id'];
            $raw = $lineQuantities[$id] ?? ($lineQuantities[(string)$id] ?? 0);
            $parsed = filter_var($raw, FILTER_VALIDATE_INT);
            if ($parsed === false || (int)$parsed < 0) {
                throw new Exception(bakery_delivery_product_count_mismatch_message());
            }
            $counts[$id] = (int)$parsed;
            $sum += (int)$parsed;
        }
        if ($sum !== $deliveredPieces) {
            throw new Exception(bakery_delivery_product_count_mismatch_message());
        }
    } elseif (count($items) === 1) {
        $counts[(int)$items[0]['id']] = $deliveredPieces;
    } else {
        $message = function_exists('bakery_t')
            ? bakery_t('driver.empty_order_needs_qty')
            : 'Enter how many of each product you delivered.';
        throw new Exception($message === 'driver.empty_order_needs_qty'
            ? 'Enter how many of each product you delivered.'
            : $message);
    }
    if (array_sum($counts) <= 0) {
        throw new Exception(bakery_delivery_empty_order_office_message());
    }

    $update = $db->prepare(
        'UPDATE daily_order_items
         SET quantity = ?, unit_price = ?, line_total = ?
         WHERE id = ? AND daily_order_id = ?'
    );
    foreach ($items as $item) {
        $id = (int)$item['id'];
        $qty = (int)($counts[$id] ?? 0);
        $isNoCharge = function_exists('bakery_order_line_is_no_charge') && bakery_order_line_is_no_charge($item);
        $price = $isNoCharge ? 0.0 : round((float)($item['unit_price'] ?? 0), 2);
        $update->execute([$qty, $price, round($qty * $price, 2), $id, $dailyOrderId]);
    }
    if (function_exists('bakery_daily_order_set_standing_fill_blocked')) {
        bakery_daily_order_set_standing_fill_blocked($db, $dailyOrderId, false);
    }
    if (function_exists('bakery_customer_update_daily_total')) {
        bakery_customer_update_daily_total($db, $dailyOrderId);
    }
}

/**
 * Ask the office to handle an empty stop. Does not mark it delivered.
 *
 * @return array{success:bool, flagged:bool, delivered:bool, message:string}
 */
function bakery_flag_empty_delivery(PDO $db, int $dailyOrderId): array
{
    if ($dailyOrderId <= 0) {
        throw new Exception('Daily order ID is required');
    }
    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    if (!bakery_delivery_order_is_empty($invoice)) {
        throw new Exception('This order is not empty');
    }
    $statusStmt = $db->prepare('SELECT status FROM daily_orders WHERE id = ?');
    $statusStmt->execute([$dailyOrderId]);
    if ((string)$statusStmt->fetchColumn() === 'delivered') {
        throw new Exception('This stop is already delivered');
    }
    $message = function_exists('bakery_t')
        ? bakery_t('driver.empty_order_flagged')
        : 'Flagged for the office. This stop was not closed as delivered.';
    if ($message === 'driver.empty_order_flagged') {
        $message = 'Flagged for the office. This stop was not closed as delivered.';
    }
    if (function_exists('bakery_record_operational_event')) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DELIVERY_EMPTY_FLAGGED,
            $message,
            [
                'operational_date' => $invoice['order']['order_date'] ?? null,
                'customer_id' => (int)($invoice['order']['customer_id'] ?? 0) ?: null,
                'daily_order_id' => $dailyOrderId,
                'driver_id' => (int)($invoice['order']['driver_id'] ?? 0) ?: null,
                'metadata' => ['source' => 'driver_empty_flag'],
            ]
        );
    }
    return [
        'success' => true,
        'flagged' => true,
        'delivered' => false,
        'message' => $message,
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

    $clientRequestId = bakery_normalize_client_request_id($options['client_request_id'] ?? '');
    if ($clientRequestId !== '' && bakery_daily_orders_confirm_request_id_ready($db)) {
        $dup = $db->prepare(
            'SELECT id, delivered_pieces, credits_taken_back, total_amount, amount_collected,
                    delivery_order_total, delivery_pricing_label
             FROM daily_orders
             WHERE confirm_request_id = ?
             LIMIT 1'
        );
        $dup->execute([$clientRequestId]);
        $existing = $dup->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((int)$existing['id'] !== $dailyOrderId) {
                throw new Exception('This confirmation was already recorded for another stop');
            }
            $delivered = (int)($existing['delivered_pieces'] ?? 0);
            $credits = (int)($existing['credits_taken_back'] ?? 0);
            $total = (float)($existing['total_amount'] ?? $existing['delivery_order_total'] ?? 0);
            $billable = max(0, $delivered - $credits);
            $pricePerPiece = $billable > 0 ? round($total / $billable, 4) : 0.0;
            $custStmt = $db->prepare(
                'SELECT ' . bakery_payment_terms_pan_dulce_collection_sql($db) . '
                 FROM daily_orders do
                 JOIN customers c ON c.id = do.customer_id
                 WHERE do.id = ?'
            );
            $custStmt->execute([$dailyOrderId]);
            $paymentCollection = (string)($custStmt->fetchColumn() ?: 'signature');
            $invoice = bakery_delivery_invoice($db, $dailyOrderId);
            return [
                'success' => true,
                'duplicate' => true,
                'message' => 'Delivery already confirmed.',
                'delivered_pieces' => $delivered,
                'credits_taken_back' => $credits,
                'billable_pieces' => $billable,
                'price_per_piece' => $pricePerPiece,
                'total' => $total,
                'amount_collected' => $existing['amount_collected'] !== null ? (float)$existing['amount_collected'] : null,
                'payment_collection' => $paymentCollection,
                'ordered_pieces' => (int)$invoice['ordered_pieces'],
                'invoice' => $invoice,
            ];
        }
    }

    $invoice = bakery_delivery_invoice($db, $dailyOrderId);
    if (bakery_delivery_order_is_empty($invoice) && $deliveredPieces <= 0) {
        throw new Exception(bakery_delivery_empty_order_office_message());
    }
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
        'SELECT ' . bakery_payment_terms_pan_dulce_collection_sql($db) . '
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         WHERE do.id = ?'
    );
    $custStmt->execute([$dailyOrderId]);
    $paymentCollection = (string)($custStmt->fetchColumn() ?: 'signature');
    if (!in_array($paymentCollection, ['cod', 'signature'], true)) {
        $paymentCollection = 'signature';
    }

    $amountCollected = null;
    $squareGuard = bakery_cod_square_guard_row($db, $dailyOrderId);
    $codBlocked = $paymentCollection === 'cod' && bakery_cod_cash_blocked($squareGuard);
    if ($codBlocked) {
        $overrideReason = trim((string)($options['cod_override_reason'] ?? ''));
        if ($overrideReason === '') {
            if (array_key_exists('amount_collected', $options)) {
                throw new Exception('This stop is prepaid in Square. COD cash is blocked unless a manager records a reason.');
            }
        } else {
            $actor = isset($options['actor']) && is_array($options['actor'])
                ? $options['actor']
                : (function_exists('bakery_current_user') ? bakery_current_user() : null);
            bakery_cod_record_square_override($db, $dailyOrderId, $overrideReason, $actor);
            $codBlocked = false;
        }
    }
    if ($paymentCollection === 'cod' && !$codBlocked) {
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
        if (bakery_delivery_order_is_empty($invoice) && $deliveredPieces > 0) {
            $pendingLines = [];
            if (isset($options['line_quantities']) && is_array($options['line_quantities'])) {
                $pendingLines = $options['line_quantities'];
            }
            bakery_delivery_fill_empty_order_lines($db, $dailyOrderId, $deliveredPieces, $pendingLines);
        }
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
                 delivery_confirmed_at = NOW()'
            . ($clientRequestId !== '' && bakery_daily_orders_confirm_request_id_ready($db)
                ? ', confirm_request_id = COALESCE(confirm_request_id, ?)'
                : '')
            . ' WHERE id = ?'
        );
        $totParams = [
            $deliveredPieces,
            $creditsTakenBack,
            $total,
            $total,
            $summary['pricing_label'],
            $amountCollected,
        ];
        if ($clientRequestId !== '' && bakery_daily_orders_confirm_request_id_ready($db)) {
            $totParams[] = $clientRequestId;
        }
        $totParams[] = $dailyOrderId;
        $tot->execute($totParams);
        $verify = $db->prepare('SELECT id FROM daily_orders WHERE id = ?');
        $verify->execute([$dailyOrderId]);
        if (!$verify->fetchColumn()) {
            throw new Exception('Could not save the delivery invoice');
        }
        $lineQuantities = [];
        if (isset($options['line_quantities']) && is_array($options['line_quantities'])) {
            $lineQuantities = $options['line_quantities'];
        }
        bakery_mark_delivery_delivered($db, $dailyOrderId, $lineQuantities);
        if (function_exists('bakery_billing_apply_confirm_credit_and_header')
            && function_exists('column_exists')
            && column_exists($db, 'daily_order_items', 'is_store_credit')) {
            $total = bakery_billing_apply_confirm_credit_and_header(
                $db,
                $dailyOrderId,
                $creditsTakenBack,
                (float)$pricePerPiece
            );
        }
        bakery_inventory_record_delivery_credit_returns(
            $db,
            $dailyOrderId,
            $creditsTakenBack
        );
        if (function_exists('bakery_special_order_invoice_lines')
            && bakery_special_order_invoice_lines($db, $dailyOrderId) !== []) {
            $specialPosted = null;
            if (isset($options['special_quantities']) && is_array($options['special_quantities'])) {
                $specialPosted = $options['special_quantities'];
            }
            $total = bakery_special_order_book_delivery($db, $dailyOrderId, $specialPosted);
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
            $summaryGuard = bakery_cod_square_guard_row($db, $dailyOrderId);
            echo json_encode([
                'success' => true,
                'ordered_pieces' => $summary['ordered_pieces'],
                'order_total' => $summary['order_total'],
                'average_price' => $summary['average_price'],
                'pricing_label' => $summary['pricing_label'],
                'pricing_missing' => $summary['pricing_missing'],
                'order_empty' => !empty($summary['order_empty']),
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
                'special_items' => $invoice['special_items'] ?? [],
                'special_total' => $invoice['special_total'] ?? 0,
                'square_prepaid' => bakery_stop_square_prepaid($summaryGuard),
                'cod_cash_blocked' => ($paymentCollection === 'cod') && bakery_cod_cash_blocked($summaryGuard),
                'cod_override_reason' => $summaryGuard['cod_square_override_reason'],
                'can_cod_override' => function_exists('bakery_user_has_role')
                    && bakery_user_has_role(['administrator', 'manager']),
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
                    'pricing_label' => bakery_delivery_pricing_label_display((string)$invoice['pricing_label']),
                    'confirmed_at' => $invoice['order']['delivery_confirmed_at'],
                    'items' => $invoice['items'],
                ],
            ]);
            break;

        case 'flag_empty_order':
            if (!isset($_POST['daily_order_id'])) {
                throw new Exception('Daily order ID is required');
            }
            $flagOrderId = (int)$_POST['daily_order_id'];
            bakery_delivery_assert_driver_access($db, $flagOrderId);
            echo json_encode(bakery_flag_empty_delivery($db, $flagOrderId));
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
            if (isset($_POST['cod_override_reason'])) {
                $confirmOptions['cod_override_reason'] = $_POST['cod_override_reason'];
            }
            if (function_exists('bakery_current_user')) {
                $actor = bakery_current_user();
                if (is_array($actor)) {
                    $confirmOptions['actor'] = $actor;
                }
            }
            $clientRequestId = bakery_normalize_client_request_id($_POST['client_request_id'] ?? '');
            if ($clientRequestId !== '') {
                $confirmOptions['client_request_id'] = $clientRequestId;
            }
            bakery_delivery_assert_proof_for_confirm(
                $db,
                $dailyOrderId,
                (string)($_POST['proof_skip'] ?? '') === '1'
            );
            if (isset($_POST['special_delivered']) && is_array($_POST['special_delivered'])) {
                $specialQuantities = [];
                foreach ($_POST['special_delivered'] as $lineId => $qty) {
                    $count = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    if ($count === false) {
                        throw new Exception('Enter whole numbers for special order quantities');
                    }
                    $specialQuantities[(int)$lineId] = (int)$count;
                }
                if ($specialQuantities !== []) {
                    $confirmOptions['special_quantities'] = $specialQuantities;
                }
            }
            if (isset($_POST['line_delivered']) && is_array($_POST['line_delivered'])) {
                $lineQuantities = [];
                foreach ($_POST['line_delivered'] as $itemId => $qty) {
                    $count = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                    if ($count === false) {
                        throw new Exception(bakery_delivery_product_count_mismatch_message());
                    }
                    $lineQuantities[(int)$itemId] = (int)$count;
                }
                if ($lineQuantities !== []) {
                    $confirmOptions['line_quantities'] = $lineQuantities;
                }
            }
            $confirmed = bakery_confirm_delivery(
                $db,
                $dailyOrderId,
                (int)$deliveredPieces,
                (int)$creditsTakenBack,
                $confirmOptions
            );

            if (empty($confirmed['duplicate'])) {
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
            }

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
                'duplicate' => !empty($confirmed['duplicate']),
                'client_request_id' => $clientRequestId !== '' ? $clientRequestId : null,
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
                'message' => function_exists('bakery_t') ? bakery_t('driver.skip_success') : 'Stop skipped. Moving to your next stop.',
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
        'error' => bakery_error_message_for_user($e)
    ]);
}
}
