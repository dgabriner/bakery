<?php
/**
 * Ingredient invoice prices, current cost per kg, and draft reorder lists.
 * Draft purchase orders are data for a printable page. Nothing here sends mail or texts.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Cost per kilogram from a pack price and a pack weight in grams.
 * Returns null when the pack weight is not positive.
 */
function bakery_ingredient_cost_per_kg_from_pack($packPrice, $packGrams): ?float
{
    $grams = (float)$packGrams;
    if ($grams <= 0) {
        return null;
    }
    return round(((float)$packPrice) * 1000 / $grams, 4);
}

function bakery_ingredient_prices_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'ingredient_price_history');
}

function bakery_ingredient_reorder_qty_ready(PDO $db): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute(['ingredients', 'reorder_qty']);
    $ready = (int)$stmt->fetchColumn() === 1;
    return $ready;
}

/**
 * On-hand at or below the reorder point. The point is ingredients.reorder_level.
 * A blank reorder point is not a flag. A blank on-hand counts as zero.
 */
function bakery_ingredient_needs_reorder(array $ingredient): bool
{
    if (function_exists('bakery_ingredient_is_low_stock')) {
        return bakery_ingredient_is_low_stock($ingredient);
    }
    if (!array_key_exists('reorder_level', $ingredient) || $ingredient['reorder_level'] === null || $ingredient['reorder_level'] === '') {
        return false;
    }
    $qty = ($ingredient['quantity_on_hand'] === null || $ingredient['quantity_on_hand'] === '')
        ? 0.0
        : (float)$ingredient['quantity_on_hand'];
    return $qty <= (float)$ingredient['reorder_level'];
}

/**
 * @return string|null i18n key when invalid
 */
function bakery_ingredient_price_validate(array $input): ?string
{
    $ingredientId = (int)($input['ingredient_id'] ?? 0);
    if ($ingredientId <= 0) {
        return 'ingredient_prices.invalid_ingredient';
    }
    $vendor = trim((string)($input['vendor'] ?? ''));
    if ($vendor === '' || strlen($vendor) > 255) {
        return 'ingredient_prices.invalid_vendor';
    }
    if (!is_numeric($input['pack_size_grams'] ?? null) || (float)$input['pack_size_grams'] <= 0) {
        return 'ingredient_prices.invalid_pack';
    }
    if (!is_numeric($input['pack_price'] ?? null) || (float)$input['pack_price'] < 0) {
        return 'ingredient_prices.invalid_price';
    }
    if (bakery_ingredient_price_normalize_date($input['invoice_date'] ?? '') === null) {
        return 'ingredient_prices.invalid_date';
    }
    return null;
}

