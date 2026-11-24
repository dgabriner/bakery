<?php
/**
 * Wholesale price import.
 *
 * Preview reads the catalog and writes nothing. Apply updates
 * products.wholesale_price only, in one transaction, and audits each change.
 * products.price, weights, and order lines are never written.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/audit_log.php';

function bakery_wholesale_price_import_roles(): array
{
    return ['administrator', 'manager'];
}

/**
 * Standing products on a Wholesale account that have no wholesale price and a
 * $0 catalog price. Retail and custom accounts are never included.
 *
 * @return list<array{customer_id:int,product_id:int,product_name:string}>
 */
function bakery_wholesale_zero_price_standing(PDO $db, ?int $customerId = null): array
{
    if ($customerId !== null && $customerId <= 0) {
        return [];
    }
    $sql = 'SELECT c.id AS customer_id, p.id AS product_id, p.name AS product_name
        FROM customers c
        INNER JOIN standing_orders so ON so.customer_id = c.id
        INNER JOIN products p ON p.id = so.product_id
        WHERE c.pricing_tier = ?
          AND so.quantity > 0
          AND (p.wholesale_price IS NULL OR p.wholesale_price = 0)
          AND (p.price IS NULL OR p.price = 0)';
    $params = ['wholesale'];
    if ($customerId !== null) {
        $sql .= ' AND c.id = ?';
        $params[] = $customerId;
    }
    $sql .= ' ORDER BY p.name, p.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $seen = [];
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (int)$row['customer_id'] . ':' . (int)$row['product_id'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'customer_id' => (int)$row['customer_id'],
            'product_id' => (int)$row['product_id'],
            'product_name' => (string)$row['product_name'],
        ];
    }
    return $out;
}

/**
 * @return array<int, list<string>>
 */
function bakery_wholesale_zero_price_standing_map(PDO $db): array
{
    $map = [];
    foreach (bakery_wholesale_zero_price_standing($db, null) as $row) {
        $map[$row['customer_id']][] = $row['product_name'];
    }
    return $map;
}

/**
 * @return list<string>
 */
function bakery_wholesale_zero_price_product_names(PDO $db, int $customerId): array
{
    $names = [];
    foreach (bakery_wholesale_zero_price_standing($db, $customerId) as $row) {
        $names[] = $row['product_name'];
    }
    return $names;
}

/**
 * @param list<string> $productNames
 */
function bakery_wholesale_zero_price_warning_text(array $productNames): string
{
    $names = [];
    foreach ($productNames as $name) {
        $name = trim((string)$name);
        if ($name !== '' && !in_array($name, $names, true)) {
            $names[] = $name;
        }
    }
    if ($names === []) {
        return '';
    }
    return bakery_t('wholesale_import.zero_guard', ['products' => implode(', ', $names)]);
}

/**
 * @return list<array{line:int,product_id:?int,name:?string,raw_price:string}>
 */
function bakery_wholesale_price_import_parse(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $lines = preg_split('/\r\n|\n|\r/', $csv) ?: [];
    $rows = [];
    $header = null;
    $delimiter = null;
    $lineNo = 0;
    foreach ($lines as $line) {
        $lineNo++;
        if (trim($line) === '') {
            continue;
        }
        if ($delimiter === null) {
            $delimiter = bakery_wholesale_price_import_delimiter($line);
        }
        $cells = str_getcsv($line, $delimiter);
        if ($header === null) {
            $header = bakery_wholesale_price_import_header_map($cells);
            if ($header !== null) {
                continue;
            }
            $header = false;
        }
        if ($header === false) {
            $idRaw = trim((string)($cells[0] ?? ''));
            $priceRaw = trim((string)($cells[1] ?? ''));
            $productId = preg_match('/^\d+$/', $idRaw) ? (int)$idRaw : null;
            $name = $productId === null ? $idRaw : null;
            $rows[] = [
                'line' => $lineNo,
                'product_id' => $productId !== null && $productId > 0 ? $productId : null,
                'name' => $name !== '' ? $name : null,
                'raw_price' => $priceRaw,
            ];
            continue;
        }
        $idCell = isset($header['id']) ? trim((string)($cells[$header['id']] ?? '')) : '';
        $nameCell = isset($header['name']) ? trim((string)($cells[$header['name']] ?? '')) : '';
        $priceCell = trim((string)($cells[$header['price']] ?? ''));
        $productId = ($idCell !== '' && preg_match('/^\d+$/', $idCell)) ? (int)$idCell : null;
        if ($productId === null && $nameCell === '' && $idCell !== '') {
            $nameCell = $idCell;
        }
        $rows[] = [
            'line' => $lineNo,
            'product_id' => $productId !== null && $productId > 0 ? $productId : null,
            'name' => ($productId === null && $nameCell !== '') ? $nameCell : null,
            'raw_price' => $priceCell,
        ];
    }
    return $rows;
}

