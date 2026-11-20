<?php
/**
 * Set the day: one sell day, standard order first, autosave into the
 * production plan, lock into the bake list Mix and Daily Production read.
 *
 * Formula grams, batch size, and ingredient pull go through baker_mix and
 * formula_structure. This file does not keep a second recipe.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/baker_mix.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/kitchen_segments.php';
require_once __DIR__ . '/production_cadence.php';

function bakery_baker_set_edit_roles(): array
{
    return ['baker', 'manager', 'administrator'];
}

function bakery_baker_set_unlock_roles(): array
{
    return ['manager', 'administrator'];
}

function bakery_baker_set_view_roles(): array
{
    return ['baker', 'manager', 'administrator', 'driver', 'driver_assistant', 'cashier', 'sales'];
}

function bakery_baker_set_can_edit(): bool
{
    return function_exists('bakery_user_has_role') && bakery_user_has_role(bakery_baker_set_edit_roles());
}

function bakery_baker_set_can_unlock(): bool
{
    return function_exists('bakery_user_has_role') && bakery_user_has_role(bakery_baker_set_unlock_roles());
}

function bakery_baker_set_default_date(?string $today = null): string
{
    return bakery_baker_home_delivery_date($today);
}

function bakery_baker_set_balance(int $planned, int $demand): string
{
    if ($planned < $demand) {
        return 'short';
    }
    if ($planned > $demand) {
        return 'over';
    }
    return 'match';
}

function bakery_baker_set_format_count(float $value): string
{
    $text = number_format($value, 2, '.', '');
    $text = rtrim(rtrim($text, '0'), '.');
    return $text === '' || $text === '-' ? '0' : $text;
}

function bakery_baker_set_format_amount(float $grams): string
{
    if (abs($grams) >= 100) {
        return number_format($grams, 0);
    }
    return bakery_baker_set_format_count($grams);
}

/**
 * Dough totals for a shape split. Piece weights come from the caller.
 * A missing weight leaves kg and gram-batches empty instead of inventing one.
 *
 * @param list<array<string, mixed>> $shapes
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $ingredients
 * @return array<string, mixed>
 */
function bakery_baker_set_shape_math(array $shapes, array $structure, float $totalPercentage, array $ingredients): array
{
    $structure = bakery_formula_normalize_structure($structure);
    $knownGrams = 0.0;
    $pieces = 0.0;
    $missing = [];
    $lossRows = [];
    $balances = [];
    foreach ($shapes as $shape) {
        if (!is_array($shape)) {
            continue;
        }
        $qty = (int) ($shape['quantity'] ?? 0);
        $weight = $shape['weight_grams'] ?? null;
        if ($weight === '' || $weight === null) {
            $weight = null;
        } else {
            $weight = (int) $weight;
            if ($weight <= 0) {
                $weight = null;
            }
        }
        $pieces += $qty;
        if ($qty > 0 && $weight === null) {
            $missing[] = (string) ($shape['name'] ?? '');
        } elseif ($weight !== null) {
            $knownGrams += $qty * $weight;
        }
        $lossRows[] = [
            'weight_grams' => $weight,
            'quantity' => $qty,
        ];
        $demand = (int) ($shape['demand_quantity'] ?? 0);
        $balances[] = [
            'product_id' => (int) ($shape['product_id'] ?? 0),
            'balance' => bakery_baker_set_balance($qty, $demand),
            'demand_quantity' => $demand,
        ];
    }

    $doughGrams = null;
    $loss = null;
    $kg = null;
    if ($missing === []) {
        $loss = bakery_formula_mix_loss_grams($structure, $lossRows);
        $doughGrams = $knownGrams + $loss;
        $kg = $doughGrams / 1000;
    }

    $reference = bakery_formula_batch_reference($structure, null);
    $pieceYield = $structure['pieces_per_batch'];
    if ($pieceYield === null && $reference['mode'] === 'pieces') {
        $pieceYield = $reference['pieces'];
    }
    $effectiveGrams = ($reference['mode'] === 'pieces') ? null : $reference['grams'];
    $batches = null;
    $mode = 'none';
    if ($reference['mode'] === 'pieces' && $pieceYield !== null && $pieceYield > 0) {
        $batches = $pieces / (float) $pieceYield;
        $mode = 'pieces';
    } elseif ($effectiveGrams !== null && $effectiveGrams > 0 && $missing === []) {
        $batches = ($doughGrams !== null && $doughGrams > 0) ? $doughGrams / (float) $effectiveGrams : 0.0;
        $mode = 'grams';
    } elseif ($pieceYield !== null && $pieceYield > 0 && $effectiveGrams === null) {
        $batches = $pieces / (float) $pieceYield;
        $mode = 'pieces';
    }
    $pieceBatches = ($pieceYield !== null && $pieceYield > 0) ? $pieces / (float) $pieceYield : null;

    $pull = [];
    if ($doughGrams !== null && $doughGrams > 0 && ($totalPercentage > 0 || bakery_baker_mix_formula_is_foldable($ingredients, $structure))) {
        $flour = $totalPercentage > 0 ? $doughGrams / ($totalPercentage / 100) : 0.0;
        $pull = bakery_baker_mix_formula_rows($ingredients, $flour, $doughGrams, $structure, []);
    }

    return [
        'kg' => $kg,
        'dough_grams' => $doughGrams,
        'loss_grams' => $loss,
        'missing_weights' => $missing,
        'effective_batch_grams' => $effectiveGrams,
        'base_grams' => $structure['standard_batch_dough_grams'],
        'batch_multiplier' => $reference['multiplier'],
        'multiplier_applied' => $reference['multiplier_applied'],
        'pieces_per_batch' => $pieceYield,
        'batches' => $batches,
        'piece_batches' => $pieceBatches,
        'batch_mode' => $mode,
        'ingredients' => $pull,
        'balances' => $balances,
    ];
}

