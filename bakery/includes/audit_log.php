<?php
/**
 * Field-level audit trail.
 *
 * One row per changed field, with the value before and after the write.
 * A staff login code is never stored. The only text allowed for that field
 * is "code changed" or "revealed".
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

const BAKERY_AUDIT_CODE_CHANGED = 'code changed';
const BAKERY_AUDIT_CODE_REVEALED = 'revealed';

function bakery_audit_ready(PDO $db): bool
{
    static $ready = null;
    static $bound = null;
    if ($bound !== $db) {
        $bound = $db;
        $ready = null;
    }
    if ($ready !== null) {
        return $ready;
    }
    $ready = function_exists('table_exists') && table_exists($db, 'field_audit')
        && (!function_exists('column_exists') || column_exists($db, 'field_audit', 'old_value'));
    return $ready;
}

/**
 * @return array{user_id:?int,type:string}
 */
function bakery_audit_actor(PDO $db): array
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    if (is_array($user) && (int)($user['id'] ?? 0) > 0) {
        return ['user_id' => (int)$user['id'], 'type' => 'human'];
    }

    $customerId = function_exists('bakery_portal_customer_id') ? (int)bakery_portal_customer_id() : 0;
    if ($customerId > 0) {
        $type = 'customer';
        if (function_exists('column_exists') && column_exists($db, 'customers', 'sf_baker_enabled')) {
            $stmt = $db->prepare('SELECT sf_baker_enabled FROM customers WHERE id = ?');
            $stmt->execute([$customerId]);
            if ((int)$stmt->fetchColumn() === 1) {
                $type = 'bot';
            }
        }
        return ['user_id' => null, 'type' => $type];
    }

    return ['user_id' => null, 'type' => 'system'];
}

function bakery_audit_scalar($value, string $format): ?string
{
    if ($value === null) {
        return null;
    }
    if ($format === 'money') {
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return number_format(round((float)$value, 2), 2, '.', '');
    }
    if ($format === 'int') {
        if ($value === '') {
            return null;
        }
        return (string)(int)$value;
    }
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function bakery_audit_redact_staff_code(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    if ($value === BAKERY_AUDIT_CODE_CHANGED || $value === BAKERY_AUDIT_CODE_REVEALED) {
        return $value;
    }
    if (preg_match('/\d{4}/', $value)) {
        return BAKERY_AUDIT_CODE_CHANGED;
    }
    return $value;
}

function bakery_audit_mask_staff_code(?string $code): string
{
    $digits = preg_replace('/\D/', '', (string)$code);
    if ($digits === null || $digits === '') {
        return '••••';
    }
    return str_repeat('•', max(3, strlen($digits) - 1)) . substr($digits, -1);
}

function bakery_audit_request_ip(): ?string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '') {
        return null;
    }
    return substr($ip, 0, 45);
}

function bakery_audit_request_agent(): ?string
{
    $agent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($agent === '') {
        return null;
    }
    return substr($agent, 0, 512);
}

