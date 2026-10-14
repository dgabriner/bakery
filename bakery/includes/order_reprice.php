<?php
/**
 * Reprice booked lines after a catalog price change.
 *
 * Dated lines keep daily_order_items.unit_price. Standing orders store
 * quantity only, so they are never rewritten here. Delivery snapshots
 * (delivery_order_total) and invoiced or delivered history stay as booked.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_portal.php';
require_once __DIR__ . '/daily_orders_actions.php';
require_once __DIR__ . '/operational_timeline.php';

function bakery_standing_orders_store_unit_price(PDO $db): bool
{
    return column_exists($db, 'standing_orders', 'unit_price')
        || column_exists($db, 'standing_orders', 'price');
}

function bakery_order_reprice_catalog_amount($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float)$value, 2);
}

function bakery_order_reprice_same_money($left, $right): bool
{
    return abs(bakery_order_reprice_catalog_amount($left) - bakery_order_reprice_catalog_amount($right)) < 0.005;
}

function bakery_order_reprice_today(?string $today): string
{
    if ($today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today)) {
        return $today;
    }
    return date('Y-m-d');
}

/**
 * Wholesale of 0 or blank means "no wholesale price" for resolution.
 */
function bakery_order_reprice_wholesale_probe($wholesale)
{
    if ($wholesale === null || $wholesale === '') {
        return null;
    }
    if (abs((float)$wholesale) < 0.005) {
        return null;
    }
    return $wholesale;
}

function bakery_order_reprice_resolved(PDO $db, array $customer, array $product, $retail, $wholesale): float
{
    $probe = $product;
    $probe['id'] = (int)($product['id'] ?? $product['product_id'] ?? 0);
    $probe['price'] = bakery_order_reprice_catalog_amount($retail);
    $probe['wholesale_price'] = bakery_order_reprice_wholesale_probe($wholesale);
    return round((float)bakery_resolve_customer_price($db, $customer, $probe), 2);
}

function bakery_product_editor_next_price(array $post, $existing)
{
    if (!array_key_exists('price', $post)) {
        return $existing;
    }
    if ($post['price'] === '' || $post['price'] === null) {
        return null;
    }
    return $post['price'];
}

function bakery_product_editor_next_wholesale(array $post, $existing)
{
    if (!array_key_exists('wholesale_price', $post)) {
        return $existing;
    }
    if ($post['wholesale_price'] === '' || $post['wholesale_price'] === null) {
        return null;
    }
    return $post['wholesale_price'];
}

function bakery_product_retail_is_zero($price): bool
{
    if ($price === null || $price === '' || !is_numeric($price)) {
        return false;
    }
    return abs((float)$price) < 0.005;
}

function bakery_product_catalog_price_missing($price): bool
{
    if ($price === null || $price === '') {
        return true;
    }
    return bakery_product_retail_is_zero($price);
}

/**
 * Products have no inactive flag in the baseline schema. A later is_active
 * column, when present and 0, marks the row inactive.
 */
function bakery_product_is_active_row(array $product): bool
{
    if (!array_key_exists('is_active', $product) || $product['is_active'] === null || $product['is_active'] === '') {
        return true;
    }
    return (int)$product['is_active'] === 1;
}

function bakery_product_shows_in_no_price_filter(array $product): bool
{
    if (!bakery_product_is_active_row($product)) {
        return false;
    }
    return bakery_product_catalog_price_missing($product['price'] ?? null);
}

function bakery_product_zero_price_confirm_required(array $existing, $newPrice): bool
{
    if (!bakery_product_is_active_row($existing)) {
        return false;
    }
    if (!bakery_product_retail_is_zero($newPrice)) {
        return false;
    }
    return !bakery_product_retail_is_zero($existing['price'] ?? null);
}

function bakery_product_zero_price_confirmed(array $post): bool
{
    return (string)($post['confirm_zero_price'] ?? '') === '1';
}

/**
 * Lang key when an active product is being saved at $0.00 without confirmation.
 * Already-zero rows can be saved again without asking. Null means allow the save.
 */