function bakery_ingredient_price_normalize_date($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $formats = ['Y-m-d', 'n/j/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat('!' . $format, $value);
        $errors = DateTime::getLastErrors();
        if ($dt instanceof DateTime && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

function bakery_ingredient_price_name_key(string $name): string
{
    $name = trim((string)(preg_replace('/\s+/', ' ', $name) ?? $name));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($name, 'UTF-8');
    }
    return strtolower($name);
}

/**
 * @param array<string, mixed> $input
 * @return array{ok:bool, error?:string, row?:array<string, mixed>}
 */
function bakery_ingredient_price_add(PDO $db, array $input): array
{
    if (!bakery_ingredient_prices_ready($db)) {
        return ['ok' => false, 'error' => 'ingredient_prices.migration_needed'];
    }
    $error = bakery_ingredient_price_validate($input);
    if ($error !== null) {
        return ['ok' => false, 'error' => $error];
    }
    $ingredientId = (int)$input['ingredient_id'];
    $exists = $db->prepare('SELECT id FROM ingredients WHERE id = ?');
    $exists->execute([$ingredientId]);
    if (!$exists->fetchColumn()) {
        return ['ok' => false, 'error' => 'ingredient_prices.invalid_ingredient'];
    }

    $enteredBy = isset($input['entered_by']) ? (int)$input['entered_by'] : 0;
    if ($enteredBy <= 0) {
        $enteredBy = null;
    } else {
        $user = $db->prepare('SELECT id FROM users WHERE id = ?');
        $user->execute([$enteredBy]);
        if (!$user->fetchColumn()) {
            $enteredBy = null;
        }
    }

    $packGrams = round((float)$input['pack_size_grams'], 3);
    $packPrice = round((float)$input['pack_price'], 2);
    $costPerKg = bakery_ingredient_cost_per_kg_from_pack($packPrice, $packGrams);
    $sku = trim((string)($input['vendor_sku'] ?? ''));
    $invoice = trim((string)($input['invoice_number'] ?? ''));
    $stmt = $db->prepare(
        'INSERT INTO ingredient_price_history
            (ingredient_id, vendor, vendor_sku, pack_size_grams, pack_price, cost_per_kg, invoice_number, invoice_date, entered_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $ingredientId,
        trim((string)$input['vendor']),
        $sku !== '' ? substr($sku, 0, 80) : null,
        $packGrams,
        $packPrice,
        $costPerKg,
        $invoice !== '' ? substr($invoice, 0, 64) : null,
        bakery_ingredient_price_normalize_date($input['invoice_date']),
        $enteredBy,
    ]);
    $id = (int)$db->lastInsertId();
    $row = bakery_ingredient_price_row($db, $id);
    return ['ok' => true, 'row' => $row ?? ['id' => $id, 'cost_per_kg' => $costPerKg]];
}

/**
 * @return array<string, mixed>|null
 */
function bakery_ingredient_price_row(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT h.*, u.display_name AS entered_by_name
         FROM ingredient_price_history h
         LEFT JOIN users u ON u.id = h.entered_by
         WHERE h.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Latest invoice for one ingredient. Same invoice date: the higher id wins.
 *
 * @return array<string, mixed>|null
 */
function bakery_ingredient_current_price(PDO $db, int $ingredientId): ?array
{
    if ($ingredientId <= 0 || !bakery_ingredient_prices_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT h.*, u.display_name AS entered_by_name
         FROM ingredient_price_history h
         LEFT JOIN users u ON u.id = h.entered_by
         WHERE h.ingredient_id = ?
         ORDER BY h.invoice_date DESC, h.id DESC
         LIMIT 1'
    );
    $stmt->execute([$ingredientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Current cost per kg for a future cost-and-margin view.
 * Pass $db, or set $GLOBALS['db'] before calling with the id alone.
 */
function bakery_ingredient_current_cost_per_kg($ingredientId, $db = null): ?float
{
    if (!$db instanceof PDO) {
        $db = (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) ? $GLOBALS['db'] : null;
    }
    if (!$db instanceof PDO) {
        return null;
    }
    $row = bakery_ingredient_current_price($db, (int)$ingredientId);
    if (!$row || $row['cost_per_kg'] === null || $row['cost_per_kg'] === '') {
        return null;
    }
    return (float)$row['cost_per_kg'];
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_ingredient_price_history(PDO $db, int $ingredientId): array
{
    if ($ingredientId <= 0 || !bakery_ingredient_prices_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT h.*, u.display_name AS entered_by_name
         FROM ingredient_price_history h
         LEFT JOIN users u ON u.id = h.entered_by
         WHERE h.ingredient_id = ?
         ORDER BY h.invoice_date DESC, h.id DESC, h.created_at DESC'
    );
    $stmt->execute([$ingredientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Latest price row keyed by ingredient id.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_ingredient_current_prices_map(PDO $db): array
{
    if (!bakery_ingredient_prices_ready($db)) {
        return [];
    }
    $sql = 'SELECT h.*, u.display_name AS entered_by_name
            FROM ingredient_price_history h
            LEFT JOIN users u ON u.id = h.entered_by
            INNER JOIN (
                SELECT h1.ingredient_id, MAX(h1.id) AS id
                FROM ingredient_price_history h1
                INNER JOIN (
                    SELECT ingredient_id, MAX(invoice_date) AS invoice_date
                    FROM ingredient_price_history
                    GROUP BY ingredient_id
                ) d ON d.ingredient_id = h1.ingredient_id AND d.invoice_date = h1.invoice_date
                GROUP BY h1.ingredient_id
            ) pick ON pick.id = h.id';
    $map = [];
    foreach ($db->query($sql) as $row) {
        $map[(int)$row['ingredient_id']] = $row;
    }
    return $map;
}

/**
 * Printable draft only. sends is always false.
 * $onlyIds limits the list for tests; the page passes null and includes every flagged ingredient.
 *
 * @param list<int>|null $onlyIds
 * @return array{sends:bool, vendors:list<array{vendor:string, unassigned:bool, lines:list<array<string, mixed>>}>}
 */
function bakery_ingredient_draft_purchase_order(PDO $db, ?array $onlyIds = null): array
{
    $empty = ['sends' => false, 'vendors' => []];
    if (!function_exists('table_exists') || !table_exists($db, 'ingredients')) {
        return $empty;
    }
    $sql = 'SELECT id, name, unit, quantity_on_hand, reorder_level, supplier_name';
    if (bakery_ingredient_reorder_qty_ready($db)) {
        $sql .= ', reorder_qty';
    }
    $sql .= ' FROM ingredients';
    $params = [];
    if ($onlyIds !== null) {
        $ids = [];
        foreach ($onlyIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return $empty;
        }
        $sql .= ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = $ids;
    }
    $sql .= ' ORDER BY name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $prices = bakery_ingredient_current_prices_map($db);
    $buckets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!bakery_ingredient_needs_reorder($row)) {
            continue;
        }
        $price = $prices[(int)$row['id']] ?? null;
        $vendor = trim((string)($price['vendor'] ?? ''));
        if ($vendor === '') {
            $vendor = trim((string)($row['supplier_name'] ?? ''));
        }
        $unassigned = $vendor === '';
        if ($unassigned) {
            $vendor = 'No vendor';
        }
        $reorderQty = $row['reorder_qty'] ?? null;
        $suggested = ($reorderQty !== null && $reorderQty !== '' && (float)$reorderQty > 0)
            ? (float)$reorderQty
            : 1.0;
        $buckets[$vendor][] = [
            'ingredient_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'unit' => (string)($row['unit'] ?? ''),
            'on_hand' => ($row['quantity_on_hand'] === null || $row['quantity_on_hand'] === '')
                ? 0.0
                : (float)$row['quantity_on_hand'],
            'reorder_point' => ($row['reorder_level'] === null || $row['reorder_level'] === '')
                ? null
                : (float)$row['reorder_level'],
            'suggested_qty' => $suggested,
            'vendor' => $vendor,
            'unassigned' => $unassigned,
            'vendor_sku' => $price['vendor_sku'] ?? null,
            'pack_size_grams' => isset($price['pack_size_grams']) ? (float)$price['pack_size_grams'] : null,
            'pack_price' => isset($price['pack_price']) && $price['pack_price'] !== null && $price['pack_price'] !== ''
                ? (float)$price['pack_price']
                : null,
            'cost_per_kg' => isset($price['cost_per_kg']) && $price['cost_per_kg'] !== null && $price['cost_per_kg'] !== ''
                ? (float)$price['cost_per_kg']
                : null,
            'invoice_number' => $price['invoice_number'] ?? null,
            'invoice_date' => $price['invoice_date'] ?? null,
        ];
    }
    $vendors = array_keys($buckets);
    sort($vendors, SORT_STRING);
    $ordered = [];
    $unassignedGroup = null;
    foreach ($vendors as $vendor) {
        $lines = $buckets[$vendor];
        usort($lines, static function (array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
        $group = [
            'vendor' => $vendor,
            'unassigned' => $vendor === 'No vendor',
            'lines' => $lines,
        ];
        if ($group['unassigned']) {
            $unassignedGroup = $group;
            continue;
        }
        $ordered[] = $group;
    }
    if ($unassignedGroup !== null) {
        $ordered[] = $unassignedGroup;
    }
    return ['sends' => false, 'vendors' => $ordered];
}

/**
 * Local import targets only. Live and hosted Staging names are refused.
 */
function bakery_ingredient_prices_assert_import_target(PDO $db): void
{
    if (!function_exists('bakery_assert_local_connection')) {
        require_once __DIR__ . '/test_target_guard.php';
    }
    bakery_assert_local_connection($db, ['bakerysf_test', 'bakerysf_local', 'bakerysf_stage_local']);
}

/**
 * @return array{dry_run:bool, applied:int, matched:list<array<string,mixed>>, unmatched:list<array<string,mixed>>, invalid:list<array<string,mixed>>}
 */
function bakery_ingredient_prices_import_csv(PDO $db, string $path, bool $apply): array
{
    bakery_ingredient_prices_assert_import_target($db);
    if (!bakery_ingredient_prices_ready($db)) {
        throw new RuntimeException('Price history table is missing. Run database migrations first.');
    }
    if (!is_readable($path)) {
        throw new RuntimeException('CSV is not readable.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('CSV is not readable.');
    }
    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        throw new RuntimeException('CSV is missing a header row.');
    }
    $columns = bakery_ingredient_price_csv_columns($header);
    foreach (['name', 'vendor', 'sku', 'pack_grams', 'pack_price', 'invoice_number', 'invoice_date'] as $required) {
        if (!isset($columns[$required])) {
            fclose($handle);
            throw new RuntimeException('CSV is missing the ' . $required . ' column.');
        }
    }

    $names = [];
    foreach ($db->query('SELECT id, name FROM ingredients') as $row) {
        $names[bakery_ingredient_price_name_key((string)$row['name'])] = (int)$row['id'];
    }

    $matched = [];
    $unmatched = [];
    $invalid = [];
    $line = 1;
    while (($cells = fgetcsv($handle)) !== false) {
        $line++;
        if ($cells === [null] || (count($cells) === 1 && trim((string)$cells[0]) === '')) {
            continue;
        }
        $name = trim((string)($cells[$columns['name']] ?? ''));
        $payload = [
            'ingredient_name' => $name,
            'vendor' => trim((string)($cells[$columns['vendor']] ?? '')),
            'vendor_sku' => trim((string)($cells[$columns['sku']] ?? '')),
            'pack_size_grams' => trim((string)($cells[$columns['pack_grams']] ?? '')),
            'pack_price' => trim((string)($cells[$columns['pack_price']] ?? '')),
            'invoice_number' => trim((string)($cells[$columns['invoice_number']] ?? '')),
            'invoice_date' => trim((string)($cells[$columns['invoice_date']] ?? '')),
            'line' => $line,
        ];
        $key = bakery_ingredient_price_name_key($name);
        if ($name === '' || !isset($names[$key])) {
            $unmatched[] = $payload;
            continue;
        }
        $payload['ingredient_id'] = $names[$key];
        $error = bakery_ingredient_price_validate($payload);
        if ($error !== null) {
            $payload['error'] = $error;
            $invalid[] = $payload;
            continue;
        }
        $matched[] = $payload;
    }
    fclose($handle);

    $applied = 0;
    if ($apply && $matched !== []) {
        $db->beginTransaction();
        try {
            foreach ($matched as $payload) {
                $result = bakery_ingredient_price_add($db, $payload);
                if (!($result['ok'] ?? false)) {
                    throw new RuntimeException((string)($result['error'] ?? 'import failed'));
                }
                $applied++;
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    return [
        'dry_run' => !$apply,
        'applied' => $applied,
        'matched' => $matched,
        'unmatched' => $unmatched,
        'invalid' => $invalid,
    ];
}

/**
 * @param list<string|null> $header
 * @return array<string, int>
 */
function bakery_ingredient_price_csv_columns(array $header): array
{
    $aliases = [
        'ingredient name' => 'name',
        'ingredient_name' => 'name',
        'ingredient' => 'name',
        'name' => 'name',
        'vendor' => 'vendor',
        'sku' => 'sku',
        'vendor sku' => 'sku',
        'vendor_sku' => 'sku',
        'pack grams' => 'pack_grams',
        'pack_grams' => 'pack_grams',
        'pack size grams' => 'pack_grams',
        'pack price' => 'pack_price',
        'pack_price' => 'pack_price',
        'invoice number' => 'invoice_number',
        'invoice_number' => 'invoice_number',
        'invoice date' => 'invoice_date',
        'invoice_date' => 'invoice_date',
    ];
    $columns = [];
    foreach ($header as $index => $label) {
        $label = (string)$label;
        if ($index === 0) {
            $label = preg_replace('/^\xEF\xBB\xBF/', '', $label) ?? $label;
        }
        $key = strtolower(trim((string)(preg_replace('/\s+/', ' ', $label) ?? $label)));
        if (isset($aliases[$key]) && !isset($columns[$aliases[$key]])) {
            $columns[$aliases[$key]] = (int)$index;
        }
    }
    return $columns;
}

function bakery_ingredient_format_cost_per_kg($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    return number_format((float)$value, 4, '.', '');
}