function bakery_audit_request_path(): ?string
{
    $path = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($path === '') {
        $path = trim((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    }
    if ($path === '') {
        return null;
    }
    return substr($path, 0, 1024);
}

function bakery_audit_write(
    PDO $db,
    string $entityType,
    int $entityId,
    string $action,
    string $field,
    ?string $oldValue,
    ?string $newValue
): void {
    if (!bakery_audit_ready($db) || $entityId <= 0 || $entityType === '' || $field === '') {
        return;
    }
    if ($field === 'login_code') {
        $oldValue = bakery_audit_redact_staff_code($oldValue);
        $newValue = bakery_audit_redact_staff_code($newValue);
    }
    $actor = bakery_audit_actor($db);
    $type = $actor['type'];
    if (!in_array($type, ['human', 'bot', 'system', 'customer'], true)) {
        $type = 'system';
    }
    $stmt = $db->prepare(
        'INSERT INTO field_audit (
            actor_user_id, actor_type, entity_type, entity_id, action, field,
            old_value, new_value, ip, user_agent, request_path
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $actor['user_id'],
        $type,
        substr($entityType, 0, 64),
        $entityId,
        substr($action, 0, 32),
        substr($field, 0, 64),
        $oldValue,
        $newValue,
        bakery_audit_request_ip(),
        bakery_audit_request_agent(),
        bakery_audit_request_path(),
    ]);
}

/**
 * @param array<string, string> $formats field => money|int|text
 */
function bakery_audit_diff(
    PDO $db,
    string $entityType,
    int $entityId,
    string $action,
    array $before,
    array $after,
    array $formats
): void {
    foreach ($formats as $field => $format) {
        if (!array_key_exists($field, $before) && !array_key_exists($field, $after)) {
            continue;
        }
        $old = array_key_exists($field, $before) ? bakery_audit_scalar($before[$field], $format) : null;
        $new = array_key_exists($field, $after) ? bakery_audit_scalar($after[$field], $format) : null;
        if ($old === $new) {
            continue;
        }
        $rowAction = $action;
        if ($action === 'update' && $old === null && $new !== null) {
            $rowAction = 'add';
        } elseif ($action === 'update' && $old !== null && $new === null) {
            $rowAction = 'remove';
        }
        bakery_audit_write($db, $entityType, $entityId, $rowAction, (string)$field, $old, $new);
    }
}

/**
 * @return array{id:int,quantity:int}
 */
function bakery_audit_standing_snapshot(PDO $db, int $customerId, int $productId, int $dayOfWeek): array
{
    $dayOfWeek = function_exists('bakery_normalize_standing_day')
        ? bakery_normalize_standing_day($dayOfWeek)
        : ($dayOfWeek === 0 ? 7 : $dayOfWeek);
    $values = function_exists('bakery_standing_day_match_values')
        ? bakery_standing_day_match_values($dayOfWeek)
        : [$dayOfWeek];
    $marks = implode(',', array_fill(0, count($values), '?'));
    $stmt = $db->prepare(
        "SELECT id, day_of_week, quantity FROM standing_orders
         WHERE customer_id = ? AND product_id = ? AND day_of_week IN ($marks)"
    );
    $stmt->execute(array_merge([$customerId, $productId], $values));
    $id = 0;
    $qty = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $qty += (int)$row['quantity'];
        if ((int)$row['day_of_week'] === $dayOfWeek) {
            $id = (int)$row['id'];
        } elseif ($id === 0) {
            $id = (int)$row['id'];
        }
    }
    return ['id' => $id, 'quantity' => $qty];
}

/**
 * @param array{id:int,quantity:int} $before
 */
function bakery_audit_standing_finish(
    PDO $db,
    int $customerId,
    int $productId,
    int $dayOfWeek,
    int $qty,
    array $before
): void {
    $oldQty = (int)$before['quantity'];
    $dayOfWeek = function_exists('bakery_normalize_standing_day')
        ? bakery_normalize_standing_day($dayOfWeek)
        : ($dayOfWeek === 0 ? 7 : $dayOfWeek);
    if ($qty > 0) {
        if ($oldQty === $qty) {
            return;
        }
        $stmt = $db->prepare(
            'SELECT id FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week = ? LIMIT 1'
        );
        $stmt->execute([$customerId, $productId, $dayOfWeek]);
        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) {
            $id = (int)$before['id'];
        }
        bakery_audit_write(
            $db,
            'standing_order',
            $id,
            $oldQty === 0 ? 'add' : 'update',
            'quantity',
            $oldQty === 0 ? null : (string)$oldQty,
            (string)$qty
        );
        return;
    }
    if ($oldQty === 0) {
        return;
    }
    bakery_audit_write(
        $db,
        'standing_order',
        (int)$before['id'],
        'remove',
        'quantity',
        (string)$oldQty,
        null
    );
}