function bakery_product_save_zero_price_block(array $existing, $newPrice, array $post): ?string
{
    if (!bakery_product_zero_price_confirm_required($existing, $newPrice)) {
        return null;
    }
    if (bakery_product_zero_price_confirmed($post)) {
        return null;
    }
    return 'reprice.zero_save_blocked';
}

function bakery_product_editor_select_sql(PDO $db): string
{
    $active = column_exists($db, 'products', 'is_active') ? ', is_active' : '';
    return 'id, name, price, wholesale_price' . $active;
}

/**
 * A catalog product is active when the row exists. Products have no inactive flag.
 * Block only when the price that would be booked is $0 and the line is not No charge.
 */
function bakery_order_reprice_zero_catalog_blocked(array $product, float $resolved, bool $noCharge): bool
{
    if ($noCharge) {
        return false;
    }
    return round($resolved, 2) <= 0.0;
}

/**
 * @return array{ok:bool,error:?string,updated:int,message:?string}
 */
function bakery_order_reprice_authorize(?array $user, bool $requireCsrf): array
{
    if ($requireCsrf && !bakery_verify_csrf()) {
        return [
            'ok' => false,
            'error' => 'csrf',
            'updated' => 0,
            'message' => bakery_t('reprice.csrf'),
        ];
    }
    $role = (string)($user['role_slug'] ?? '');
    if (!in_array($role, ['administrator', 'manager'], true)) {
        return [
            'ok' => false,
            'error' => 'forbidden',
            'updated' => 0,
            'message' => bakery_t('reprice.forbidden'),
        ];
    }
    return ['ok' => true, 'error' => null, 'updated' => 0, 'message' => null];
}

function bakery_order_reprice_block_reason(array $row, string $today): ?string
{
    $status = (string)($row['status'] ?? '');
    $assignment = (string)($row['locked_assignment'] ?? '');
    if ($status === 'delivered' || !empty($row['delivery_confirmed_at']) || $assignment === 'delivered') {
        return 'delivered';
    }
    $square = trim((string)($row['square_invoice_id'] ?? ''));
    if ($status === 'invoiced' || $square !== '' || (int)($row['invoice_sends'] ?? 0) > 0) {
        return 'invoiced';
    }
    if ($status === 'out_for_delivery' || in_array($assignment, ['in_transit', 'failed', 'cancelled'], true)) {
        return 'locked';
    }
    if (!empty($row['closed_at']) && empty($row['reopened_at'])) {
        return 'closed';
    }
    $date = (string)($row['order_date'] ?? '');
    if ($date !== '' && $date < $today) {
        return 'past';
    }
    return null;
}

function bakery_order_reprice_refusal_message(string $reason): string
{
    if ($reason === 'past') {
        return bakery_t('reprice.past');
    }
    return bakery_t('reprice.order_refused');
}

/**
 * @return array{select:string,join:string}
 */
function bakery_order_reprice_flag_sql(PDO $db): array
{
    $square = column_exists($db, 'daily_orders', 'square_invoice_id')
        ? 'do.square_invoice_id'
        : 'NULL AS square_invoice_id';
    $confirmed = column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'do.delivery_confirmed_at'
        : 'NULL AS delivery_confirmed_at';
    if (table_exists($db, 'operating_day_closeouts')) {
        $close = 'odc.closed_at, odc.reopened_at';
        $join = 'LEFT JOIN operating_day_closeouts odc ON odc.operating_date = do.order_date';
    } else {
        $close = 'NULL AS closed_at, NULL AS reopened_at';
        $join = '';
    }
    $sends = table_exists($db, 'billing_invoice_sends')
        ? '(SELECT COUNT(*) FROM billing_invoice_sends bis WHERE bis.daily_order_id = do.id) AS invoice_sends'
        : '0 AS invoice_sends';
    $locked = "(SELECT doa.delivery_status FROM daily_order_assignments doa
        WHERE doa.daily_order_id = do.id
          AND doa.delivery_status IN ('in_transit','delivered','failed','cancelled')
        ORDER BY doa.id DESC LIMIT 1) AS locked_assignment";
    return [
        'select' => "{$confirmed}, {$square}, {$close}, {$sends}, {$locked}",
        'join' => $join,
    ];
}