function bakery_wholesale_price_import_delimiter(string $line): string
{
    $counts = [
        ',' => substr_count($line, ','),
        ';' => substr_count($line, ';'),
        "\t" => substr_count($line, "\t"),
    ];
    arsort($counts);
    $best = array_key_first($counts);
    if ($best === null || ($counts[$best] ?? 0) < 1) {
        return ',';
    }
    return $best;
}

/**
 * @param list<string> $cells
 * @return array{id?:int,name?:int,price:int}|null
 */
function bakery_wholesale_price_import_header_map(array $cells): ?array
{
    $map = [];
    foreach ($cells as $index => $cell) {
        $key = strtolower(trim((string)$cell));
        $key = str_replace([' ', '-'], '_', $key);
        if (in_array($key, ['product_id', 'id', 'productid'], true)) {
            $map['id'] = (int)$index;
        } elseif (in_array($key, ['name', 'product', 'product_name', 'productname'], true)) {
            $map['name'] = (int)$index;
        } elseif (in_array($key, ['wholesale_price', 'wholesale', 'wholesaleprice', 'new_wholesale'], true)) {
            $map['price'] = (int)$index;
        }
    }
    if (!isset($map['price']) || (!isset($map['id']) && !isset($map['name']))) {
        return null;
    }
    return $map;
}

/**
 * @return array{ok:bool,value:?string,warning:?string}
 */
function bakery_wholesale_price_import_parse_price(string $raw): array
{
    $trim = trim($raw);
    if ($trim === '') {
        return ['ok' => false, 'value' => null, 'warning' => 'non_numeric'];
    }
    $clean = str_replace(['$', ' ', ','], '', $trim);
    if (!preg_match('/^-?\d+(?:\.\d+)?$/', $clean)) {
        return ['ok' => false, 'value' => null, 'warning' => 'non_numeric'];
    }
    $money = number_format(round((float)$clean, 2), 2, '.', '');
    if ((float)$money < 0) {
        return ['ok' => true, 'value' => $money, 'warning' => 'negative'];
    }
    if ((float)$money == 0.0) {
        return ['ok' => true, 'value' => $money, 'warning' => 'zero'];
    }
    return ['ok' => true, 'value' => $money, 'warning' => null];
}

function bakery_wholesale_price_import_money($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return number_format(round((float)$value, 2), 2, '.', '');
}

function bakery_wholesale_price_import_big_change(?string $catalogMoney, string $newMoney): bool
{
    $new = (float)$newMoney;
    if ($catalogMoney === null || abs((float)$catalogMoney) < 0.00001) {
        return abs($new) > 0;
    }
    return abs($new - (float)$catalogMoney) / abs((float)$catalogMoney) > 0.50 + 1e-9;
}

function bakery_wholesale_price_import_change_text(?string $current, ?string $new): string
{
    if ($new === null) {
        return '';
    }
    if ($current === null) {
        return '+' . $new;
    }
    $delta = round((float)$new - (float)$current, 2);
    if (abs($delta) < 0.001) {
        return '0.00';
    }
    $sign = $delta > 0 ? '+' : '-';
    return $sign . number_format(abs($delta), 2, '.', '');
}

/**
 * @return array{by_id:array<int,array<string,mixed>>,by_name:array<string,array<string,mixed>>}
 */
function bakery_wholesale_price_import_catalog(PDO $db): array
{
    $byId = [];
    $byName = [];
    $stmt = $db->query('SELECT id, name, price, wholesale_price, weight_grams FROM products');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $byId[$id] = $row;
        $byName[(string)$row['name']] = $row;
    }
    return ['by_id' => $byId, 'by_name' => $byName];
}

/**
 * Read-only preview. This function does not begin a transaction and does not write.
 *
 * @return array{rows:list<array<string,mixed>>,applicable:int,skipped:int}
 */
