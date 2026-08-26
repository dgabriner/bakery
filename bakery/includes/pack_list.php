<?php
/**
 * Pack List check-offs: shared per delivery date.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_pack_line_key(int $customerId, int $productId): string
{
    return 'c' . $customerId . '_p' . $productId;
}

function bakery_pack_line_key_valid(string $key): bool
{
    return $key !== '' && strlen($key) <= 64 && (bool)preg_match('/^c\d+_p\d+$/', $key);
}

function bakery_pack_progress_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'pack_progress');
}

function bakery_pack_set_checked(PDO $db, string $date, string $lineKey, bool $checked, ?int $userId): void
{
    if (!bakery_pack_progress_ready($db) || !bakery_pack_line_key_valid($lineKey)) {
        throw new InvalidArgumentException('Invalid pack line');
    }
    if ($checked) {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO pack_progress (pack_date, line_key, checked_by_user_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$date, $lineKey, $userId]);
        return;
    }
    $stmt = $db->prepare('DELETE FROM pack_progress WHERE pack_date = ? AND line_key = ?');
    $stmt->execute([$date, $lineKey]);
}

/**
 * Mark many pack lines packed. Existing checks stay. Returns rows present for the date.
 *
 * @param list<string> $lineKeys
 */
function bakery_pack_mark_keys(PDO $db, string $date, array $lineKeys, ?int $userId): int
{
    if (!bakery_pack_progress_ready($db)) {
        throw new RuntimeException('Packing check-offs are unavailable until database migrations are complete.');
    }
    $stmt = $db->prepare(
        'INSERT IGNORE INTO pack_progress (pack_date, line_key, checked_by_user_id) VALUES (?, ?, ?)'
    );
    $seen = [];
    foreach ($lineKeys as $key) {
        $key = trim((string)$key);
        if (!bakery_pack_line_key_valid($key) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $stmt->execute([$date, $key, $userId]);
    }
    $count = $db->prepare('SELECT COUNT(*) FROM pack_progress WHERE pack_date = ?');
    $count->execute([$date]);
    return (int)$count->fetchColumn();
}

/**
 * Compact POST form to mark one SKU or the whole day's missing bake as produced.
 *
 * @param array{product_id?:int,confirm?:string,class?:string,button_class?:string,driver_id?:int} $opts
 */
function bakery_pack_backfill_form_html(string $action, string $date, string $view, string $label, array $opts = []): string
{
    $productId = (int)($opts['product_id'] ?? 0);
    $confirm = (string)($opts['confirm'] ?? '');
    $class = (string)($opts['class'] ?? 'pack-all-form');
    $btnClass = (string)($opts['button_class'] ?? 'pack-btn');
    $formId = (string)($opts['form_id'] ?? '');
    $buttonOnly = !empty($opts['button_only']);
    $html = '';
    if (!$buttonOnly) {
        $html .= '<form method="post"';
        if ($formId !== '') {
            $html .= ' id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"';
        }
        $html .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
        if ($confirm !== '') {
            $encoded = json_encode(
                $confirm,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            $html .= ' onsubmit="return confirm(' . htmlspecialchars((string)$encoded, ENT_QUOTES, 'UTF-8') . ')"';
        }
        $html .= '>';
        $html .= function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
        $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="view" value="' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="delivery_date" value="' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '">';
        if ($productId > 0) {
            $html .= '<input type="hidden" name="product_id" value="' . $productId . '">';
        }
        if (isset($opts['driver_id'])) {
            $html .= '<input type="hidden" name="board_driver_id" value="' . (int)$opts['driver_id'] . '">';
        }
    }
    $html .= '<button type="submit" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '"';
    if ($buttonOnly && $formId !== '') {
        $html .= ' form="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"';
    }
    $html .= '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
    if (!$buttonOnly) {
        $html .= '</form>';
    }
    return $html;
}

/**
 * Packer day board rows: supposed bake/demand vs finished goods on hand.
 *
 * @param list<int>|null $allowedProductIds Null = all products with supposed or stock
 * @return list<array{
 *   product_id:int,
 *   product_name:string,
 *   dough_type:string,
 *   supposed:int,
 *   available:int,
 *   loaded:int,
 *   produced:int,
 *   covered:int,
 *   matches:bool,
 *   short:int,
 *   source:string
 * }>
 */
function bakery_pack_day_count_rows(PDO $db, string $date, ?array $allowedProductIds = null): array
{
    if (!function_exists('bakery_production_produce_targets_by_product')) {
        require_once __DIR__ . '/production_plan.php';
    }
    $targets = bakery_production_produce_targets_by_product($db, $date);
    $supposedByProduct = $targets['by_product'] ?? [];
    $source = (string)($targets['source'] ?? 'demand');

    $availableByProduct = [];
    $loadedByProduct = [];
    $producedByProduct = [];
    if (function_exists('bakery_inventory_ready') && bakery_inventory_ready($db)) {
        $invStmt = $db->prepare(
            'SELECT product_id, available_quantity, produced_quantity, loaded_quantity
             FROM product_inventory_days WHERE delivery_date = ?'
        );
        $invStmt->execute([$date]);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            $availableByProduct[$pid] = (int)$row['available_quantity'];
            $producedByProduct[$pid] = (int)$row['produced_quantity'];
            $loadedByProduct[$pid] = (int)($row['loaded_quantity'] ?? 0);
        }
    }

    $productIds = array_keys($supposedByProduct);
    foreach (array_keys($availableByProduct + $producedByProduct + $loadedByProduct) as $pid) {
        $productIds[] = (int)$pid;
    }
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (is_array($allowedProductIds)) {
        $allowed = array_flip(array_map('intval', $allowedProductIds));
        $productIds = array_values(array_filter($productIds, static function ($pid) use ($allowed) {
            return isset($allowed[(int)$pid]);
        }));
    }
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $nameStmt = $db->prepare(
        "SELECT p.id, p.name, COALESCE(dt.name, '') AS dough_type_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         WHERE p.id IN ($placeholders)"
    );
    $nameStmt->execute($productIds);
    $meta = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[(int)$row['id']] = [
            'name' => (string)$row['name'],
            'dough' => (string)$row['dough_type_name'],
        ];
    }

    $rows = [];
    foreach ($productIds as $productId) {
        $supposed = (int)($supposedByProduct[$productId] ?? 0);
        $available = (int)($availableByProduct[$productId] ?? 0);
        $loaded = (int)($loadedByProduct[$productId] ?? 0);
        $produced = (int)($producedByProduct[$productId] ?? 0);
        $covered = $available + $loaded;
        if ($supposed <= 0 && $covered <= 0 && $produced <= 0) {
            continue;
        }
        $rows[] = [
            'product_id' => $productId,
            'product_name' => (string)($meta[$productId]['name'] ?? ('Product #' . $productId)),
            'dough_type' => (string)($meta[$productId]['dough'] ?? ''),
            'supposed' => $supposed,
            'available' => $available,
            'loaded' => $loaded,
            'produced' => $produced,
            'covered' => $covered,
            'matches' => $supposed > 0 && $covered >= $supposed,
            'short' => max(0, $supposed - $covered),
            'source' => $source,
        ];
    }
    usort($rows, static function ($a, $b) {
        return strcasecmp($a['product_name'], $b['product_name']);
    });
    return $rows;
}

function bakery_pack_qty_html(PDO $db, int $productId, int $qty): string
{
    $qty = max(0, $qty);
    $html = '<div class="pack-line__qtywrap"><span class="pack-line__qty">' . number_format($qty) . '</span>';
    if ($qty > 0 && function_exists('bakery_pack_count_breakdown')) {
        $break = bakery_pack_count_breakdown($db, $productId, $qty);
        if (($break['trays'] ?? 0) > 0 || ($break['boxes'] ?? 0) > 0) {
            $html .= '<span class="pack-convert">' . htmlspecialchars((string)$break['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    return $html . '</div>';
}