function bakery_audit_product_row(PDO $db, int $productId): ?array
{
    if ($productId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, name, description, price, wholesale_price, weight_grams, dough_type_id
         FROM products WHERE id = ?'
    );
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_audit_product_fields(PDO $db, int $productId, array $before, array $after, string $action = 'update'): void
{
    $formats = [
        'name' => 'text',
        'description' => 'text',
        'price' => 'money',
        'wholesale_price' => 'money',
        'weight_grams' => 'int',
        'dough_type_id' => 'int',
    ];
    $used = [];
    foreach ($formats as $field => $format) {
        if ($action === 'remove') {
            $used[$field] = $format;
            continue;
        }
        if (array_key_exists($field, $after)) {
            $used[$field] = $format;
        }
    }
    $beforeSlice = [];
    $afterSlice = [];
    foreach ($used as $field => $format) {
        if (array_key_exists($field, $before)) {
            $beforeSlice[$field] = $before[$field];
        }
        if ($action === 'remove') {
            $afterSlice[$field] = null;
        } elseif (array_key_exists($field, $after)) {
            $afterSlice[$field] = $after[$field];
        }
    }
    bakery_audit_diff($db, 'product', $productId, $action, $beforeSlice, $afterSlice, $used);
}

/**
 * @return array{id:int,quantity:int|string,unit_price:mixed}|null
 */
function bakery_audit_daily_line_lookup(PDO $db, int $orderId, int $productId): ?array
{
    if ($orderId <= 0 || $productId <= 0) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, quantity, unit_price FROM daily_order_items
         WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$orderId, $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_audit_daily_line_diff(PDO $db, ?array $before, ?array $after): void
{
    $id = (int)($after['id'] ?? $before['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $action = $before === null ? 'add' : ($after === null ? 'remove' : 'update');
    $beforeValues = $before ?? [];
    $afterValues = $after ?? [];
    if ($before === null) {
        $beforeValues = [];
    }
    if ($after === null) {
        $afterValues = ['quantity' => null, 'unit_price' => null];
        $beforeValues = [
            'quantity' => $before['quantity'] ?? null,
            'unit_price' => $before['unit_price'] ?? null,
        ];
    }
    bakery_audit_diff($db, 'daily_order_item', $id, $action, $beforeValues, $afterValues, [
        'quantity' => 'int',
        'unit_price' => 'money',
    ]);
}

function bakery_audit_pricing_tier(PDO $db, int $customerId, $old, $new, string $action = 'update'): void
{
    $oldText = bakery_audit_scalar($old, 'text');
    $newText = bakery_audit_scalar($new, 'text');
    if ($action !== 'create' && $oldText === $newText) {
        return;
    }
    if ($newText === null && $oldText === null) {
        return;
    }
    bakery_audit_write($db, 'customer', $customerId, $action, 'pricing_tier', $oldText, $newText);
}

/**
 * @param list<array<string, mixed>> $prices
 */
function bakery_save_customer_custom_prices(PDO $db, int $customerId, array $prices): void
{
    if ($customerId <= 0) {
        return;
    }
    $find = $db->prepare(
        'SELECT id, unit_price FROM customer_product_prices WHERE customer_id = ? AND product_id = ? LIMIT 1'
    );
    $upsert = $db->prepare(
        'INSERT INTO customer_product_prices (customer_id, product_id, unit_price)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE unit_price = VALUES(unit_price)'
    );
    $delete = $db->prepare(
        'DELETE FROM customer_product_prices WHERE customer_id = ? AND product_id = ?'
    );
    foreach ($prices as $row) {
        if (!is_array($row)) {
            continue;
        }
        $productId = (int)($row['product_id'] ?? 0);
        $price = trim((string)($row['unit_price'] ?? ''));
        if ($productId <= 0) {
            continue;
        }
        $find->execute([$customerId, $productId]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if ($price === '') {
            if (!$existing) {
                continue;
            }
            $delete->execute([$customerId, $productId]);
            bakery_audit_write(
                $db,
                'customer_product_price',
                (int)$existing['id'],
                'remove',
                'unit_price',
                bakery_audit_scalar($existing['unit_price'], 'money'),
                null
            );
            continue;
        }
        $newMoney = bakery_audit_scalar($price, 'money');
        $upsert->execute([$customerId, $productId, (float)$price]);
        $id = $existing ? (int)$existing['id'] : 0;
        if ($id <= 0) {
            $find->execute([$customerId, $productId]);
            $id = (int)$find->fetchColumn();
        }
        $oldMoney = $existing ? bakery_audit_scalar($existing['unit_price'], 'money') : null;
        if ($oldMoney === $newMoney) {
            continue;
        }
        bakery_audit_write(
            $db,
            'customer_product_price',
            $id,
            $existing ? 'update' : 'add',
            'unit_price',
            $oldMoney,
            $newMoney
        );
    }
}

/**
 * @return array{from:string,to:string,user_id:int,entity_type:string,action:string}
 */
function bakery_audit_parse_filters(array $query): array
{
    $from = trim((string)($query['from'] ?? ''));
    $to = trim((string)($query['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $from = '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $to = '';
    }
    $entity = strtolower(trim((string)($query['entity_type'] ?? '')));
    if (!preg_match('/^[a-z_]{1,64}$/', $entity)) {
        $entity = '';
    }
    $action = strtolower(trim((string)($query['action'] ?? '')));
    if (!preg_match('/^[a-z_]{1,32}$/', $action)) {
        $action = '';
    }
    return [
        'from' => $from,
        'to' => $to,
        'user_id' => max(0, (int)($query['user_id'] ?? 0)),
        'entity_type' => $entity,
        'action' => $action,
    ];
}

/**
 * @param array{from:string,to:string,user_id:int,entity_type:string,action:string} $filters
 * @return array{0:string,1:list<mixed>}
 */
function bakery_audit_where(array $filters): array
{
    $where = ['1=1'];
    $params = [];
    if ($filters['from'] !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $next = date('Y-m-d', strtotime($filters['to'] . ' +1 day'));
        $where[] = 'a.created_at < ?';
        $params[] = $next . ' 00:00:00';
    }
    if ($filters['user_id'] > 0) {
        $where[] = 'a.actor_user_id = ?';
        $params[] = $filters['user_id'];
    }
    if ($filters['entity_type'] !== '') {
        $where[] = 'a.entity_type = ?';
        $params[] = $filters['entity_type'];
    }
    if ($filters['action'] !== '') {
        $where[] = 'a.action = ?';
        $params[] = $filters['action'];
    }
    return [implode(' AND ', $where), $params];
}

/**
 * @param array{from:string,to:string,user_id:int,entity_type:string,action:string} $filters
 * @return array{0:string,1:list<mixed>}
 */
function bakery_audit_select_sql(array $filters, ?int $limit, string $direction = 'ASC'): array
{
    [$where, $params] = bakery_audit_where($filters);
    $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
    $sql = 'SELECT a.*, u.display_name AS actor_name
            FROM field_audit a
            LEFT JOIN users u ON u.id = a.actor_user_id
            WHERE ' . $where . '
            ORDER BY a.id ' . $direction;
    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    return [$sql, $params];
}

function bakery_audit_count(PDO $db, array $filters): int
{
    if (!bakery_audit_ready($db)) {
        return 0;
    }
    [$where, $params] = bakery_audit_where($filters);
    $stmt = $db->prepare('SELECT COUNT(*) FROM field_audit a WHERE ' . $where);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bakery_audit_rows(PDO $db, array $filters, ?int $limit, string $direction = 'ASC'): array
{
    if (!bakery_audit_ready($db)) {
        return [];
    }
    [$sql, $params] = bakery_audit_select_sql($filters, $limit, $direction);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return bakery_audit_decorate($db, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function bakery_audit_decorate(PDO $db, array $rows): array
{
    if ($rows === []) {
        return [];
    }
    $ids = [
        'product' => [],
        'user' => [],
        'customer' => [],
        'standing_order' => [],
        'daily_order_item' => [],
        'customer_product_price' => [],
    ];
    foreach ($rows as $row) {
        $type = (string)$row['entity_type'];
        if (isset($ids[$type])) {
            $ids[$type][(int)$row['entity_id']] = (int)$row['entity_id'];
        }
    }
    $labels = [];
    $labels += bakery_audit_name_map($db, 'product', 'products', 'name', array_values($ids['product']));
    $labels += bakery_audit_name_map($db, 'user', 'users', 'display_name', array_values($ids['user']));
    $labels += bakery_audit_name_map($db, 'customer', 'customers', 'name', array_values($ids['customer']));
    $labels += bakery_audit_standing_labels($db, array_values($ids['standing_order']));
    $labels += bakery_audit_line_labels($db, 'daily_order_item', 'daily_order_items', array_values($ids['daily_order_item']));
    $labels += bakery_audit_price_labels($db, array_values($ids['customer_product_price']));

    foreach ($rows as &$row) {
        $key = (string)$row['entity_type'] . ':' . (int)$row['entity_id'];
        $row['entity_label'] = $labels[$key] ?? ('#' . (int)$row['entity_id']);
    }
    unset($row);
    return $rows;
}

function bakery_audit_name_map(PDO $db, string $type, string $table, string $column, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === [] || !function_exists('table_exists') || !table_exists($db, $table)) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, {$column} AS label FROM {$table} WHERE id IN ($marks)");
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$type . ':' . (int)$row['id']] = (string)$row['label'];
    }
    return $out;
}

function bakery_audit_standing_labels(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $days = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT so.id, so.day_of_week, c.name AS customer_name, p.name AS product_name
         FROM standing_orders so
         JOIN customers c ON c.id = so.customer_id
         JOIN products p ON p.id = so.product_id
         WHERE so.id IN ($marks)"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = $days[(int)$row['day_of_week']] ?? (string)$row['day_of_week'];
        $out['standing_order:' . (int)$row['id']] = $row['customer_name'] . ' · ' . $row['product_name'] . ' · ' . $day;
    }
    return $out;
}

function bakery_audit_line_labels(PDO $db, string $type, string $table, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT doi.id, c.name AS customer_name, p.name AS product_name, do.order_date
         FROM {$table} doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         JOIN products p ON p.id = doi.product_id
         WHERE doi.id IN ($marks)"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$type . ':' . (int)$row['id']] = $row['customer_name'] . ' · ' . $row['product_name'] . ' · ' . $row['order_date'];
    }
    return $out;
}

function bakery_audit_price_labels(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT cpp.id, c.name AS customer_name, p.name AS product_name
         FROM customer_product_prices cpp
         JOIN customers c ON c.id = cpp.customer_id
         JOIN products p ON p.id = cpp.product_id
         WHERE cpp.id IN ($marks)"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out['customer_product_price:' . (int)$row['id']] = $row['customer_name'] . ' · ' . $row['product_name'];
    }
    return $out;
}

function bakery_audit_csv_cell($value): string
{
    $text = $value === null ? '' : (string)$value;
    if ($text !== '' && preg_match('/^[=+\-@]/', $text)) {
        return "'" . $text;
    }
    return $text;
}

function bakery_audit_csv(PDO $db, array $filters): string
{
    $rows = bakery_audit_rows($db, $filters, null, 'ASC');
    $handle = fopen('php://temp', 'w+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, [
        'id', 'created_at', 'actor_user_id', 'actor_name', 'actor_type',
        'entity_type', 'entity_id', 'entity_label', 'action', 'field',
        'old_value', 'new_value', 'ip', 'user_agent', 'request_path',
    ]);
    foreach ($rows as $row) {
        fputcsv($handle, [
            bakery_audit_csv_cell($row['id'] ?? ''),
            bakery_audit_csv_cell($row['created_at'] ?? ''),
            bakery_audit_csv_cell($row['actor_user_id'] ?? ''),
            bakery_audit_csv_cell($row['actor_name'] ?? ''),
            bakery_audit_csv_cell($row['actor_type'] ?? ''),
            bakery_audit_csv_cell($row['entity_type'] ?? ''),
            bakery_audit_csv_cell($row['entity_id'] ?? ''),
            bakery_audit_csv_cell($row['entity_label'] ?? ''),
            bakery_audit_csv_cell($row['action'] ?? ''),
            bakery_audit_csv_cell($row['field'] ?? ''),
            bakery_audit_csv_cell($row['old_value'] ?? ''),
            bakery_audit_csv_cell($row['new_value'] ?? ''),
            bakery_audit_csv_cell($row['ip'] ?? ''),
            bakery_audit_csv_cell($row['user_agent'] ?? ''),
            bakery_audit_csv_cell($row['request_path'] ?? ''),
        ]);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}

function bakery_audit_known_entities(): array
{
    return [
        'standing_order',
        'product',
        'customer',
        'customer_product_price',
        'daily_order_item',
        'user',
    ];
}

function bakery_audit_known_actions(): array
{
    return ['add', 'update', 'remove', 'create', 'reveal'];
}