function bakery_wholesale_price_import_preview(PDO $db, string $csv): array
{
    $catalog = bakery_wholesale_price_import_catalog($db);
    $parsed = bakery_wholesale_price_import_parse($csv);
    $drafts = [];
    foreach ($parsed as $row) {
        $product = null;
        if ($row['product_id'] !== null) {
            $product = $catalog['by_id'][$row['product_id']] ?? null;
        } elseif ($row['name'] !== null) {
            $product = $catalog['by_name'][$row['name']] ?? null;
        }
        $price = bakery_wholesale_price_import_parse_price($row['raw_price']);
        $warnings = [];
        if ($product === null) {
            $warnings[] = 'unknown';
        }
        if ($price['warning'] !== null) {
            $warnings[] = $price['warning'];
        }
        $productId = $product !== null ? (int)$product['id'] : 0;
        if ($productId > 0) {
            $key = 'product:' . $productId;
        } elseif ($row['product_id'] !== null) {
            $key = 'id:' . $row['product_id'];
        } elseif ($row['name'] !== null && $row['name'] !== '') {
            $key = 'name:' . $row['name'];
        } else {
            $key = 'line:' . $row['line'];
        }
        $catalogMoney = $product !== null ? bakery_wholesale_price_import_money($product['price']) : null;
        $currentMoney = $product !== null ? bakery_wholesale_price_import_money($product['wholesale_price']) : null;
        if ($product !== null && $price['value'] !== null
            && bakery_wholesale_price_import_big_change($catalogMoney, $price['value'])) {
            $warnings[] = 'big_change';
        }
        $label = $product !== null
            ? (string)$product['name']
            : (string)($row['name'] ?? ($row['product_id'] !== null ? ('#' . $row['product_id']) : ''));
        $drafts[] = [
            'line' => $row['line'],
            'key' => $key,
            'product_id' => $productId,
            'product_name' => $label,
            'catalog_price' => $catalogMoney,
            'current_wholesale' => $currentMoney,
            'new_wholesale' => $price['value'],
            'warnings' => $warnings,
        ];
    }
    $counts = [];
    foreach ($drafts as $draft) {
        $counts[$draft['key']] = ($counts[$draft['key']] ?? 0) + 1;
    }
    $rows = [];
    $applicable = 0;
    foreach ($drafts as $draft) {
        $warnings = $draft['warnings'];
        if (($counts[$draft['key']] ?? 0) > 1) {
            $warnings[] = 'duplicate';
        }
        $warnings = bakery_wholesale_price_import_sort_warnings($warnings);
        $blocking = array_intersect($warnings, ['unknown', 'duplicate', 'non_numeric', 'negative']);
        $changed = $draft['new_wholesale'] !== null && $draft['new_wholesale'] !== $draft['current_wholesale'];
        $apply = $draft['product_id'] > 0 && $blocking === [] && $changed;
        if ($apply) {
            $applicable++;
        }
        $rows[] = [
            'line' => $draft['line'],
            'product_id' => $draft['product_id'],
            'product_name' => $draft['product_name'],
            'catalog_price' => $draft['catalog_price'],
            'current_wholesale' => $draft['current_wholesale'],
            'new_wholesale' => $draft['new_wholesale'],
            'change' => bakery_wholesale_price_import_change_text($draft['current_wholesale'], $draft['new_wholesale']),
            'warnings' => $warnings,
            'apply' => $apply,
        ];
    }
    return [
        'rows' => $rows,
        'applicable' => $applicable,
        'skipped' => count($rows) - $applicable,
    ];
}

/**
 * @param list<string> $warnings
 * @return list<string>
 */
function bakery_wholesale_price_import_sort_warnings(array $warnings): array
{
    $order = ['unknown' => 0, 'duplicate' => 1, 'non_numeric' => 2, 'negative' => 3, 'zero' => 4, 'big_change' => 5];
    $unique = [];
    foreach ($warnings as $warning) {
        $unique[(string)$warning] = true;
    }
    $codes = array_keys($unique);
    usort($codes, static function (string $a, string $b) use ($order): int {
        return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
    });
    return $codes;
}

