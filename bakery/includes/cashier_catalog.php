<?php
/**
 * Cashier catalog helpers — create bakery or retail products for photo capture.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Ensure Retail product line + Store shelf dough type exist; return dough type id. */
function bakery_ensure_retail_store_shelf(PDO $db): int
{
    if (!table_exists($db, 'product_lines') || !table_exists($db, 'dough_types')) {
        return 0;
    }

    $db->exec(
        "INSERT IGNORE INTO product_lines (name, description, color_code, sort_order)
         VALUES ('Retail', 'Store shelf items — coffee, chips, snacks, and other retail', '#6b8f71', 900)"
    );

    $lineId = (int)$db->query(
        "SELECT id FROM product_lines WHERE name = 'Retail' LIMIT 1"
    )->fetchColumn();
    if ($lineId <= 0) {
        return 0;
    }

    $existing = $db->prepare(
        "SELECT id FROM dough_types WHERE name = 'Store shelf' LIMIT 1"
    );
    $existing->execute();
    $doughId = (int)$existing->fetchColumn();
    if ($doughId > 0) {
        $db->prepare('UPDATE dough_types SET product_line_id = ? WHERE id = ? AND (product_line_id IS NULL OR product_line_id <> ?)')
            ->execute([$lineId, $doughId, $lineId]);
        return $doughId;
    }

    $ins = $db->prepare(
        "INSERT INTO dough_types (name, description, product_line_id)
         VALUES ('Store shelf', 'Retail store items (not a bakery dough)', ?)"
    );
    $ins->execute([$lineId]);
    return (int)$db->lastInsertId();
}

/**
 * Create a product for the cashier catalog flow.
 *
 * @param array{name:string,price:?float,kind:string,dough_type_id?:?int,description?:string} $input
 * @return array{ok:bool,id?:int,error?:string}
 */
function bakery_cashier_create_product(PDO $db, array $input): array
{
    if (!table_exists($db, 'products')) {
        return ['ok' => false, 'error' => 'products_missing'];
    }

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        return ['ok' => false, 'error' => 'name_required'];
    }

    $kind = strtolower(trim((string)($input['kind'] ?? 'retail')));
    if (!in_array($kind, ['bakery', 'retail'], true)) {
        $kind = 'retail';
    }

    $priceRaw = $input['price'] ?? '';
    if ($priceRaw === '' || $priceRaw === null) {
        $price = 0.0;
    } else {
        $price = round((float)$priceRaw, 2);
        if ($price < 0 || $price > 99999) {
            return ['ok' => false, 'error' => 'price_invalid'];
        }
    }

    $description = trim((string)($input['description'] ?? ''));
    $doughTypeId = null;

    if ($kind === 'bakery') {
        $doughTypeId = (int)($input['dough_type_id'] ?? 0);
        if ($doughTypeId <= 0) {
            return ['ok' => false, 'error' => 'dough_required'];
        }
        $check = $db->prepare('SELECT id FROM dough_types WHERE id = ? LIMIT 1');
        $check->execute([$doughTypeId]);
        if (!$check->fetchColumn()) {
            return ['ok' => false, 'error' => 'dough_required'];
        }
    } else {
        $doughTypeId = bakery_ensure_retail_store_shelf($db);
        if ($doughTypeId <= 0) {
            $doughTypeId = null; // still allow create without grouping
        }
    }

    $dup = $db->prepare('SELECT id FROM products WHERE name = ? LIMIT 1');
    $dup->execute([$name]);
    if ($dup->fetchColumn()) {
        return ['ok' => false, 'error' => 'name_taken'];
    }

    $hasWholesale = false;
    try {
        $col = $db->query("SHOW COLUMNS FROM products LIKE 'wholesale_price'");
        $hasWholesale = (bool)$col->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $hasWholesale = false;
    }

    try {
        if ($hasWholesale) {
            $stmt = $db->prepare(
                'INSERT INTO products (name, description, price, wholesale_price, weight_grams, dough_type_id)
                 VALUES (?, ?, ?, NULL, NULL, ?)'
            );
            $stmt->execute([$name, $description, $price, $doughTypeId]);
        } else {
            $stmt = $db->prepare(
                'INSERT INTO products (name, description, price, weight_grams, dough_type_id)
                 VALUES (?, ?, ?, NULL, ?)'
            );
            $stmt->execute([$name, $description, $price, $doughTypeId]);
        }
    } catch (Throwable $e) {
        error_log('cashier create product failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'save_failed'];
    }

    return ['ok' => true, 'id' => (int)$db->lastInsertId(), 'kind' => $kind];
}

/** Dough types for cashier bakery picker (id, name, line_name). */
function bakery_cashier_dough_options(PDO $db): array
{
    if (!table_exists($db, 'dough_types')) {
        return [];
    }
    $sql = 'SELECT dt.id, dt.name, pl.name AS line_name
            FROM dough_types dt
            LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
            WHERE dt.name <> ?
            ORDER BY COALESCE(pl.sort_order, 9999), pl.name, dt.name';
    $stmt = $db->prepare($sql);
    $stmt->execute(['Store shelf']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
