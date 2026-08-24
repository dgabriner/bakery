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