function bakery_wholesale_price_import_warning_label(string $code): string
{
    switch ($code) {
        case 'unknown':
            return bakery_t('wholesale_import.warn_unknown');
        case 'duplicate':
            return bakery_t('wholesale_import.warn_duplicate');
        case 'non_numeric':
            return bakery_t('wholesale_import.warn_non_numeric');
        case 'negative':
            return bakery_t('wholesale_import.warn_negative');
        case 'zero':
            return bakery_t('wholesale_import.warn_zero');
        case 'big_change':
            return bakery_t('wholesale_import.warn_big_change');
        default:
            $unknown = $code;
            throw new InvalidArgumentException('Unknown wholesale import warning: ' . $unknown);
    }
}

/**
 * Write products.wholesale_price for applicable preview rows. One transaction.
 * Does not update products.price, weight, or any order table.
 *
 * @return array{applied:int,rows:list<array<string,mixed>>}
 */
function bakery_wholesale_price_import_apply(PDO $db, string $csv): array
{
    $preview = bakery_wholesale_price_import_preview($db, $csv);
    $targets = [];
    foreach ($preview['rows'] as $row) {
        if (!empty($row['apply'])) {
            $targets[] = $row;
        }
    }
    if ($targets === []) {
        return ['applied' => 0, 'rows' => $preview['rows']];
    }
    $db->beginTransaction();
    try {
        $select = $db->prepare(
            'SELECT id, price, wholesale_price, weight_grams FROM products WHERE id = ? FOR UPDATE'
        );
        $update = $db->prepare('UPDATE products SET wholesale_price = ? WHERE id = ?');
        $applied = 0;
        foreach ($targets as $row) {
            $select->execute([(int)$row['product_id']]);
            $current = $select->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                continue;
            }
            $newMoney = (string)$row['new_wholesale'];
            $oldMoney = bakery_wholesale_price_import_money($current['wholesale_price']);
            if ($oldMoney === $newMoney) {
                continue;
            }
            $update->execute([$newMoney, (int)$current['id']]);
            bakery_audit_product_fields($db, (int)$current['id'], [
                'wholesale_price' => $current['wholesale_price'],
            ], [
                'wholesale_price' => $newMoney,
            ]);
            $applied++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['applied' => $applied, 'rows' => $preview['rows']];
}

function bakery_wholesale_price_import_money_label(?string $money): string
{
    if ($money === null) {
        return bakery_t('wholesale_import.unset');
    }
    return '$' . $money;
}

/**
 * @param array{csv:string,preview:?array,error:?string,flash:?array} $state
 */
function bakery_wholesale_price_import_render(array $state): string
{
    $csv = (string)($state['csv'] ?? '');
    $preview = $state['preview'] ?? null;
    $error = $state['error'] ?? null;
    $flash = $state['flash'] ?? null;
    ob_start();
    ?>
<div class="container wholesale-import-page">
    <header class="wholesale-import-header">
        <p class="wholesale-import-kicker"><a href="products.php"><?php echo htmlspecialchars(bakery_t('wholesale_import.back_products')); ?></a>
            · <a href="customer_pricing.php"><?php echo htmlspecialchars(bakery_t('nav.item.customer_pricing')); ?></a></p>
        <h1><?php echo htmlspecialchars(bakery_t('wholesale_import.heading')); ?></h1>
        <p><?php echo htmlspecialchars(bakery_t('wholesale_import.intro')); ?></p>
    </header>
    <?php if (is_array($flash)): ?>
        <p class="wholesale-import-saved" role="status"><?php
            $applied = (int)($flash['applied'] ?? 0);
            echo htmlspecialchars($applied > 0
                ? bakery_t('wholesale_import.applied', ['count' => (string)$applied])
                : bakery_t('wholesale_import.nothing_applied'));
        ?></p>
    <?php endif; ?>
    <?php if (is_string($error) && $error !== ''): ?>
        <p class="wholesale-import-error" role="alert"><?php echo htmlspecialchars(bakery_t($error)); ?></p>
    <?php endif; ?>
    <form class="wholesale-import-form" method="post" action="wholesale_price_import.php" enctype="multipart/form-data">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="preview">
        <label for="wholesale-csv-file"><?php echo htmlspecialchars(bakery_t('wholesale_import.file_label')); ?></label>
        <input id="wholesale-csv-file" name="csv_file" type="file" accept=".csv,text/csv,text/plain">
        <label for="wholesale-csv"><?php echo htmlspecialchars(bakery_t('wholesale_import.paste_label')); ?></label>
        <textarea id="wholesale-csv" name="csv" rows="8" placeholder="<?php echo htmlspecialchars(bakery_t('wholesale_import.placeholder')); ?>"><?php echo htmlspecialchars($csv); ?></textarea>
        <p class="wholesale-import-hint"><?php echo htmlspecialchars(bakery_t('wholesale_import.example')); ?></p>
        <button type="submit"><?php echo htmlspecialchars(bakery_t('wholesale_import.preview')); ?></button>
    </form>
    <?php if (is_array($preview)): ?>
        <?php echo bakery_wholesale_price_import_render_preview($preview, $csv); ?>
    <?php endif; ?>
</div>
<style>
    .wholesale-import-page { padding: 24px 16px 64px; color: var(--sf-text, #1c2a26); max-width: 1080px; }
    .wholesale-import-kicker { margin: 0 0 6px; font-size: 0.85rem; }
    .wholesale-import-kicker a { color: var(--sf-primary, #24746a); font-weight: 700; }
    .wholesale-import-header h1 { margin: 0 0 8px; font-size: 1.7rem; }
    .wholesale-import-header p, .wholesale-import-hint { margin: 0; color: var(--sf-text-secondary, #4a5c57); }
    .wholesale-import-form, .wholesale-import-apply { display: flex; flex-direction: column; gap: 8px; margin: 16px 0; }
    .wholesale-import-form label { font-weight: 700; }
    .wholesale-import-form input[type="file"], .wholesale-import-form textarea {
        width: 100%; min-height: var(--sf-touch-min, 44px); padding: 10px;
        border: 1px solid var(--sf-border-strong, #c9bda9); border-radius: 8px; background: var(--sf-surface, #fffefb);
        font: inherit;
    }
    .wholesale-import-form textarea { min-height: 8rem; }
    .wholesale-import-form button, .wholesale-import-apply button {
        min-height: var(--sf-touch-min, 44px); padding: 0 16px; border-radius: 8px; border: 0;
        background: var(--sf-primary, #24746a); color: #fff; font-weight: 700; cursor: pointer;
    }
    .wholesale-import-note { margin: 12px 0; font-weight: 700; }
    .wholesale-import-scroll { overflow-x: auto; border: 1px solid var(--sf-border, #ddd4c6); border-radius: 12px; background: var(--sf-surface, #fffefb); }
    .wholesale-import-table { width: 100%; border-collapse: collapse; min-width: 640px; }
    .wholesale-import-table th, .wholesale-import-table td { padding: 10px 12px; border-bottom: 1px solid var(--sf-border, #ddd4c6); text-align: left; vertical-align: top; }
    .wholesale-import-table thead th { background: var(--sf-surface-muted, #f3eee6); }
    .wholesale-import-row--warn { background: #fff7f2; }
    .wholesale-import-warn { color: #8f2d2d; font-weight: 700; }
    .wholesale-import-saved { color: #176b5d; font-weight: 700; }
    .wholesale-import-error { color: #8f2d2d; font-weight: 700; }
    .wholesale-import-skip { color: var(--sf-text-muted, #6b7d78); }
    .wholesale-import-page .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
    @media (max-width: 700px) {
        .wholesale-import-page { padding: 16px 12px 72px; }
        .wholesale-import-form button, .wholesale-import-apply button { width: 100%; }
        .wholesale-import-scroll { overflow: visible; border: 0; background: transparent; }
        .wholesale-import-table { min-width: 0; }
        .wholesale-import-table thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
        .wholesale-import-table tr { display: block; margin: 0 0 12px; border: 1px solid var(--sf-border, #ddd4c6); border-radius: 12px; background: var(--sf-surface, #fffefb); }
        .wholesale-import-table th, .wholesale-import-table td { display: flex; justify-content: space-between; gap: 12px; width: auto; }
        .wholesale-import-table th::before, .wholesale-import-table td::before { content: attr(data-label); flex: 0 0 42%; font-size: 0.78rem; font-weight: 700; color: var(--sf-text-secondary, #4a5c57); }
        .wholesale-import-row--warn { background: #fff7f2; }
    }
</style>
    <?php
    return (string)ob_get_clean();
}

/**
 * @param array{rows:list<array<string,mixed>>,applicable:int,skipped:int} $preview
 */
function bakery_wholesale_price_import_render_preview(array $preview, string $csv): string
{
    ob_start();
    ?>
<section class="wholesale-import-preview" aria-label="<?php echo htmlspecialchars(bakery_t('wholesale_import.preview')); ?>">
    <p class="wholesale-import-note"><?php echo htmlspecialchars(bakery_t('wholesale_import.preview_note')); ?>
        <?php echo htmlspecialchars(bakery_t('wholesale_import.will_apply', [
            'count' => (string)(int)$preview['applicable'],
            'skip' => (string)(int)$preview['skipped'],
        ])); ?></p>
    <?php if ($preview['rows'] === []): ?>
        <p><?php echo htmlspecialchars(bakery_t('wholesale_import.empty')); ?></p>
    <?php else: ?>
        <div class="wholesale-import-scroll">
            <table class="wholesale-import-table">
                <caption class="sr-only"><?php echo htmlspecialchars(bakery_t('wholesale_import.heading')); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_product')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_catalog')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_current')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_new')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_change')); ?></th>
                        <th scope="col"><?php echo htmlspecialchars(bakery_t('wholesale_import.col_warnings')); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $colProduct = bakery_t('wholesale_import.col_product');
                $colCatalog = bakery_t('wholesale_import.col_catalog');
                $colCurrent = bakery_t('wholesale_import.col_current');
                $colNew = bakery_t('wholesale_import.col_new');
                $colChange = bakery_t('wholesale_import.col_change');
                $colWarnings = bakery_t('wholesale_import.col_warnings');
                foreach ($preview['rows'] as $row):
                    $warn = $row['warnings'] !== [];
                ?>
                    <tr class="<?php echo $warn ? 'wholesale-import-row--warn' : ''; ?>">
                        <th scope="row" data-label="<?php echo htmlspecialchars($colProduct); ?>"><?php echo htmlspecialchars((string)$row['product_name']); ?></th>
                        <td data-label="<?php echo htmlspecialchars($colCatalog); ?>"><?php echo htmlspecialchars($row['catalog_price'] === null ? '' : bakery_wholesale_price_import_money_label($row['catalog_price'])); ?></td>
                        <td data-label="<?php echo htmlspecialchars($colCurrent); ?>"><?php echo htmlspecialchars(bakery_wholesale_price_import_money_label($row['current_wholesale'])); ?></td>
                        <td data-label="<?php echo htmlspecialchars($colNew); ?>"><?php echo htmlspecialchars($row['new_wholesale'] === null ? '' : bakery_wholesale_price_import_money_label((string)$row['new_wholesale'])); ?></td>
                        <td data-label="<?php echo htmlspecialchars($colChange); ?>"><?php echo htmlspecialchars((string)$row['change']); ?></td>
                        <td class="<?php echo $warn ? 'wholesale-import-warn' : 'wholesale-import-skip'; ?>" data-label="<?php echo htmlspecialchars($colWarnings); ?>">
                            <?php
                            $labels = [];
                            foreach ($row['warnings'] as $code) {
                                $labels[] = bakery_wholesale_price_import_warning_label((string)$code);
                            }
                            $warningText = $labels === []
                                ? bakery_t('wholesale_import.no_warning')
                                : implode(', ', $labels);
                            if (empty($row['apply'])) {
                                $warningText .= ' · ' . bakery_t('wholesale_import.not_written');
                            }
                            echo htmlspecialchars($warningText);
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ((int)$preview['applicable'] > 0): ?>
        <form class="wholesale-import-apply" method="post" action="wholesale_price_import.php">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="apply">
            <textarea name="csv" hidden><?php echo htmlspecialchars($csv); ?></textarea>
            <button type="submit"><?php echo htmlspecialchars(bakery_t('wholesale_import.apply')); ?></button>
        </form>
    <?php endif; ?>
</section>
    <?php
    return (string)ob_get_clean();
}

/**
 * CSV text from a preview POST. A non-empty upload wins over pasted text.
 */
function bakery_wholesale_price_import_request_csv(): string
{
    $file = $_FILES['csv_file'] ?? null;
    if (is_array($file)) {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('wholesale_import.file_too_large');
        }
        if ($error === UPLOAD_ERR_OK) {
            $size = (int)($file['size'] ?? 0);
            if ($size > 524288) {
                throw new RuntimeException('wholesale_import.file_too_large');
            }
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                $text = file_get_contents($tmp);
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        } elseif ($error !== UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('wholesale_import.bad_file');
        }
    }
    return (string)($_POST['csv'] ?? '');
}