function bakery_baker_set_audit_entity(string $date): int
{
    return (int) str_replace('-', '', $date);
}

function bakery_baker_set_audit(PDO $db, string $date, string $action, string $field, ?string $oldValue, ?string $newValue): void
{
    bakery_audit_write(
        $db,
        'baker_set',
        bakery_baker_set_audit_entity($date),
        $action,
        $field,
        $oldValue,
        $newValue
    );
}

function bakery_baker_set_revision(PDO $db, string $date): string
{
    $draft = bakery_production_plan_draft_quantities($db, $date);
    ksort($draft);
    $commit = bakery_production_plan_commit_get($db, $date);
    $payload = json_encode([
        'draft' => $draft,
        'committed_at' => $commit['committed_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES);
    return hash('sha256', (string) $payload);
}

function bakery_baker_set_is_locked(PDO $db, string $date): bool
{
    return bakery_production_plan_commit_get($db, $date) !== null;
}

/**
 * @param list<int>|null $limitProductIds
 * @return array{by_product: array<int, int>, real_order_lines: int, explicit_zeros: array<int, true>}
 */
function bakery_baker_set_standard_order(PDO $db, string $date, ?array $limitProductIds = null): array
{
    $demand = bakery_operating_demand_by_product($db, $date);
    $lines = bakery_operating_demand_lines($db, $date);
    $limit = null;
    if (is_array($limitProductIds)) {
        $limit = array_fill_keys(array_map('intval', $limitProductIds), true);
    }
    $real = 0;
    foreach ($lines as $line) {
        $productId = (int) ($line['product_id'] ?? 0);
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $source = (string) ($line['source'] ?? '');
        if (($source === 'daily' || $source === 'standing') && (int) ($line['quantity'] ?? 0) > 0) {
            $real++;
        }
    }
    $byProduct = [];
    foreach ($demand['by_product'] as $productId => $qty) {
        $productId = (int) $productId;
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $byProduct[$productId] = (int) $qty;
    }
    $explicitZeros = [];
    if (table_exists($db, 'daily_order_items') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare('
            SELECT doi.product_id
            FROM daily_order_items doi
            JOIN daily_orders o ON o.id = doi.daily_order_id
            WHERE o.order_date = ?
            GROUP BY doi.product_id
            HAVING SUM(doi.quantity) = 0
        ');
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $productId) {
            $productId = (int) $productId;
            if ($limit !== null && !isset($limit[$productId])) {
                continue;
            }
            if (!array_key_exists($productId, $byProduct)) {
                $byProduct[$productId] = 0;
                $explicitZeros[$productId] = true;
            }
        }
    }
    return [
        'by_product' => $byProduct,
        'real_order_lines' => $real,
        'explicit_zeros' => $explicitZeros,
    ];
}

function bakery_baker_set_active_sql(PDO $db, string $alias = 'p'): string
{
    if (function_exists('column_exists') && column_exists($db, 'products', 'is_active')) {
        return ' AND COALESCE(' . $alias . '.is_active, 1) = 1';
    }
    return '';
}

/**
 * @param list<int> $productIds
 * @return array<int, array<string, mixed>>
 */
function bakery_baker_set_load_products(PDO $db, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    if ($productIds === [] || !table_exists($db, 'products')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $db->prepare("
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            p.weight_grams,
            p.dough_type_id,
            dt.name AS dough_type_name,
            pl.name AS product_line_name
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        WHERE p.id IN ($placeholders)
    ");
    $stmt->execute($productIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['product_id']] = $row;
    }
    return $out;
}

/**
 * @param list<int> $doughIds
 * @param array<int, true> $skip
 * @return list<array<string, mixed>>
 */
function bakery_baker_set_sibling_shapes(PDO $db, array $doughIds, array $skip): array
{
    $doughIds = array_values(array_unique(array_filter(array_map('intval', $doughIds))));
    if ($doughIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($doughIds), '?'));
    $active = bakery_baker_set_active_sql($db, 'p');
    $stmt = $db->prepare("
        SELECT
            p.id AS product_id,
            p.name AS product_name,
            p.weight_grams,
            p.dough_type_id,
            dt.name AS dough_type_name,
            pl.name AS product_line_name
        FROM products p
        JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        WHERE p.dough_type_id IN ($placeholders) {$active}
        ORDER BY dt.name, p.name
    ");
    $stmt->execute($doughIds);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int) $row['product_id'];
        if (isset($skip[$productId])) {
            continue;
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param list<int>|null $limitProductIds
 * @return array<string, mixed>
 */
function bakery_baker_set_sheet(PDO $db, string $date, ?array $limitProductIds = null): array
{
    $standard = bakery_baker_set_standard_order($db, $date, $limitProductIds);
    $draft = bakery_production_plan_draft_quantities($db, $date);
    $limit = null;
    if (is_array($limitProductIds)) {
        $limit = array_fill_keys(array_map('intval', $limitProductIds), true);
        $draft = array_filter(
            $draft,
            static fn($qty, $productId): bool => isset($limit[(int) $productId]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    $quantities = $standard['by_product'];
    $hasPlan = [];
    foreach ($draft as $productId => $qty) {
        $productId = (int) $productId;
        $quantities[$productId] = (int) $qty;
        $hasPlan[$productId] = true;
    }
    $loaded = bakery_baker_set_load_products($db, array_keys($quantities));
    $doughIds = [];
    foreach ($loaded as $row) {
        $doughId = (int) ($row['dough_type_id'] ?? 0);
        if ($doughId > 0) {
            $doughIds[] = $doughId;
        }
    }
    $skip = array_fill_keys(array_map('intval', array_keys($quantities)), true);
    foreach (bakery_baker_set_sibling_shapes($db, $doughIds, $skip) as $row) {
        $productId = (int) $row['product_id'];
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $loaded[$productId] = $row;
        if (!array_key_exists($productId, $quantities)) {
            $quantities[$productId] = 0;
        }
    }

    $rows = [];
    foreach ($loaded as $productId => $row) {
        $productId = (int) $productId;
        $qty = (int) ($quantities[$productId] ?? 0);
        $weight = $row['weight_grams'];
        $rows[] = [
            'product_id' => $productId,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'weight_grams' => ($weight === null || $weight === '') ? null : (int) $weight,
            'dough_type_id' => (int) ($row['dough_type_id'] ?? 0),
            'dough_type_name' => (string) ($row['dough_type_name'] ?? ''),
            'product_line_name' => (string) ($row['product_line_name'] ?? ''),
            'planned_quantity' => $qty,
            'demand_quantity' => (int) ($standard['by_product'][$productId] ?? 0),
            'has_plan' => !empty($hasPlan[$productId]),
        ];
    }
    usort($rows, static function (array $a, array $b): int {
        $dough = strcasecmp((string) $a['dough_type_name'], (string) $b['dough_type_name']);
        if ($dough !== 0) {
            return $dough;
        }
        return strcasecmp((string) $a['product_name'], (string) $b['product_name']);
    });

    $built = bakery_baker_mix_batches_from_rows($db, $rows);
    $meta = [];
    foreach ($rows as $row) {
        $meta[(int) $row['product_id']] = $row;
    }
    foreach ($built['batches'] as &$batch) {
        $shapes = [];
        foreach ($batch['products'] as &$product) {
            $productId = (int) $product['product_id'];
            $info = $meta[$productId] ?? [];
            $demandQty = (int) ($info['demand_quantity'] ?? 0);
            $planned = (int) ($product['planned_quantity'] ?? 0);
            $product['demand_quantity'] = $demandQty;
            $product['has_plan'] = !empty($info['has_plan']);
            $product['balance'] = bakery_baker_set_balance($planned, $demandQty);
            $shapes[] = [
                'product_id' => $productId,
                'name' => (string) ($product['product_name'] ?? ''),
                'quantity' => $planned,
                'weight_grams' => $product['weight_grams'] ?? null,
                'demand_quantity' => $demandQty,
            ];
        }
        unset($product);
        $batch['shape'] = bakery_baker_set_shape_math(
            $shapes,
            is_array($batch['formula_structure'] ?? null) ? $batch['formula_structure'] : [],
            (float) ($batch['formula']['total_percentage'] ?? 0),
            is_array($batch['ingredients'] ?? null) ? $batch['ingredients'] : []
        );
        $addIns = is_array($batch['add_in_rows'] ?? null) ? $batch['add_in_rows'] : [];
        if ($addIns !== [] && ($batch['shape']['dough_grams'] ?? null) !== null) {
            $doughGrams = (float) $batch['shape']['dough_grams'];
            $totalPct = (float) ($batch['formula']['total_percentage'] ?? 0);
            $flour = $totalPct > 0 ? $doughGrams / ($totalPct / 100) : 0.0;
            $batch['shape']['ingredients'] = bakery_baker_mix_formula_rows(
                is_array($batch['ingredients'] ?? null) ? $batch['ingredients'] : [],
                $flour,
                $doughGrams,
                is_array($batch['formula_structure'] ?? null) ? $batch['formula_structure'] : [],
                $addIns
            );
        }
    }
    unset($batch);

    return [
        'date' => $date,
        'locked' => bakery_baker_set_is_locked($db, $date),
        'revision' => bakery_baker_set_revision($db, $date),
        'real_order_lines' => (int) $standard['real_order_lines'],
        'batches' => $built['batches'],
        'can_undo' => bakery_baker_set_last_change($db, $date) !== null,
        'commit' => bakery_production_plan_commit_get($db, $date),
    ];
}

function bakery_baker_set_dough_card(array $batch): array
{
    $shape = is_array($batch['shape'] ?? null) ? $batch['shape'] : [];
    $notes = [];
    $effective = $shape['effective_batch_grams'] ?? null;
    if (!empty($shape['multiplier_applied']) && !empty($shape['base_grams']) && $effective) {
        $notes[] = bakery_t('baker_set.batch_scaled', [
            'multiplier' => bakery_baker_set_format_count((float) $shape['batch_multiplier']),
            'base' => number_format((float) $shape['base_grams'], 0),
            'effective' => number_format((float) $effective, 0),
        ]);
    } elseif ($effective && ($shape['batch_mode'] ?? '') === 'grams') {
        $notes[] = bakery_t('baker_set.batch_plain', [
            'grams' => number_format((float) $effective, 0),
        ]);
    }
    if (!empty($shape['pieces_per_batch'])) {
        $notes[] = bakery_t('baker_set.per_batch', [
            'count' => bakery_baker_set_format_count((float) $shape['pieces_per_batch']),
        ]);
    }
    $ingredients = [];
    foreach ($shape['ingredients'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $label = (string) ($row['name'] ?? '');
        if ($row['folded_percent'] !== null) {
            $label .= ' · ' . number_format((float) $row['folded_percent'], 1) . '%';
        }
        $ingredients[] = [
            'name' => $label,
            'grams' => bakery_baker_set_format_amount((float) ($row['grams'] ?? 0)) . ' g',
        ];
    }
    $kg = $shape['kg'] ?? null;
    $batches = $shape['batches'] ?? null;
    return [
        'dough_type_id' => (int) ($batch['dough_type_id'] ?? 0),
        'name' => (string) ($batch['dough_type_name'] ?? ''),
        'kg' => $kg === null ? '' : bakery_t('baker_set.kg', ['kg' => bakery_baker_set_format_count((float) $kg)]),
        'batches' => $batches === null ? '' : bakery_t('baker_set.batches', ['count' => bakery_baker_set_format_count((float) $batches)]),
        'notes' => $notes,
        'missing' => ($shape['missing_weights'] ?? []) !== [],
        'ingredients' => $ingredients,
    ];
}

function bakery_baker_set_balance_text(string $balance, int $planned, int $demand): string
{
    if ($balance === 'short') {
        return bakery_t('baker_set.short', ['count' => (string) ($demand - $planned)]);
    }
    if ($balance === 'over') {
        return bakery_t('baker_set.over', ['count' => (string) ($planned - $demand)]);
    }
    return '';
}

/**
 * @return array<string, mixed>
 */
function bakery_baker_set_product_patch(array $sheet, int $productId): array
{
    foreach ($sheet['batches'] as $batch) {
        if (!is_array($batch)) {
            continue;
        }
        foreach ($batch['products'] as $product) {
            if ((int) ($product['product_id'] ?? 0) !== $productId) {
                continue;
            }
            $planned = (int) ($product['planned_quantity'] ?? 0);
            $demand = (int) ($product['demand_quantity'] ?? 0);
            $hasPlan = !empty($product['has_plan']);
            $balance = (string) ($product['balance'] ?? 'match');
            return [
                'product_id' => $productId,
                'quantity' => $planned,
                'demand' => $demand,
                'has_plan' => $hasPlan,
                'expected_has_plan' => $hasPlan ? 1 : 0,
                'expected_quantity' => $hasPlan ? $planned : 0,
                'balance' => $balance,
                'balance_text' => bakery_baker_set_balance_text($balance, $planned, $demand),
                'dough' => bakery_baker_set_dough_card($batch),
            ];
        }
    }
    return ['product_id' => $productId];
}

function bakery_baker_set_user_id(): ?int
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $id = (int) ($user['id'] ?? 0);
    return $id > 0 ? $id : null;
}

/**
 * @return list<int>|null
 */
function bakery_baker_set_limit(PDO $db): ?array
{
    if (!function_exists('bakery_baker_product_ids')) {
        return null;
    }
    return bakery_baker_product_ids($db);
}

function bakery_baker_set_assert_editable(PDO $db, string $date): void
{
    if (!bakery_baker_set_can_edit()) {
        throw new RuntimeException('baker_set_forbidden');
    }
    if (bakery_baker_set_is_locked($db, $date)) {
        throw new RuntimeException('baker_set_locked');
    }
}

function bakery_baker_set_assert_date(string $date): string
{
    $date = bakery_staff_selected_date($date, '');
    if ($date === '') {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    return $date;
}

function bakery_baker_set_whole($value): int
{
    if (is_string($value)) {
        $value = trim($value);
    }
    if ($value === null || $value === '' || !preg_match('/^\d+$/', (string) $value)) {
        throw new InvalidArgumentException('Batch targets must be whole numbers of zero or more.');
    }
    return (int) $value;
}

/**
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_assert_product(PDO $db, int $productId, ?array $limitProductIds): array
{
    if ($productId <= 0) {
        throw new InvalidArgumentException('Unknown product');
    }
    if (is_array($limitProductIds) && !in_array($productId, array_map('intval', $limitProductIds), true)) {
        throw new RuntimeException('baker_set_forbidden');
    }
    $active = bakery_baker_set_active_sql($db, 'p');
    $stmt = $db->prepare('SELECT p.id, p.name FROM products p WHERE p.id = ?' . $active . ' LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new InvalidArgumentException('Unknown product');
    }
    return $row;
}

/**
 * @return array<string, mixed>|null
 */
function bakery_baker_set_last_change(PDO $db, string $date): ?array
{
    if (!bakery_audit_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT id, action, field, old_value, new_value, actor_user_id, created_at
        FROM field_audit
        WHERE entity_type = ? AND entity_id = ? AND action IN (\'update\', \'reset\', \'copy\', \'undo\')
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmt->execute(['baker_set', bakery_baker_set_audit_entity($date)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<int, int> $quantities
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_replace_draft(PDO $db, string $date, array $quantities, ?array $limitProductIds, ?int $userId): void
{
    $current = bakery_production_plan_draft_quantities($db, $date);
    $limit = null;
    if (is_array($limitProductIds)) {
        $limit = array_fill_keys(array_map('intval', $limitProductIds), true);
    }
    $deleteIds = [];
    foreach ($current as $productId => $qty) {
        $productId = (int) $productId;
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $deleteIds[] = $productId;
    }
    $clean = [];
    foreach ($quantities as $productId => $qty) {
        $productId = (int) $productId;
        $qty = bakery_baker_set_whole($qty);
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $clean[$productId] = $qty;
    }
    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        if ($deleteIds !== []) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $stmt = $db->prepare("DELETE FROM production_plan_items WHERE delivery_date = ? AND product_id IN ($placeholders)");
            $stmt->execute(array_merge([$date], $deleteIds));
        }
        if ($clean !== []) {
            $allowed = [];
            foreach ($clean as $productId => $qty) {
                $allowed[$productId] = true;
            }
            bakery_production_plan_save_targets($db, [$date => $clean], $allowed, $userId);
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
}

function bakery_baker_set_scoped_draft(PDO $db, string $date, ?array $limitProductIds): array
{
    $draft = bakery_production_plan_draft_quantities($db, $date);
    if (!is_array($limitProductIds)) {
        return $draft;
    }
    $limit = array_fill_keys(array_map('intval', $limitProductIds), true);
    $out = [];
    foreach ($draft as $productId => $qty) {
        if (isset($limit[(int) $productId])) {
            $out[(int) $productId] = (int) $qty;
        }
    }
    return $out;
}

/**
 * @param list<int>|null $limitProductIds
 * @return array<string, mixed>
 */
function bakery_baker_set_save_quantity(
    PDO $db,
    string $date,
    int $productId,
    int $quantity,
    bool $expectedHasPlan,
    int $expectedQuantity,
    ?int $userId,
    ?array $limitProductIds
): array {
    bakery_baker_set_assert_editable($db, $date);
    bakery_baker_set_assert_product($db, $productId, $limitProductIds);
    $draft = bakery_production_plan_draft_quantities($db, $date);
    $had = array_key_exists($productId, $draft);
    $standard = bakery_baker_set_standard_order($db, $date, $limitProductIds);
    $oldDisplay = $had ? (int) $draft[$productId] : (int) ($standard['by_product'][$productId] ?? 0);
    $allowed = [$productId => true];
    bakery_production_plan_save_target_cas(
        $db,
        $date,
        $productId,
        $quantity,
        $allowed,
        $userId,
        $expectedHasPlan,
        $expectedQuantity
    );
    bakery_baker_set_audit($db, $date, 'update', 'qty:' . $productId, (string) $oldDisplay, (string) $quantity);
    $sheet = bakery_baker_set_sheet($db, $date, $limitProductIds);
    return [
        'ok' => true,
        'revision' => $sheet['revision'],
        'can_undo' => true,
        'patch' => bakery_baker_set_product_patch($sheet, $productId),
    ];
}

/**
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_undo(PDO $db, string $date, ?int $userId, ?array $limitProductIds): array
{
    bakery_baker_set_assert_editable($db, $date);
    $change = bakery_baker_set_last_change($db, $date);
    if ($change === null) {
        throw new RuntimeException('baker_set_nothing_to_undo');
    }
    $action = (string) $change['action'];
    $field = (string) $change['field'];
    if (($action === 'update' || $action === 'undo') && str_starts_with($field, 'qty:')) {
        $productId = (int) substr($field, 4);
        bakery_baker_set_assert_product($db, $productId, $limitProductIds);
        $restore = bakery_baker_set_whole($change['old_value'] ?? '0');
        $current = bakery_baker_set_whole($change['new_value'] ?? '0');
        bakery_production_plan_save_targets(
            $db,
            [$date => [$productId => $restore]],
            [$productId => true],
            $userId
        );
        bakery_baker_set_audit($db, $date, 'undo', 'qty:' . $productId, (string) $current, (string) $restore);
        $sheet = bakery_baker_set_sheet($db, $date, $limitProductIds);
        return [
            'ok' => true,
            'revision' => $sheet['revision'],
            'can_undo' => true,
            'patch' => bakery_baker_set_product_patch($sheet, $productId),
        ];
    }
    if ($action === 'reset' || $action === 'copy') {
        $map = json_decode((string) ($change['old_value'] ?? ''), true);
        if (!is_array($map)) {
            throw new RuntimeException('baker_set_nothing_to_undo');
        }
        $current = bakery_baker_set_scoped_draft($db, $date, $limitProductIds);
        bakery_baker_set_replace_draft($db, $date, $map, $limitProductIds, $userId);
        bakery_baker_set_audit(
            $db,
            $date,
            'undo',
            'draft',
            json_encode($current, JSON_UNESCAPED_SLASHES),
            json_encode($map, JSON_UNESCAPED_SLASHES)
        );
        return ['ok' => true, 'reload' => true, 'revision' => bakery_baker_set_revision($db, $date)];
    }
    throw new RuntimeException('baker_set_nothing_to_undo');
}

/**
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_reset(PDO $db, string $date, ?int $userId, ?array $limitProductIds): array
{
    bakery_baker_set_assert_editable($db, $date);
    $current = bakery_baker_set_scoped_draft($db, $date, $limitProductIds);
    bakery_baker_set_replace_draft($db, $date, [], $limitProductIds, $userId);
    bakery_baker_set_audit(
        $db,
        $date,
        'reset',
        'draft',
        json_encode($current, JSON_UNESCAPED_SLASHES),
        'standard'
    );
    return ['ok' => true, 'reload' => true, 'revision' => bakery_baker_set_revision($db, $date)];
}

/**
 * @return array{date: string, quantities: array<int, int>}|null
 */
function bakery_baker_set_previous_weekday(PDO $db, string $date): ?array
{
    $cursor = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$cursor || $cursor->format('Y-m-d') !== $date) {
        return null;
    }
    for ($week = 1; $week <= 8; $week++) {
        $cursor = DateTime::createFromFormat('!Y-m-d', $date);
        $cursor->modify('-' . (7 * $week) . ' days');
        $prev = $cursor->format('Y-m-d');
        $committed = bakery_production_plan_committed_quantities($db, $prev);
        if (is_array($committed) && $committed !== []) {
            return ['date' => $prev, 'quantities' => $committed];
        }
        $draft = bakery_production_plan_draft_quantities($db, $prev);
        if ($draft !== []) {
            return ['date' => $prev, 'quantities' => $draft];
        }
    }
    return null;
}

/**
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_copy_previous(PDO $db, string $date, ?int $userId, ?array $limitProductIds): array
{
    bakery_baker_set_assert_editable($db, $date);
    $previous = bakery_baker_set_previous_weekday($db, $date);
    if ($previous === null) {
        throw new RuntimeException('baker_set_no_previous');
    }
    $current = bakery_baker_set_scoped_draft($db, $date, $limitProductIds);
    bakery_baker_set_replace_draft($db, $date, $previous['quantities'], $limitProductIds, $userId);
    bakery_baker_set_audit(
        $db,
        $date,
        'copy',
        'draft',
        json_encode($current, JSON_UNESCAPED_SLASHES),
        json_encode(['from' => $previous['date'], 'quantities' => $previous['quantities']], JSON_UNESCAPED_SLASHES)
    );
    return ['ok' => true, 'reload' => true, 'revision' => bakery_baker_set_revision($db, $date), 'copied_from' => $previous['date']];
}

function bakery_baker_set_lock(PDO $db, string $date, ?int $userId): array
{
    if (!bakery_baker_set_can_edit()) {
        throw new RuntimeException('baker_set_forbidden');
    }
    if (bakery_baker_set_is_locked($db, $date)) {
        throw new RuntimeException('baker_set_locked');
    }
    $standard = bakery_baker_set_standard_order($db, $date, null);
    $draft = bakery_production_plan_draft_quantities($db, $date);
    $fill = [];
    foreach ($standard['by_product'] as $productId => $qty) {
        if (!array_key_exists($productId, $draft)) {
            $fill[$productId] = (int) $qty;
        }
    }
    if ($fill !== []) {
        $allowed = [];
        foreach ($fill as $productId => $qty) {
            $allowed[$productId] = true;
        }
        bakery_production_plan_save_targets($db, [$date => $fill], $allowed, $userId);
    }
    $result = bakery_production_plan_commit($db, $date, $userId);
    bakery_baker_set_audit($db, $date, 'lock', 'plan', null, (string) ($result['units_count'] ?? 0));
    return ['ok' => true, 'reload' => true, 'revision' => bakery_baker_set_revision($db, $date), 'commit' => $result];
}

function bakery_baker_set_unlock(PDO $db, string $date): array
{
    if (!bakery_baker_set_can_unlock()) {
        throw new RuntimeException('baker_set_forbidden');
    }
    bakery_production_plan_unlock($db, $date);
    bakery_baker_set_audit($db, $date, 'unlock', 'plan', 'locked', 'open');
    return ['ok' => true, 'reload' => true, 'revision' => bakery_baker_set_revision($db, $date)];
}

/**
 * @param list<int>|null $limitProductIds
 * @return list<array<string, mixed>>
 */
function bakery_baker_set_search(PDO $db, string $query, ?array $limitProductIds): array
{
    $query = trim($query);
    if ($query === '' || !table_exists($db, 'products')) {
        return [];
    }
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
    $active = bakery_baker_set_active_sql($db, 'p');
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.weight_grams, dt.name AS dough_name
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        WHERE p.name LIKE ? {$active}
        ORDER BY p.name
        LIMIT 24
    ");
    $stmt->execute([$like]);
    $limit = null;
    if (is_array($limitProductIds)) {
        $limit = array_fill_keys(array_map('intval', $limitProductIds), true);
    }
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productId = (int) $row['id'];
        if ($limit !== null && !isset($limit[$productId])) {
            continue;
        }
        $out[] = [
            'product_id' => $productId,
            'name' => (string) $row['name'],
            'dough_name' => (string) ($row['dough_name'] ?? ''),
            'weight_grams' => ($row['weight_grams'] === null || $row['weight_grams'] === '') ? null : (int) $row['weight_grams'],
        ];
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/**
 * @param list<int>|null $limitProductIds
 */
function bakery_baker_set_add(PDO $db, string $date, int $productId, ?int $userId, ?array $limitProductIds): array
{
    bakery_baker_set_assert_editable($db, $date);
    bakery_baker_set_assert_product($db, $productId, $limitProductIds);
    $draft = bakery_production_plan_draft_quantities($db, $date);
    $standard = bakery_baker_set_standard_order($db, $date, null);
    if (array_key_exists($productId, $draft) || (int) ($standard['by_product'][$productId] ?? 0) > 0) {
        return ['ok' => true, 'reload' => true, 'product_id' => $productId, 'already' => true];
    }
    return bakery_baker_set_save_quantity($db, $date, $productId, 1, false, 0, $userId, $limitProductIds) + [
        'reload' => true,
        'product_id' => $productId,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function bakery_baker_set_apply(PDO $db, string $action, array $input): array
{
    $date = bakery_baker_set_assert_date((string) ($input['date'] ?? ''));
    $limit = bakery_baker_set_limit($db);
    $userId = bakery_baker_set_user_id();
    $clientRevision = trim((string) ($input['revision'] ?? ''));
    $stale = $clientRevision !== '' && $clientRevision !== bakery_baker_set_revision($db, $date);

    if ($action === 'step-up' || $action === 'step-down' || $action === 'set') {
        $shown = bakery_baker_set_whole($input['quantity'] ?? null);
        $quantity = $action === 'set' ? $shown : max(0, $shown + ($action === 'step-up' ? 1 : -1));
        $expectedHas = in_array((string) ($input['expected_has_plan'] ?? ''), ['1', 'true'], true);
        $expectedQty = bakery_baker_set_whole($input['expected_quantity'] ?? '0');
        $result = bakery_baker_set_save_quantity(
            $db,
            $date,
            (int) ($input['product_id'] ?? 0),
            $quantity,
            $expectedHas,
            $expectedQty,
            $userId,
            $limit
        );
        $result['stale'] = $stale;
        return $result;
    }
    if ($action === 'undo') {
        $result = bakery_baker_set_undo($db, $date, $userId, $limit);
        $result['stale'] = $stale;
        return $result;
    }
    if ($action === 'reset') {
        return bakery_baker_set_reset($db, $date, $userId, $limit);
    }
    if ($action === 'copy') {
        return bakery_baker_set_copy_previous($db, $date, $userId, $limit);
    }
    if ($action === 'add') {
        $result = bakery_baker_set_add($db, $date, (int) ($input['product_id'] ?? 0), $userId, $limit);
        $result['stale'] = $stale;
        return $result;
    }
    if ($action === 'lock') {
        return bakery_baker_set_lock($db, $date, $userId);
    }
    if ($action === 'unlock') {
        return bakery_baker_set_unlock($db, $date);
    }
    throw new InvalidArgumentException('Unknown action');
}

function bakery_baker_set_error_key(Throwable $e): string
{
    $message = $e->getMessage();
    if ($message === 'baker_set_forbidden') {
        return 'baker_set.error_forbidden';
    }
    if ($message === 'baker_set_locked') {
        return 'baker_set.error_locked';
    }
    if ($message === 'baker_set_no_previous') {
        return 'baker_set.no_previous';
    }
    if ($message === 'baker_set_nothing_to_undo') {
        return 'baker_set.nothing_to_undo';
    }
    if (str_starts_with($message, 'production_plan_conflict:')) {
        return 'baker_set.error_conflict';
    }
    if (str_contains($message, 'before committing')) {
        return 'baker_set.empty_lock';
    }
    return 'baker_set.error_generic';
}

function bakery_baker_set_conflict_payload(Throwable $e, PDO $db, string $date): array
{
    $message = $e->getMessage();
    $tail = substr($message, strlen('production_plan_conflict:'));
    return [
        'ok' => false,
        'conflict' => true,
        'error' => bakery_t('baker_set.error_conflict'),
        'has_plan' => $tail !== 'none',
        'quantity' => $tail === 'none' ? 0 : (int) $tail,
        'revision' => bakery_baker_set_revision($db, $date),
        'stale' => true,
    ];
}
