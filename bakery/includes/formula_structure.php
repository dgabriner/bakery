<?php
/**
 * Formula structure: standard batch, starter sub-formulas, toppings, dough loss.
 *
 * Grams stay the source of truth. Blank fields leave the existing baker's-%
 * math alone. This file does not store recipe numbers.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/formula_units.php';

/** Suggested dough loss once a baker types it in. Blank stays unset. */
function bakery_formula_default_dough_loss_grams(): float
{
    return 50.0;
}

function bakery_formula_normalize_optional_number($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

function bakery_formula_positive_or_null($value): ?float
{
    $number = bakery_formula_normalize_optional_number($value);
    if ($number === null || $number <= 0) {
        return null;
    }
    return $number;
}

/**
 * @param array<string, mixed>|null $raw
 * @return array{
 *   batch_size_mode: ?string,
 *   standard_batch_dough_grams: ?float,
 *   standard_batch_pieces: ?float,
 *   batch_multiplier: ?float,
 *   dough_loss_grams: ?float,
 *   starters: list<array<string, mixed>>
 * }
 */
function bakery_formula_normalize_structure(?array $raw): array
{
    $raw = $raw ?? [];
    $mode = strtolower(trim((string) ($raw['batch_size_mode'] ?? '')));
    if ($mode !== 'grams' && $mode !== 'pieces') {
        $mode = null;
    }
    $multiplier = bakery_formula_normalize_optional_number($raw['batch_multiplier'] ?? null);
    if ($multiplier !== null && $multiplier <= 0) {
        $multiplier = null;
    }
    $loss = bakery_formula_normalize_optional_number($raw['dough_loss_grams'] ?? null);
    if ($loss !== null && $loss < 0) {
        $loss = null;
    }
    $starters = [];
    if (isset($raw['starters']) && is_array($raw['starters'])) {
        foreach ($raw['starters'] as $starter) {
            if (is_array($starter)) {
                $starters[] = $starter;
            }
        }
    }
    return [
        'batch_size_mode' => $mode,
        'standard_batch_dough_grams' => bakery_formula_positive_or_null($raw['standard_batch_dough_grams'] ?? null),
        'standard_batch_pieces' => bakery_formula_positive_or_null($raw['standard_batch_pieces'] ?? null),
        'batch_multiplier' => $multiplier,
        'dough_loss_grams' => $loss,
        'starters' => $starters,
    ];
}

/**
 * Effective mix size. A blank multiplier is 1 and is not treated as a saved scale.
 *
 * @param array<string, mixed> $structure
 * @return array{
 *   configured: bool,
 *   mode: ?string,
 *   multiplier: float,
 *   multiplier_applied: bool,
 *   grams: ?float,
 *   pieces: ?float,
 *   base_grams: ?float,
 *   base_pieces: ?float
 * }
 */
function bakery_formula_batch_reference(array $structure, ?float $pieceWeightGrams): array
{
    $structure = bakery_formula_normalize_structure($structure);
    $factor = $structure['batch_multiplier'] === null ? 1.0 : (float) $structure['batch_multiplier'];
    $applied = $structure['batch_multiplier'] !== null;
    $weight = ($pieceWeightGrams !== null && $pieceWeightGrams > 0) ? (float) $pieceWeightGrams : null;
    $base = [
        'configured' => false,
        'mode' => null,
        'multiplier' => $factor,
        'multiplier_applied' => $applied,
        'grams' => null,
        'pieces' => null,
        'base_grams' => $structure['standard_batch_dough_grams'],
        'base_pieces' => $structure['standard_batch_pieces'],
    ];

    if ($structure['batch_size_mode'] === 'pieces' && $structure['standard_batch_pieces'] !== null) {
        $pieces = (float) $structure['standard_batch_pieces'] * $factor;
        $base['configured'] = $pieces > 0;
        $base['mode'] = 'pieces';
        $base['pieces'] = $pieces;
        $base['grams'] = $weight !== null ? $pieces * $weight : null;
        return $base;
    }

    if ($structure['standard_batch_dough_grams'] !== null
        && ($structure['batch_size_mode'] === null || $structure['batch_size_mode'] === 'grams')
    ) {
        $grams = (float) $structure['standard_batch_dough_grams'] * $factor;
        $base['configured'] = $grams > 0;
        $base['mode'] = 'grams';
        $base['grams'] = $grams;
        $base['pieces'] = ($weight !== null && $grams > 0) ? $grams / $weight : null;
        return $base;
    }

    return $base;
}

/**
 * Grams of dough loss carried by one piece. Null when loss or the batch is blank.
 *
 * @param array<string, mixed> $structure
 */
function bakery_formula_loss_share_grams(array $structure, ?float $pieceWeightGrams): ?float
{
    $structure = bakery_formula_normalize_structure($structure);
    if ($structure['dough_loss_grams'] === null) {
        return null;
    }
    $batch = bakery_formula_batch_reference($structure, $pieceWeightGrams);
    if (!$batch['configured'] || $batch['pieces'] === null || $batch['pieces'] <= 0) {
        return null;
    }
    return (float) $structure['dough_loss_grams'] / (float) $batch['pieces'];
}

/**
 * Total dough loss for a mix sheet, using the same per-piece share as costing.
 *
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $products
 */
function bakery_formula_mix_loss_grams(array $structure, array $products): float
{
    $total = 0.0;
    $any = false;
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $weight = isset($product['weight_grams']) && $product['weight_grams'] !== null && $product['weight_grams'] !== ''
            ? (float) $product['weight_grams']
            : null;
        $share = bakery_formula_loss_share_grams($structure, $weight);
        if ($share === null) {
            continue;
        }
        $any = true;
        $total += $share * (float) ($product['quantity'] ?? 0);
    }
    return $any ? $total : 0.0;
}