function bakery_order_reprice_order_header(PDO $db, int $orderId): ?array
{
    $flags = bakery_order_reprice_flag_sql($db);
    $stmt = $db->prepare(
        "SELECT do.id, do.customer_id, do.order_date, do.status, {$flags['select']}
         FROM daily_orders do
         {$flags['join']}
         WHERE do.id = ?"
    );
    $stmt->execute([$orderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param list<int> $orderIds
 * @return list<array<string,mixed>>
 */
function bakery_order_reprice_order_headers(PDO $db, array $orderIds): array
{
    if ($orderIds === []) {
        return [];
    }
    $flags = bakery_order_reprice_flag_sql($db);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare(
        "SELECT do.id, do.customer_id, do.order_date, do.status, {$flags['select']}
         FROM daily_orders do
         {$flags['join']}
         WHERE do.id IN ($placeholders)"
    );
    $stmt->execute(array_values($orderIds));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param list<int> $orderIds
 * @return list<int>
 */
function bakery_order_reprice_open_ids(PDO $db, array $orderIds, ?string $today = null): array
{
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    if ($orderIds === []) {
        return [];
    }
    $today = bakery_order_reprice_today($today);
    $open = [];
    foreach (bakery_order_reprice_order_headers($db, $orderIds) as $row) {
        if (bakery_order_reprice_block_reason($row, $today) === null) {
            $open[] = (int)$row['id'];
        }
    }
    return $open;
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_order_reprice_line_rows(PDO $db, string $where, array $params): array
{
    $noCharge = bakery_order_line_no_charge_ready($db)
        ? 'doi.is_no_charge, doi.no_charge_reason'
        : '0 AS is_no_charge, NULL AS no_charge_reason';
    $flags = bakery_order_reprice_flag_sql($db);
    $sql = "SELECT doi.id AS line_id, doi.daily_order_id AS order_id, doi.product_id,
                   doi.quantity, doi.unit_price, doi.line_total, {$noCharge},
                   do.order_date, do.status, do.customer_id, {$flags['select']},
                   c.name AS customer_name, c.pricing_tier, c.default_pan_dulce_price,
                   p.name AS product_name, p.price, p.wholesale_price,
                   pl.name AS product_line_name
            FROM daily_order_items doi
            JOIN daily_orders do ON do.id = doi.daily_order_id
            JOIN customers c ON c.id = do.customer_id
            JOIN products p ON p.id = doi.product_id
            LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
            LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
            {$flags['join']}
            WHERE {$where}";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_order_reprice_customer_from_row(array $row): array
{
    return [
        'id' => (int)($row['customer_id'] ?? 0),
        'pricing_tier' => $row['pricing_tier'] ?? 'retail',
        'default_pan_dulce_price' => $row['default_pan_dulce_price'] ?? null,
    ];
}

function bakery_order_reprice_product_from_row(array $row): array
{
    return [
        'id' => (int)($row['product_id'] ?? 0),
        'price' => $row['price'] ?? 0,
        'wholesale_price' => $row['wholesale_price'] ?? null,
        'product_line_name' => $row['product_line_name'] ?? '',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_order_reprice_list_stale(PDO $db, int $productId, $oldRetail, $oldWholesale, ?string $today = null): array
{
    $today = bakery_order_reprice_today($today);
    $rows = bakery_order_reprice_line_rows(
        $db,
        'doi.product_id = ? AND do.order_date >= ? AND doi.quantity > 0',
        [$productId, $today]
    );
    $stale = [];
    foreach ($rows as $row) {
        if (bakery_order_line_is_no_charge($row)) {
            continue;
        }
        if (bakery_order_reprice_block_reason($row, $today) !== null) {
            continue;
        }
        $customer = bakery_order_reprice_customer_from_row($row);
        $product = bakery_order_reprice_product_from_row($row);
        $oldResolved = bakery_order_reprice_resolved($db, $customer, $product, $oldRetail, $oldWholesale);
        $newResolved = bakery_order_reprice_resolved($db, $customer, $product, $product['price'], $product['wholesale_price']);
        if (!bakery_order_reprice_same_money($row['unit_price'], $oldResolved)) {
            continue;
        }
        if (bakery_order_reprice_same_money($oldResolved, $newResolved)) {
            continue;
        }
        $stale[] = [
            'line_id' => (int)$row['line_id'],
            'order_id' => (int)$row['order_id'],
            'customer_id' => (int)$row['customer_id'],
            'product_id' => (int)$row['product_id'],
            'customer_name' => (string)$row['customer_name'],
            'order_date' => (string)$row['order_date'],
            'quantity' => (int)$row['quantity'],
            'old_unit_price' => bakery_order_reprice_catalog_amount($row['unit_price']),
            'new_unit_price' => $newResolved,
            'product_name' => (string)$row['product_name'],
        ];
    }
    return $stale;
}

function bakery_order_reprice_format_money($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format(round((float)$value, 2), 2, '.', '');
}

/**
 * @return array<string,mixed>|null
 */
function bakery_order_reprice_note_price_change(
    PDO $db,
    int $productId,
    $oldRetail,
    $oldWholesale,
    $newRetail,
    $newWholesale,
    ?string $today = null
): ?array {
    $retailSame = bakery_order_reprice_same_money($oldRetail, $newRetail);
    $wholesaleSame = bakery_order_reprice_same_money(
        bakery_order_reprice_wholesale_probe($oldWholesale) ?? 0,
        bakery_order_reprice_wholesale_probe($newWholesale) ?? 0
    );
    if ($retailSame && $wholesaleSame) {
        return null;
    }
    $lines = bakery_order_reprice_list_stale($db, $productId, $oldRetail, $oldWholesale, $today);
    if ($lines === []) {
        return null;
    }
    $name = (string)($lines[0]['product_name'] ?? '');
    if ($name === '') {
        $stmt = $db->prepare('SELECT name FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $name = (string)$stmt->fetchColumn();
    }
    return [
        'product_id' => $productId,
        'product_name' => $name,
        'old_price' => bakery_order_reprice_format_money($oldRetail),
        'old_wholesale' => bakery_order_reprice_format_money(bakery_order_reprice_wholesale_probe($oldWholesale)),
        'lines' => $lines,
        'standing_orders_store_price' => bakery_standing_orders_store_unit_price($db),
    ];
}

function bakery_order_reprice_recompute(PDO $db, int $orderId): void
{
    if (function_exists('bakery_daily_order_recompute_total')) {
        bakery_daily_order_recompute_total($db, $orderId);
        return;
    }
    $stmt = $db->prepare(
        'UPDATE daily_orders
         SET total_amount = (
             SELECT COALESCE(SUM(line_total), 0) FROM daily_order_items WHERE daily_order_id = ?
         )
         WHERE id = ?'
    );
    $stmt->execute([$orderId, $orderId]);
}

function bakery_order_reprice_log(PDO $db, array $row, ?array $user, float $oldPrice, float $newPrice, string $source): void
{
    $lineId = (int)($row['line_id'] ?? 0);
    $eventType = defined('BAKERY_OP_LINE_PRICE_REPAIRED')
        ? BAKERY_OP_LINE_PRICE_REPAIRED
        : 'line_price_repaired';
    bakery_record_operational_event(
        $db,
        $eventType,
        'Repriced order line ' . $lineId . ' from the catalog',
        [
            'operational_date' => $row['order_date'] ?? null,
            'customer_id' => (int)($row['customer_id'] ?? 0) ?: null,
            'daily_order_id' => (int)($row['order_id'] ?? $row['daily_order_id'] ?? 0) ?: null,
            'product_id' => (int)($row['product_id'] ?? 0) ?: null,
            'actor_user_id' => (int)($user['id'] ?? 0) ?: null,
            'actor_role' => $user['role_slug'] ?? null,
            'metadata' => [
                'line_id' => $lineId,
                'old_price' => round($oldPrice, 2),
                'new_price' => round($newPrice, 2),
                'source' => $source,
            ],
        ]
    );
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{ok:bool,error:?string,updated:int,message:?string}
 */
function bakery_order_reprice_write_lines(PDO $db, array $rows, ?array $user, string $source, string $doneKey): array
{
    $noChargeGuard = bakery_order_line_no_charge_ready($db)
        ? ' AND COALESCE(is_no_charge, 0) = 0'
        : '';
    $update = $db->prepare(
        'UPDATE daily_order_items
         SET unit_price = ?, line_total = ?
         WHERE id = ? AND daily_order_id = ?' . $noChargeGuard
    );
    $updated = 0;
    $orderIds = [];
    foreach ($rows as $row) {
        $lineId = (int)$row['line_id'];
        $orderId = (int)$row['order_id'];
        $quantity = (int)$row['quantity'];
        $oldPrice = bakery_order_reprice_catalog_amount($row['old_unit_price'] ?? $row['unit_price']);
        $newPrice = bakery_order_reprice_catalog_amount($row['new_unit_price']);
        if (bakery_order_reprice_same_money($oldPrice, $newPrice) || $quantity <= 0) {
            continue;
        }
        $lineTotal = round($quantity * $newPrice, 2);
        $update->execute([$newPrice, $lineTotal, $lineId, $orderId]);
        if ($update->rowCount() < 1) {
            continue;
        }
        $updated++;
        $orderIds[$orderId] = true;
        $logRow = $row;
        $logRow['line_id'] = $lineId;
        $logRow['order_id'] = $orderId;
        bakery_order_reprice_log($db, $logRow, $user, $oldPrice, $newPrice, $source);
    }
    foreach (array_keys($orderIds) as $orderId) {
        bakery_order_reprice_recompute($db, (int)$orderId);
    }
    $message = $updated > 0
        ? bakery_t($doneKey, ['count' => (string)$updated])
        : bakery_t('reprice.none');
    return ['ok' => true, 'error' => null, 'updated' => $updated, 'message' => $message];
}

/**
 * @return array{ok:bool,error:?string,updated:int,message:?string}
 */
function bakery_order_reprice_apply_product(
    PDO $db,
    int $productId,
    $oldRetail,
    $oldWholesale,
    ?array $user,
    ?string $today = null,
    bool $requireCsrf = false
): array {
    $auth = bakery_order_reprice_authorize($user, $requireCsrf);
    if (!$auth['ok']) {
        return $auth;
    }
    $lines = bakery_order_reprice_list_stale($db, $productId, $oldRetail, $oldWholesale, $today);
    return bakery_order_reprice_write_lines($db, $lines, $user, 'catalog_reprice', 'reprice.done');
}

/**
 * @return array{ok:bool,error:?string,updated:int,message:?string}
 */
function bakery_order_reprice_apply_order(
    PDO $db,
    int $orderId,
    ?array $user,
    ?string $today = null,
    bool $requireCsrf = false
): array {
    $auth = bakery_order_reprice_authorize($user, $requireCsrf);
    if (!$auth['ok']) {
        return $auth;
    }
    $today = bakery_order_reprice_today($today);
    $header = bakery_order_reprice_order_header($db, $orderId);
    if (!$header) {
        return [
            'ok' => false,
            'error' => 'missing',
            'updated' => 0,
            'message' => bakery_t('reprice.order_refused'),
        ];
    }
    $reason = bakery_order_reprice_block_reason($header, $today);
    if ($reason !== null) {
        return [
            'ok' => false,
            'error' => $reason,
            'updated' => 0,
            'message' => bakery_order_reprice_refusal_message($reason),
        ];
    }
    $rows = bakery_order_reprice_line_rows(
        $db,
        'doi.daily_order_id = ? AND doi.quantity > 0',
        [$orderId]
    );
    $eligible = [];
    foreach ($rows as $row) {
        if (bakery_order_line_is_no_charge($row)) {
            continue;
        }
        $customer = bakery_order_reprice_customer_from_row($row);
        $product = bakery_order_reprice_product_from_row($row);
        $newPrice = bakery_order_reprice_resolved($db, $customer, $product, $product['price'], $product['wholesale_price']);
        $oldPrice = bakery_order_reprice_catalog_amount($row['unit_price']);
        if (bakery_order_reprice_same_money($oldPrice, $newPrice)) {
            continue;
        }
        $row['old_unit_price'] = $oldPrice;
        $row['new_unit_price'] = $newPrice;
        $eligible[] = $row;
    }
    $result = bakery_order_reprice_write_lines($db, $eligible, $user, 'order_catalog_reprice', 'reprice.order_done');
    if ((int)$result['updated'] === 0) {
        $result['message'] = bakery_t('reprice.none');
    }
    return $result;
}