/**
 * @param array<string, mixed> $line
 * @return array{ingredient_id:int, ingredient_name:string, unit:string, line_role:string, percentage:?float, share:float}
 */
function bakery_formula_starter_part(array $line, float $share): array
{
    return [
        'ingredient_id' => (int) $line['ingredient_id'],
        'ingredient_name' => (string) ($line['ingredient_name'] ?? ''),
        'unit' => (string) ($line['unit'] ?? ''),
        'line_role' => (string) $line['line_role'],
        'percentage' => $line['percentage'],
        'share' => $share,
    ];
}

/**
 * Split one gram of starter into flour, water, and other lines.
 * Null when the sub-formula is too incomplete to fold without guessing.
 *
 * @param array<string, mixed> $starter
 * @return array{replaces_ingredient_id:int, parts:list<array<string, mixed>>}|null
 */
function bakery_formula_starter_parts(array $starter): ?array
{
    $replaces = (int) ($starter['replaces_ingredient_id'] ?? 0);
    if ($replaces <= 0) {
        return null;
    }

    $lines = [];
    foreach ($starter['lines'] ?? [] as $line) {
        if (!is_array($line)) {
            continue;
        }
        $id = (int) ($line['ingredient_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $role = strtolower(trim((string) ($line['line_role'] ?? 'other')));
        if (!in_array($role, ['flour', 'water', 'other'], true)) {
            $role = 'other';
        }
        $percentage = bakery_formula_normalize_optional_number($line['percentage'] ?? null);
        $lines[] = [
            'ingredient_id' => $id,
            'ingredient_name' => (string) ($line['ingredient_name'] ?? ''),
            'unit' => (string) ($line['unit'] ?? ''),
            'line_role' => $role,
            'percentage' => $percentage,
        ];
    }

    $flours = [];
    $waters = [];
    $others = [];
    foreach ($lines as $line) {
        if ($line['line_role'] === 'flour') {
            $flours[] = $line;
        } elseif ($line['line_role'] === 'water') {
            $waters[] = $line;
        } else {
            $others[] = $line;
        }
    }
    if ($flours === []) {
        return null;
    }

    $hydration = bakery_formula_normalize_optional_number($starter['hydration_percent'] ?? null);
    if ($hydration !== null && $hydration < 0) {
        $hydration = null;
    }

    if ($hydration !== null) {
        if ($waters === []) {
            return null;
        }
        $flourNulls = 0;
        $flourWeights = [];
        foreach ($flours as $flour) {
            if ($flour['percentage'] === null) {
                $flourNulls++;
            } else {
                $flourWeights[] = (float) $flour['percentage'];
            }
        }
        if ($flourNulls > 0 && ($flourWeights !== [] || count($flours) !== 1)) {
            return null;
        }
        $flourTotal = $flourNulls > 0 ? 100.0 : array_sum($flourWeights);
        if ($flourTotal <= 0) {
            return null;
        }
        if (count($waters) > 1) {
            foreach ($waters as $water) {
                if ($water['percentage'] === null) {
                    return null;
                }
            }
        }
        $otherTotal = 0.0;
        foreach ($others as $other) {
            if ($other['percentage'] === null) {
                return null;
            }
            $otherTotal += (float) $other['percentage'];
        }
        $denominator = $flourTotal + $hydration + $otherTotal;
        if ($denominator <= 0) {
            return null;
        }
        $parts = [];
        foreach ($flours as $flour) {
            $weight = $flour['percentage'] === null ? 100.0 : (float) $flour['percentage'];
            $parts[] = bakery_formula_starter_part($flour, $weight / $denominator);
        }
        if (count($waters) === 1) {
            $parts[] = bakery_formula_starter_part($waters[0], $hydration / $denominator);
        } else {
            $waterSum = 0.0;
            foreach ($waters as $water) {
                $waterSum += (float) $water['percentage'];
            }
            if ($waterSum <= 0) {
                return null;
            }
            foreach ($waters as $water) {
                $parts[] = bakery_formula_starter_part(
                    $water,
                    ($hydration / $denominator) * ((float) $water['percentage'] / $waterSum)
                );
            }
        }
        foreach ($others as $other) {
            $parts[] = bakery_formula_starter_part($other, (float) $other['percentage'] / $denominator);
        }
        return [
            'replaces_ingredient_id' => $replaces,
            'parts' => $parts,
        ];
    }

    $sum = 0.0;
    $hasFlour = false;
    foreach ($lines as $line) {
        if ($line['percentage'] === null) {
            return null;
        }
        $sum += (float) $line['percentage'];
        if ($line['line_role'] === 'flour' && (float) $line['percentage'] > 0) {
            $hasFlour = true;
        }
    }
    if (!$hasFlour || $sum <= 0) {
        return null;
    }
    $parts = [];
    foreach ($lines as $line) {
        $parts[] = bakery_formula_starter_part($line, (float) $line['percentage'] / $sum);
    }
    return [
        'replaces_ingredient_id' => $replaces,
        'parts' => $parts,
    ];
}

/**
 * Flour and water inside a starter amount. Null when the sub-formula cannot fold.
 *
 * @param array<string, mixed> $starter
 * @return array{flour_grams:float, water_grams:float, parts:list<array<string, mixed>>}|null
 */
function bakery_formula_starter_feeding_parts(float $starterGrams, array $starter): ?array
{
    $parsed = bakery_formula_starter_parts($starter);
    if ($parsed === null) {
        return null;
    }
    $flour = 0.0;
    $water = 0.0;
    $parts = [];
    foreach ($parsed['parts'] as $part) {
        $grams = $starterGrams * (float) $part['share'];
        $part['grams'] = $grams;
        $parts[] = $part;
        if ($part['line_role'] === 'flour') {
            $flour += $grams;
        } elseif ($part['line_role'] === 'water') {
            $water += $grams;
        }
    }
    return [
        'flour_grams' => $flour,
        'water_grams' => $water,
        'parts' => $parts,
    ];
}

/**
 * @param list<string> $roles
 */
function bakery_formula_ingredient_is_flour(string $name, array $roles): bool
{
    if (in_array('flour', $roles, true)) {
        return true;
    }
    $normalized = bakery_formula_normalize_text($name);
    return strpos($normalized, 'flour') !== false || strpos($normalized, 'harina') !== false;
}

/**
 * @param list<string> $roles
 */
function bakery_formula_ingredient_is_water(string $name, array $roles): bool
{
    if (in_array('water', $roles, true)) {
        return true;
    }
    $normalized = bakery_formula_normalize_text($name);
    return preg_match('/\b(water|agua)\b/', $normalized) === 1;
}

/**
 * Scale a dough formula. Foldable starters move their flour and water into
 * those ingredients and stay on a prep line so they are not counted twice.
 *
 * @param list<array<string, mixed>> $lines
 * @param list<array<string, mixed>> $starters
 * @return array<string, mixed>
 */
function bakery_formula_fold_formula(array $lines, array $starters, float $doughGrams): array
{
    $parsed = [];
    foreach ($starters as $starter) {
        if (!is_array($starter)) {
            continue;
        }
        $parts = bakery_formula_starter_parts($starter);
        if ($parts !== null) {
            $parsed[(int) $parts['replaces_ingredient_id']] = $parts;
        }
    }

    $totalPct = 0.0;
    foreach ($lines as $line) {
        $totalPct += (float) ($line['percentage'] ?? 0);
    }
    $flourBase = $totalPct > 0 ? $doughGrams / ($totalPct / 100.0) : 0.0;

    $buckets = [];
    $prep = [];
    $folded = false;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $id = (int) ($line['ingredient_id'] ?? 0);
        $pct = (float) ($line['percentage'] ?? 0);
        $grams = $flourBase * ($pct / 100.0);
        $name = (string) ($line['ingredient_name'] ?? $line['name'] ?? '');
        $unit = (string) ($line['unit'] ?? '');
        if (isset($parsed[$id])) {
            $folded = true;
            $prep[] = [
                'ingredient_id' => $id,
                'ingredient_name' => $name,
                'unit' => $unit,
                'grams' => $grams,
                'stored_percentage' => $pct,
                'folded_bakers_percent' => null,
                'counts_in_totals' => false,
                'source' => 'starter_prep',
                'label_key' => 'formula_structure.starter_folded_note',
            ];
            foreach ($parsed[$id]['parts'] as $part) {
                $partId = (int) $part['ingredient_id'];
                if (!isset($buckets[$partId])) {
                    $buckets[$partId] = [
                        'ingredient_id' => $partId,
                        'ingredient_name' => (string) $part['ingredient_name'],
                        'unit' => (string) $part['unit'],
                        'grams' => 0.0,
                        'stored_percentage' => null,
                        'roles' => [],
                    ];
                }
                $buckets[$partId]['grams'] += $grams * (float) $part['share'];
                $buckets[$partId]['roles'][(string) $part['line_role']] = true;
                if ($buckets[$partId]['ingredient_name'] === '' && $part['ingredient_name'] !== '') {
                    $buckets[$partId]['ingredient_name'] = (string) $part['ingredient_name'];
                }
                if ($buckets[$partId]['unit'] === '' && $part['unit'] !== '') {
                    $buckets[$partId]['unit'] = (string) $part['unit'];
                }
            }
            continue;
        }

        if (!isset($buckets[$id])) {
            $buckets[$id] = [
                'ingredient_id' => $id,
                'ingredient_name' => $name,
                'unit' => $unit,
                'grams' => 0.0,
                'stored_percentage' => $pct,
                'roles' => [],
            ];
        }
        $buckets[$id]['grams'] += $grams;
        if ($buckets[$id]['stored_percentage'] === null) {
            $buckets[$id]['stored_percentage'] = $pct;
        }
        if ($name !== '') {
            $buckets[$id]['ingredient_name'] = $name;
        }
        if ($unit !== '') {
            $buckets[$id]['unit'] = $unit;
        }
    }

    $totalFlour = 0.0;
    $totalWater = 0.0;
    foreach ($buckets as &$bucket) {
        $roles = array_keys($bucket['roles']);
        $bucket['is_flour'] = bakery_formula_ingredient_is_flour($bucket['ingredient_name'], $roles);
        $bucket['is_water'] = bakery_formula_ingredient_is_water($bucket['ingredient_name'], $roles);
        if ($bucket['is_flour']) {
            $totalFlour += (float) $bucket['grams'];
        }
        if ($bucket['is_water']) {
            $totalWater += (float) $bucket['grams'];
        }
    }
    unset($bucket);

    $ingredients = [];
    foreach ($buckets as $bucket) {
        $foldedPercent = $totalFlour > 0 ? ((float) $bucket['grams'] / $totalFlour) * 100.0 : null;
        $ingredients[] = [
            'ingredient_id' => $bucket['ingredient_id'],
            'ingredient_name' => $bucket['ingredient_name'],
            'unit' => $bucket['unit'],
            'grams' => (float) $bucket['grams'],
            'stored_percentage' => $bucket['stored_percentage'],
            'folded_bakers_percent' => $foldedPercent,
            'counts_in_totals' => true,
            'source' => 'dough',
            'is_flour' => $bucket['is_flour'],
            'is_water' => $bucket['is_water'],
        ];
    }

    return [
        'dough_grams' => $doughGrams,
        'folded' => $folded,
        'total_flour_grams' => $totalFlour,
        'total_water_grams' => $totalWater,
        'ingredients' => $ingredients,
        'prep' => $prep,
    ];
}

/**
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $parts
 */
function bakery_formula_structure_changes_requirements(array $structure, array $parts, ?float $pieceWeight): bool
{
    $structure = bakery_formula_normalize_structure($structure);
    foreach ($structure['starters'] as $starter) {
        if (bakery_formula_starter_parts($starter) !== null) {
            return true;
        }
    }
    $loss = bakery_formula_loss_share_grams($structure, $pieceWeight);
    if ($loss !== null && abs($loss) > 0) {
        return true;
    }
    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }
        $kind = strtolower(trim((string) ($part['kind'] ?? '')));
        if ($kind !== 'topping' && $kind !== 'filling') {
            continue;
        }
        foreach ($part['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $grams = bakery_formula_normalize_optional_number($line['grams_per_piece'] ?? null);
            if ($grams !== null && $grams > 0 && (int) ($line['ingredient_id'] ?? 0) > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Ingredient grams for one finished piece: dough, folded starter, add-ins, loss share.
 * Grams only. Does not price ingredients and does not call bakery_ingredient_current_cost_per_kg.
 *
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $parts
 * @return array<string, mixed>
 */
function bakery_formula_product_piece_grams(array $lines, array $structure, array $parts, ?float $pieceWeightGrams): array
{
    $structure = bakery_formula_normalize_structure($structure);
    $weight = ($pieceWeightGrams !== null && $pieceWeightGrams > 0) ? (float) $pieceWeightGrams : null;
    $lossShare = $weight !== null ? bakery_formula_loss_share_grams($structure, $weight) : null;
    $dough = $weight !== null ? $weight + ($lossShare ?? 0.0) : 0.0;
    $folded = bakery_formula_fold_formula($lines, $structure['starters'], $dough);
    $ingredients = $folded['ingredients'];

    foreach ($parts as $part) {
        if (!is_array($part)) {
            continue;
        }
        $kind = strtolower(trim((string) ($part['kind'] ?? '')));
        if ($kind !== 'topping' && $kind !== 'filling') {
            continue;
        }
        foreach ($part['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $id = (int) ($line['ingredient_id'] ?? 0);
            $grams = bakery_formula_normalize_optional_number($line['grams_per_piece'] ?? null);
            if ($id <= 0 || $grams === null) {
                continue;
            }
            $ingredients[] = [
                'ingredient_id' => $id,
                'ingredient_name' => (string) ($line['ingredient_name'] ?? ''),
                'unit' => (string) ($line['unit'] ?? ''),
                'grams' => $grams,
                'stored_percentage' => null,
                'folded_bakers_percent' => null,
                'counts_in_totals' => true,
                'source' => $kind,
                'is_flour' => false,
                'is_water' => false,
            ];
        }
    }

    return [
        'piece_weight_grams' => $weight,
        'dough_grams' => $dough,
        'loss_share_grams' => $lossShare,
        'loss_applied' => $lossShare !== null,
        'folded' => (bool) $folded['folded'],
        'total_flour_grams' => (float) $folded['total_flour_grams'],
        'total_water_grams' => (float) $folded['total_water_grams'],
        'ingredients' => $ingredients,
        'prep' => $folded['prep'],
    ];
}

/**
 * Full-quantity ingredient lines when structure changes the requirement.
 * Null means the caller should keep the classic baker's-% path.
 *
 * @param list<array<string, mixed>> $formulaLines
 * @param array<string, mixed> $structure
 * @param list<array<string, mixed>> $parts
 * @return array{dough_grams:float, flour_grams:float, lines:list<array<string, mixed>>, folded:bool}|null
 */
function bakery_formula_requirement_scale(array $formulaLines, array $structure, array $parts, float $pieceWeight, int $quantity): ?array
{
    if ($pieceWeight <= 0 || $quantity <= 0) {
        return null;
    }
    if (!bakery_formula_structure_changes_requirements($structure, $parts, $pieceWeight)) {
        return null;
    }
    $piece = bakery_formula_product_piece_grams($formulaLines, $structure, $parts, $pieceWeight);
    $lines = [];
    foreach ($piece['ingredients'] as $row) {
        if (empty($row['counts_in_totals'])) {
            continue;
        }
        $row['grams'] = (float) $row['grams'] * $quantity;
        $lines[] = $row;
    }
    return [
        'dough_grams' => (float) $piece['dough_grams'] * $quantity,
        'flour_grams' => (float) $piece['total_flour_grams'] * $quantity,
        'lines' => $lines,
        'folded' => (bool) $piece['folded'],
    ];
}

/**
 * Batch fields that should replace the legacy gram reference. Null keeps it.
 *
 * @param array<string, mixed>|null $formulaStructure
 * @return array<string, mixed>|null
 */
function bakery_formula_batch_override(?array $formulaStructure, ?float $standardBatchDoughGrams, int $weightGrams): ?array
{
    if ($formulaStructure === null) {
        return null;
    }
    $structure = bakery_formula_normalize_structure($formulaStructure);
    if ($structure['standard_batch_dough_grams'] === null && $standardBatchDoughGrams !== null && $standardBatchDoughGrams > 0) {
        $structure['standard_batch_dough_grams'] = (float) $standardBatchDoughGrams;
    }
    $reference = bakery_formula_batch_reference($structure, $weightGrams > 0 ? (float) $weightGrams : null);
    if (!$reference['configured']) {
        return null;
    }
    if ($structure['batch_multiplier'] === null && $structure['batch_size_mode'] !== 'pieces') {
        return null;
    }
    return [
        'structure' => $structure,
        'reference' => $reference,
    ];
}

/**
 * Add-in grams for the pieces on a mix card.
 *
 * @param list<array<string, mixed>> $products
 * @param array<int, list<array<string, mixed>>> $partsByProduct
 * @return list<array<string, mixed>>
 */
function bakery_formula_mix_add_in_rows(array $products, array $partsByProduct): array
{
    $rows = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $productId = (int) ($product['product_id'] ?? 0);
        $qty = (float) ($product['planned_quantity'] ?? $product['quantity'] ?? 0);
        if ($productId <= 0 || $qty <= 0) {
            continue;
        }
        foreach ($partsByProduct[$productId] ?? [] as $part) {
            if (!is_array($part)) {
                continue;
            }
            $kind = strtolower(trim((string) ($part['kind'] ?? '')));
            if ($kind !== 'topping' && $kind !== 'filling') {
                continue;
            }
            foreach ($part['lines'] ?? [] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $id = (int) ($line['ingredient_id'] ?? 0);
                $gramsEach = bakery_formula_normalize_optional_number($line['grams_per_piece'] ?? null);
                if ($id <= 0 || $gramsEach === null) {
                    continue;
                }
                $key = $kind . ':' . $id;
                if (!isset($rows[$key])) {
                    $rows[$key] = [
                        'ingredient_id' => $id,
                        'ingredient_name' => (string) ($line['ingredient_name'] ?? ''),
                        'unit' => (string) ($line['unit'] ?? ''),
                        'grams' => 0.0,
                        'source' => $kind,
                    ];
                }
                $rows[$key]['grams'] += $gramsEach * $qty;
            }
        }
    }
    return array_values($rows);
}

function bakery_formula_structure_ready(PDO $db): bool
{
    if (!function_exists('table_exists') || !function_exists('column_exists')) {
        return false;
    }
    return table_exists($db, 'formula_subformulas')
        && table_exists($db, 'formula_subformula_lines')
        && column_exists($db, 'dough_types', 'dough_loss_grams')
        && column_exists($db, 'dough_types', 'batch_multiplier')
        && column_exists($db, 'dough_types', 'standard_batch_pieces')
        && column_exists($db, 'dough_types', 'batch_size_mode');
}

/**
 * @param list<int> $doughIds
 * @return array<int, array<string, mixed>>
 */
function bakery_formula_structure_load_map(PDO $db, array $doughIds): array
{
    $doughIds = array_values(array_unique(array_filter(array_map('intval', $doughIds))));
    $out = [];
    foreach ($doughIds as $id) {
        $out[$id] = bakery_formula_normalize_structure([]);
    }
    if ($doughIds === [] || !bakery_formula_structure_ready($db)) {
        return $out;
    }

    $placeholders = implode(',', array_fill(0, count($doughIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, batch_size_mode, standard_batch_dough_grams, standard_batch_pieces, batch_multiplier, dough_loss_grams
         FROM dough_types WHERE id IN ($placeholders)"
    );
    $stmt->execute($doughIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $out[$id] = bakery_formula_normalize_structure($row);
    }

    $starterStmt = $db->prepare(
        "SELECT id, dough_type_id, name, hydration_percent, replaces_ingredient_id
         FROM formula_subformulas
         WHERE kind = 'starter' AND dough_type_id IN ($placeholders)
         ORDER BY sort_order, id"
    );
    $starterStmt->execute($doughIds);
    $starters = $starterStmt->fetchAll(PDO::FETCH_ASSOC);
    $starterIds = array_map(static fn($row) => (int) $row['id'], $starters);
    $linesByStarter = bakery_formula_subformula_lines_map($db, $starterIds);
    foreach ($starters as $starter) {
        $doughId = (int) $starter['dough_type_id'];
        if (!isset($out[$doughId])) {
            continue;
        }
        $starterId = (int) $starter['id'];
        $out[$doughId]['starters'][] = [
            'id' => $starterId,
            'name' => (string) $starter['name'],
            'hydration_percent' => $starter['hydration_percent'] !== null ? (float) $starter['hydration_percent'] : null,
            'replaces_ingredient_id' => $starter['replaces_ingredient_id'] !== null ? (int) $starter['replaces_ingredient_id'] : 0,
            'lines' => $linesByStarter[$starterId] ?? [],
        ];
    }
    return $out;
}

/**
 * @param list<int> $productIds
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_formula_parts_load_map(PDO $db, array $productIds): array
{
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    $out = [];
    foreach ($productIds as $id) {
        $out[$id] = [];
    }
    if ($productIds === [] || !bakery_formula_structure_ready($db)) {
        return $out;
    }
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $db->prepare(
        "SELECT id, product_id, kind, name
         FROM formula_subformulas
         WHERE product_id IN ($placeholders) AND kind IN ('topping', 'filling')
         ORDER BY sort_order, id"
    );
    $stmt->execute($productIds);
    $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $partIds = array_map(static fn($row) => (int) $row['id'], $parts);
    $linesByPart = bakery_formula_subformula_lines_map($db, $partIds);
    foreach ($parts as $part) {
        $productId = (int) $part['product_id'];
        $partId = (int) $part['id'];
        $out[$productId][] = [
            'id' => $partId,
            'kind' => (string) $part['kind'],
            'name' => (string) $part['name'],
            'lines' => $linesByPart[$partId] ?? [],
        ];
    }
    return $out;
}

/**
 * @param list<int> $subformulaIds
 * @return array<int, list<array<string, mixed>>>
 */
function bakery_formula_subformula_lines_map(PDO $db, array $subformulaIds): array
{
    $subformulaIds = array_values(array_unique(array_filter(array_map('intval', $subformulaIds))));
    if ($subformulaIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($subformulaIds), '?'));
    $stmt = $db->prepare(
        "SELECT l.subformula_id, l.ingredient_id, l.line_role, l.percentage, l.grams_per_piece,
                i.name AS ingredient_name, i.unit
         FROM formula_subformula_lines l
         JOIN ingredients i ON i.id = l.ingredient_id
         WHERE l.subformula_id IN ($placeholders)
         ORDER BY l.id"
    );
    $stmt->execute($subformulaIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['subformula_id'];
        $map[$id][] = [
            'ingredient_id' => (int) $row['ingredient_id'],
            'ingredient_name' => (string) $row['ingredient_name'],
            'unit' => (string) ($row['unit'] ?? ''),
            'line_role' => (string) $row['line_role'],
            'percentage' => $row['percentage'] !== null ? (float) $row['percentage'] : null,
            'grams_per_piece' => $row['grams_per_piece'] !== null ? (float) $row['grams_per_piece'] : null,
        ];
    }
    return $map;
}

/**
 * @param list<array<string, mixed>> $products
 * @return list<array<string, mixed>>
 */
function bakery_formula_structure_attach_products(PDO $db, array $products): array
{
    if (!bakery_formula_structure_ready($db)) {
        return $products;
    }
    $doughIds = [];
    $productIds = [];
    foreach ($products as $product) {
        if (!empty($product['dough_type_id'])) {
            $doughIds[] = (int) $product['dough_type_id'];
        }
        $productIds[] = (int) ($product['product_id'] ?? 0);
    }
    $structures = bakery_formula_structure_load_map($db, $doughIds);
    $parts = bakery_formula_parts_load_map($db, $productIds);
    foreach ($products as &$product) {
        $doughId = (int) ($product['dough_type_id'] ?? 0);
        $product['formula_structure'] = $structures[$doughId] ?? bakery_formula_normalize_structure([]);
        $product['formula_parts'] = $parts[(int) ($product['product_id'] ?? 0)] ?? [];
    }
    unset($product);
    return $products;
}

function bakery_formula_structure_save_batch(PDO $db, int $doughTypeId, array $post): void
{
    if (!bakery_formula_structure_ready($db)) {
        throw new RuntimeException(bakery_t('formula_structure.not_ready'));
    }
    bakery_formula_structure_assert_dough($db, $doughTypeId);
    $normalized = bakery_formula_normalize_structure([
        'batch_size_mode' => $post['batch_size_mode'] ?? '',
        'standard_batch_dough_grams' => $post['standard_batch_dough_grams'] ?? '',
        'standard_batch_pieces' => $post['standard_batch_pieces'] ?? '',
        'batch_multiplier' => $post['batch_multiplier'] ?? '',
        'dough_loss_grams' => $post['dough_loss_grams'] ?? '',
    ]);
    $postedMultiplier = bakery_formula_normalize_optional_number($post['batch_multiplier'] ?? null);
    if ($postedMultiplier !== null && $postedMultiplier <= 0) {
        throw new RuntimeException(bakery_t('formula_structure.multiplier_invalid'));
    }
    $postedLoss = bakery_formula_normalize_optional_number($post['dough_loss_grams'] ?? null);
    if ($postedLoss !== null && $postedLoss < 0) {
        throw new RuntimeException(bakery_t('formula_structure.loss_invalid'));
    }
    $stmt = $db->prepare(
        'UPDATE dough_types
         SET batch_size_mode = ?, standard_batch_dough_grams = ?, standard_batch_pieces = ?,
             batch_multiplier = ?, dough_loss_grams = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $normalized['batch_size_mode'],
        $normalized['standard_batch_dough_grams'],
        $normalized['standard_batch_pieces'],
        $normalized['batch_multiplier'],
        $normalized['dough_loss_grams'],
        $doughTypeId,
    ]);
}

/**
 * @return list<array{ingredient_id:int, line_role:string, percentage:?float, grams_per_piece:?float}>
 */
function bakery_formula_structure_posted_lines(array $post, string $kind): array
{
    $ids = $post['line_ingredient_id'] ?? [];
    if (!is_array($ids)) {
        return [];
    }
    $roles = is_array($post['line_role'] ?? null) ? $post['line_role'] : [];
    $percents = is_array($post['line_percentage'] ?? null) ? $post['line_percentage'] : [];
    $grams = is_array($post['line_grams_per_piece'] ?? null) ? $post['line_grams_per_piece'] : [];
    $lines = [];
    $seen = [];
    foreach ($ids as $index => $rawId) {
        $ingredientId = (int) $rawId;
        if ($ingredientId <= 0) {
            continue;
        }
        if (isset($seen[$ingredientId])) {
            throw new RuntimeException(bakery_t('formula_structure.duplicate_line'));
        }
        $seen[$ingredientId] = true;
        $role = strtolower(trim((string) ($roles[$index] ?? 'other')));
        if (!in_array($role, ['flour', 'water', 'other'], true)) {
            $role = 'other';
        }
        $percentage = bakery_formula_normalize_optional_number($percents[$index] ?? null);
        $gramsEach = bakery_formula_normalize_optional_number($grams[$index] ?? null);
        if ($kind === 'starter') {
            $lines[] = [
                'ingredient_id' => $ingredientId,
                'line_role' => $role,
                'percentage' => $percentage,
                'grams_per_piece' => null,
            ];
            continue;
        }
        $lines[] = [
            'ingredient_id' => $ingredientId,
            'line_role' => 'other',
            'percentage' => null,
            'grams_per_piece' => $gramsEach,
        ];
    }
    return $lines;
}

function bakery_formula_structure_save_starter(PDO $db, int $doughTypeId, array $post): int
{
    if (!bakery_formula_structure_ready($db)) {
        throw new RuntimeException(bakery_t('formula_structure.not_ready'));
    }
    bakery_formula_structure_assert_dough($db, $doughTypeId);
    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException(bakery_t('formula_structure.name_required'));
    }
    $replaces = (int) ($post['replaces_ingredient_id'] ?? 0);
    if ($replaces <= 0) {
        throw new RuntimeException(bakery_t('formula_structure.replaces_required'));
    }
    $onFormula = $db->prepare('SELECT 1 FROM formula_ingredients WHERE dough_type_id = ? AND ingredient_id = ?');
    $onFormula->execute([$doughTypeId, $replaces]);
    if (!$onFormula->fetchColumn()) {
        throw new RuntimeException(bakery_t('formula_structure.replaces_required'));
    }
    $hydration = bakery_formula_normalize_optional_number($post['hydration_percent'] ?? null);
    if ($hydration !== null && $hydration < 0) {
        throw new RuntimeException(bakery_t('formula_structure.hydration_invalid'));
    }
    $lines = bakery_formula_structure_posted_lines($post, 'starter');
    bakery_formula_structure_assert_ingredients($db, array_column($lines, 'ingredient_id'));

    $subformulaId = (int) ($post['subformula_id'] ?? 0);
    $db->beginTransaction();
    try {
        if ($subformulaId > 0) {
            $own = $db->prepare("SELECT id FROM formula_subformulas WHERE id = ? AND dough_type_id = ? AND kind = 'starter'");
            $own->execute([$subformulaId, $doughTypeId]);
            if (!$own->fetchColumn()) {
                throw new RuntimeException(bakery_t('formula_structure.save_failed'));
            }
            $update = $db->prepare(
                'UPDATE formula_subformulas
                 SET name = ?, hydration_percent = ?, replaces_ingredient_id = ?
                 WHERE id = ?'
            );
            $update->execute([$name, $hydration, $replaces, $subformulaId]);
            $db->prepare('DELETE FROM formula_subformula_lines WHERE subformula_id = ?')->execute([$subformulaId]);
        } else {
            $insert = $db->prepare(
                "INSERT INTO formula_subformulas (dough_type_id, product_id, kind, name, hydration_percent, replaces_ingredient_id)
                 VALUES (?, NULL, 'starter', ?, ?, ?)"
            );
            $insert->execute([$doughTypeId, $name, $hydration, $replaces]);
            $subformulaId = (int) $db->lastInsertId();
        }
        bakery_formula_structure_insert_lines($db, $subformulaId, $lines);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $subformulaId;
}

function bakery_formula_structure_save_part(PDO $db, int $doughTypeId, array $post): int
{
    if (!bakery_formula_structure_ready($db)) {
        throw new RuntimeException(bakery_t('formula_structure.not_ready'));
    }
    bakery_formula_structure_assert_dough($db, $doughTypeId);
    $name = trim((string) ($post['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException(bakery_t('formula_structure.name_required'));
    }
    $kind = strtolower(trim((string) ($post['kind'] ?? '')));
    if ($kind !== 'topping' && $kind !== 'filling') {
        throw new RuntimeException(bakery_t('formula_structure.kind_required'));
    }
    $productId = (int) ($post['product_id'] ?? 0);
    $product = $db->prepare('SELECT id FROM products WHERE id = ? AND dough_type_id = ?');
    $product->execute([$productId, $doughTypeId]);
    if (!$product->fetchColumn()) {
        throw new RuntimeException(bakery_t('formula_structure.product_required'));
    }
    $lines = bakery_formula_structure_posted_lines($post, $kind);
    if ($lines === []) {
        throw new RuntimeException(bakery_t('formula_structure.line_required'));
    }
    bakery_formula_structure_assert_ingredients($db, array_column($lines, 'ingredient_id'));

    $subformulaId = (int) ($post['subformula_id'] ?? 0);
    $db->beginTransaction();
    try {
        if ($subformulaId > 0) {
            $own = $db->prepare(
                "SELECT id FROM formula_subformulas
                 WHERE id = ? AND product_id = ? AND kind IN ('topping', 'filling')"
            );
            $own->execute([$subformulaId, $productId]);
            if (!$own->fetchColumn()) {
                throw new RuntimeException(bakery_t('formula_structure.save_failed'));
            }
            $update = $db->prepare('UPDATE formula_subformulas SET name = ?, kind = ?, dough_type_id = ? WHERE id = ?');
            $update->execute([$name, $kind, $doughTypeId, $subformulaId]);
            $db->prepare('DELETE FROM formula_subformula_lines WHERE subformula_id = ?')->execute([$subformulaId]);
        } else {
            $insert = $db->prepare(
                'INSERT INTO formula_subformulas (dough_type_id, product_id, kind, name, hydration_percent, replaces_ingredient_id)
                 VALUES (?, ?, ?, ?, NULL, NULL)'
            );
            $insert->execute([$doughTypeId, $productId, $kind, $name]);
            $subformulaId = (int) $db->lastInsertId();
        }
        bakery_formula_structure_insert_lines($db, $subformulaId, $lines);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return $subformulaId;
}

function bakery_formula_structure_delete(PDO $db, int $doughTypeId, int $subformulaId): void
{
    if (!bakery_formula_structure_ready($db)) {
        throw new RuntimeException(bakery_t('formula_structure.not_ready'));
    }
    $stmt = $db->prepare('SELECT id, dough_type_id, product_id FROM formula_subformulas WHERE id = ?');
    $stmt->execute([$subformulaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException(bakery_t('formula_structure.save_failed'));
    }
    $ownsDough = (int) ($row['dough_type_id'] ?? 0) === $doughTypeId;
    $ownsProduct = false;
    if (!$ownsDough && !empty($row['product_id'])) {
        $product = $db->prepare('SELECT 1 FROM products WHERE id = ? AND dough_type_id = ?');
        $product->execute([(int) $row['product_id'], $doughTypeId]);
        $ownsProduct = (bool) $product->fetchColumn();
    }
    if (!$ownsDough && !$ownsProduct) {
        throw new RuntimeException(bakery_t('formula_structure.save_failed'));
    }
    $db->prepare('DELETE FROM formula_subformulas WHERE id = ?')->execute([$subformulaId]);
}

/**
 * @param list<int> $ingredientIds
 */
function bakery_formula_structure_assert_ingredients(PDO $db, array $ingredientIds): void
{
    foreach ($ingredientIds as $ingredientId) {
        $ingredientId = (int) $ingredientId;
        if ($ingredientId <= 0) {
            continue;
        }
        $stmt = $db->prepare('SELECT 1 FROM ingredients WHERE id = ?');
        $stmt->execute([$ingredientId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(bakery_t('formula_structure.save_failed'));
        }
    }
}

/**
 * @param list<array{ingredient_id:int, line_role:string, percentage:?float, grams_per_piece:?float}> $lines
 */
function bakery_formula_structure_insert_lines(PDO $db, int $subformulaId, array $lines): void
{
    $insert = $db->prepare(
        'INSERT INTO formula_subformula_lines (subformula_id, ingredient_id, line_role, percentage, grams_per_piece)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $insert->execute([
            $subformulaId,
            $line['ingredient_id'],
            $line['line_role'],
            $line['percentage'],
            $line['grams_per_piece'],
        ]);
    }
}

function bakery_formula_structure_assert_dough(PDO $db, int $doughTypeId): void
{
    $stmt = $db->prepare('SELECT 1 FROM dough_types WHERE id = ?');
    $stmt->execute([$doughTypeId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException(bakery_t('formula_structure.save_failed'));
    }
}
