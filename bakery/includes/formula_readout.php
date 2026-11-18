<?php
/**
 * Read-only dough readout. Hydration, preferment, target mix, implied pieces.
 * No database. Callers pass formula lines and the structure array they already loaded.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/formula_structure.php';

/**
 * Piece count from a query string. Blank, zero, and junk stay unset.
 * This does not read the request and does not store anything.
 *
 * @param mixed $raw
 */
function bakery_formula_readout_query_pieces($raw): ?float
{
    if (is_array($raw)) {
        return null;
    }
    $number = bakery_formula_normalize_optional_number($raw);
    if ($number === null || $number <= 0) {
        return null;
    }
    return $number;
}

/**
 * Pieces the baker entered for the batch, times the multiplier.
 * Grams-per-piece is not used, so a typed piece count can disagree with the gram batch.
 *
 * @param array<string, mixed> $structure
 */
function bakery_formula_readout_entered_pieces(array $structure): ?float
{
    $structure = bakery_formula_normalize_structure($structure);
    $factor = $structure['batch_multiplier'] === null ? 1.0 : (float) $structure['batch_multiplier'];
    $base = null;
    if ($structure['batch_size_mode'] === 'pieces' && $structure['standard_batch_pieces'] !== null) {
        $base = (float) $structure['standard_batch_pieces'];
    } elseif ($structure['pieces_per_batch'] !== null) {
        $base = (float) $structure['pieces_per_batch'];
    } elseif ($structure['standard_batch_pieces'] !== null) {
        $base = (float) $structure['standard_batch_pieces'];
    }
    if ($base === null || $base <= 0 || $factor <= 0) {
        return null;
    }
    return $base * $factor;
}

/**
 * Dough grams of the standard batch, times the multiplier.
 * Piece mode does not invent this from piece weight.
 *
 * @param array<string, mixed> $structure
 */
function bakery_formula_readout_dough_anchor(array $structure): ?float
{
    $structure = bakery_formula_normalize_structure($structure);
    if ($structure['standard_batch_dough_grams'] === null) {
        return null;
    }
    $factor = $structure['batch_multiplier'] === null ? 1.0 : (float) $structure['batch_multiplier'];
    $grams = (float) $structure['standard_batch_dough_grams'] * $factor;
    return $grams > 0 ? $grams : null;
}

/**
 * @param list<string> $roles
 */
function bakery_formula_readout_kind(string $name, string $unit = '', array $roles = []): string
{
    if (in_array('flour', $roles, true)) {
        return 'flour';
    }
    if (in_array('water', $roles, true)) {
        return 'water';
    }
    $classified = bakery_formula_classify_ingredient($name, $unit);
    $class = (string) ($classified['kind'] ?? '');
    if ($class === 'eggs') {
        return 'egg';
    }
    if ($class === 'milk') {
        return 'milk';
    }
    if ($class === 'water') {
        return 'water';
    }
    if (bakery_formula_ingredient_is_flour($name, [])) {
        return 'flour';
    }
    $normalized = bakery_formula_normalize_text($name);
    if (preg_match('/\b(salt|sal)\b/', $normalized) === 1) {
        return 'salt';
    }
    return 'other';
}

/**
 * Implied pieces = (total flour × total baker's percent − loss) / piece weight.
 * Total baker's percent is a percent (224 means 224%), on folded total flour.
 */
function bakery_formula_readout_implied_pieces(?float $totalFlourGrams, ?float $totalBakersPercent, ?float $lossGrams, ?float $pieceWeightGrams): ?float
{
    if ($totalFlourGrams === null || $totalBakersPercent === null || $pieceWeightGrams === null) {
        return null;
    }
    if ($totalFlourGrams <= 0 || $totalBakersPercent <= 0 || $pieceWeightGrams <= 0) {
        return null;
    }
    $loss = $lossGrams === null ? 0.0 : (float) $lossGrams;
    if ($loss < 0) {
        return null;
    }
    return (($totalFlourGrams * ($totalBakersPercent / 100.0)) - $loss) / $pieceWeightGrams;
}

/**
 * Amber when the gap is strictly greater than 2% of the entered batch.
 *
 * @return array{enough:bool, divergence:?float, flag:bool}
 */
function bakery_formula_readout_flag(?float $impliedPieces, ?float $enteredPieces): array
{
    if ($impliedPieces === null || $enteredPieces === null || $enteredPieces <= 0) {
        return [
            'enough' => false,
            'divergence' => null,
            'flag' => false,
        ];
    }
    $divergence = abs($impliedPieces - $enteredPieces) / $enteredPieces;
    return [
        'enough' => true,
        'divergence' => $divergence,
        'flag' => $divergence > (0.02 + 1e-9),
    ];
}

/**
 * @param array<string, mixed> $line
 */
function bakery_formula_readout_line_name(array $line): string
{
    return (string) ($line['ingredient_name'] ?? $line['name'] ?? '');
}

/**
 * @param list<array<string, mixed>> $lines
 * @param list<array<string, mixed>> $starters
 * @return array<string, mixed>|null
 */
function bakery_formula_readout_composition(array $lines, array $starters): ?array
{
    $totalPct = 0.0;
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $totalPct += (float) ($line['percentage'] ?? 0);
    }
    if ($totalPct <= 0) {
        return null;
    }

    $foldable = [];
    $incompleteIds = [];
    foreach ($starters as $starter) {
        if (!is_array($starter)) {
            continue;
        }
        $parts = bakery_formula_starter_parts($starter);
        $replaces = (int) ($starter['replaces_ingredient_id'] ?? 0);
        if ($parts !== null) {
            $foldable[$replaces] = $starter;
        } elseif ($replaces > 0) {
            $incompleteIds[$replaces] = true;
        }
    }

    $prefermentFlour = 0.0;
    $prefermentWater = 0.0;
    $prefermentGrams = 0.0;
    $incomplete = false;
    $bowl = [];
    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $id = (int) ($line['ingredient_id'] ?? 0);
        $grams = (float) ($line['percentage'] ?? 0);
        $name = bakery_formula_readout_line_name($line);
        $unit = (string) ($line['unit'] ?? '');
        $subformula = (isset($line['subformula']) && is_array($line['subformula'])) ? $line['subformula'] : null;
        if ($subformula !== null) {
            $expanded = bakery_formula_preferment_expansion($grams, $subformula, []);
            if ($expanded !== null) {
                $prefermentGrams += $grams;
                $prefermentFlour += (float) $expanded['flour_grams'];
                $prefermentWater += (float) $expanded['water_grams'];
                $bowl[] = [
                    'ingredient_id' => $id,
                    'name' => $name,
                    'unit' => $unit,
                    'grams' => $grams,
                    'kind' => 'starter',
                ];
                continue;
            }
            $incomplete = true;
        }
        if (isset($foldable[$id])) {
            $feeding = bakery_formula_starter_feeding_parts($grams, $foldable[$id]);
            if ($feeding !== null) {
                $prefermentGrams += $grams;
                $prefermentFlour += (float) $feeding['flour_grams'];
                $prefermentWater += (float) $feeding['water_grams'];
                $bowl[] = [
                    'ingredient_id' => $id,
                    'name' => $name,
                    'unit' => $unit,
                    'grams' => $grams,
                    'kind' => 'starter',
                ];
                continue;
            }
            $incomplete = true;
        }
        if (isset($incompleteIds[$id])) {
            $incomplete = true;
        }
        $bowl[] = [
            'ingredient_id' => $id,
            'name' => $name,
            'unit' => $unit,
            'grams' => $grams,
            'kind' => bakery_formula_readout_kind($name, $unit, []),
        ];
    }

    $fold = bakery_formula_fold_formula($lines, $starters, $totalPct);
    $water = 0.0;
    $milk = 0.0;
    $egg = 0.0;
    foreach ($fold['ingredients'] as $row) {
        if (empty($row['counts_in_totals'])) {
            continue;
        }
        $roles = [];
        if (!empty($row['is_flour'])) {
            $roles[] = 'flour';
        }
        if (!empty($row['is_water'])) {
            $roles[] = 'water';
        }
        $kind = bakery_formula_readout_kind((string) $row['ingredient_name'], (string) $row['unit'], $roles);
        $rowGrams = (float) $row['grams'];
        if ($kind === 'water') {
            $water += $rowGrams;
        } elseif ($kind === 'milk') {
            $milk += $rowGrams;
        } elseif ($kind === 'egg') {
            $egg += $rowGrams;
        }
    }

    $totalFlour = (float) $fold['total_flour_grams'];
    return [
        'total_pct' => $totalPct,
        'total_flour_grams' => $totalFlour,
        'total_bakers_percent' => $totalFlour > 0 ? ($totalPct / $totalFlour) * 100.0 : null,
        'water_grams' => $water,
        'milk_grams' => $milk,
        'egg_grams' => $egg,
        'preferment_flour_grams' => $prefermentFlour,
        'preferment_water_grams' => $prefermentWater,
        'preferment_grams' => $prefermentGrams,
        'incomplete' => $incomplete,
        'bowl' => $bowl,
    ];
}

/**
 * @param list<array<string, mixed>> $bowl
 * @return array{flour:float, water:float, starter:float, salt:float, lines:list<array<string, mixed>>}
 */
function bakery_formula_readout_scale_bowl(array $bowl, float $scale): array
{
    $flour = 0.0;
    $water = 0.0;
    $starter = 0.0;
    $salt = 0.0;
    $lines = [];
    foreach ($bowl as $row) {
        $grams = (float) $row['grams'] * $scale;
        $kind = (string) $row['kind'];
        if ($kind === 'flour') {
            $flour += $grams;
        } elseif ($kind === 'water') {
            $water += $grams;
        } elseif ($kind === 'starter') {
            $starter += $grams;
        } elseif ($kind === 'salt') {
            $salt += $grams;
        } elseif ($kind !== 'egg' && $kind !== 'milk' && $kind !== 'other') {
            $kind = 'other';
        }
        $lines[] = [
            'ingredient_id' => (int) $row['ingredient_id'],
            'name' => (string) $row['name'],
            'unit' => (string) $row['unit'],
            'grams' => $grams,
            'kind' => $kind,
        ];
    }
    return [
        'flour' => $flour,
        'water' => $water,
        'starter' => $starter,
        'salt' => $salt,
        'lines' => $lines,
    ];
}

/**
 * Read-only totals for one dough and one piece weight.
 *
 * @param list<array<string, mixed>> $lines
 * @param array<string, mixed> $structure
 * @param array{piece_weight_grams?:?float, pieces?:?float, flour_grams?:?float} $input
 * @return array<string, mixed>
 */
function bakery_formula_readout(array $lines, array $structure, array $input): array
{
    $structure = bakery_formula_normalize_structure($structure);
    $lossUnset = $structure['dough_loss_grams'] === null;
    $loss = $lossUnset ? 0.0 : (float) $structure['dough_loss_grams'];
    $entered = bakery_formula_readout_entered_pieces($structure);
    $anchor = bakery_formula_readout_dough_anchor($structure);
    $composition = bakery_formula_readout_composition($lines, $structure['starters']);
    $incomplete = $composition !== null && !empty($composition['incomplete']);
    $formulaEnough = $composition !== null && !$incomplete;

    $hydration = [
        'enough' => false,
        'percent' => null,
        'water_grams' => null,
        'milk_grams' => null,
        'egg_grams' => null,
        'total_flour_grams' => null,
    ];
    $pff = [
        'enough' => false,
        'percent' => null,
        'prefermented_flour_grams' => null,
        'total_flour_grams' => null,
    ];
    $share = [
        'enough' => false,
        'percent' => null,
        'preferment_grams' => null,
        'dough_grams' => null,
    ];
    if ($formulaEnough) {
        $flour = (float) $composition['total_flour_grams'];
        if ($flour > 0) {
            $hydration = [
                'enough' => true,
                'percent' => (((float) $composition['water_grams'] + (float) $composition['milk_grams'] + (0.75 * (float) $composition['egg_grams'])) / $flour) * 100.0,
                'water_grams' => (float) $composition['water_grams'],
                'milk_grams' => (float) $composition['milk_grams'],
                'egg_grams' => (float) $composition['egg_grams'],
                'total_flour_grams' => $flour,
            ];
            $pff = [
                'enough' => true,
                'percent' => ((float) $composition['preferment_flour_grams'] / $flour) * 100.0,
                'prefermented_flour_grams' => (float) $composition['preferment_flour_grams'],
                'total_flour_grams' => $flour,
            ];
        }
        $share = [
            'enough' => true,
            'percent' => ((float) $composition['preferment_grams'] / (float) $composition['total_pct']) * 100.0,
            'preferment_grams' => (float) $composition['preferment_grams'],
            'dough_grams' => (float) $composition['total_pct'],
        ];
    }

    $requested = bakery_formula_readout_query_pieces($input['pieces'] ?? null);
    $pieces = $requested !== null ? $requested : $entered;
    $weight = bakery_formula_normalize_optional_number($input['piece_weight_grams'] ?? null);
    if ($weight !== null && $weight <= 0) {
        $weight = null;
    }
    $doughEnough = $pieces !== null && $pieces > 0 && $weight !== null;
    $doughGrams = $doughEnough ? ($pieces * $weight) + $loss : null;
    $mixEnough = $doughEnough && $formulaEnough && $composition !== null;
    $scaled = [
        'flour' => null,
        'water' => null,
        'starter' => null,
        'salt' => null,
        'lines' => [],
    ];
    if ($mixEnough && $doughGrams !== null) {
        $scaled = bakery_formula_readout_scale_bowl($composition['bowl'], $doughGrams / (float) $composition['total_pct']);
    }

    $flourBasis = null;
    $totalBakers = $formulaEnough ? $composition['total_bakers_percent'] : null;
    if ($formulaEnough && $totalBakers !== null && (float) $composition['total_flour_grams'] > 0) {
        $explicitFlour = bakery_formula_normalize_optional_number($input['flour_grams'] ?? null);
        if ($explicitFlour !== null && $explicitFlour > 0) {
            $flourBasis = $explicitFlour;
        } elseif ($anchor !== null) {
            $flourBasis = $anchor * ((float) $composition['total_flour_grams'] / (float) $composition['total_pct']);
        }
    }
    $impliedPieces = ($weight !== null)
        ? bakery_formula_readout_implied_pieces($flourBasis, $totalBakers, $loss, $weight)
        : null;
    $flag = bakery_formula_readout_flag($impliedPieces, $entered);

    return [
        'formula_enough' => $formulaEnough,
        'starter_incomplete' => $incomplete,
        'loss_grams' => $loss,
        'loss_unset' => $lossUnset,
        'hydration' => $hydration,
        'pff' => $pff,
        'preferment_share' => $share,
        'mix' => [
            'enough' => $mixEnough,
            'dough_enough' => $doughEnough,
            'pieces' => $pieces,
            'piece_weight_grams' => $weight,
            'dough_grams' => $doughGrams,
            'direct_flour_grams' => $mixEnough ? $scaled['flour'] : null,
            'direct_water_grams' => $mixEnough ? $scaled['water'] : null,
            'starter_grams' => $mixEnough ? $scaled['starter'] : null,
            'salt_grams' => $mixEnough ? $scaled['salt'] : null,
            'lines' => $mixEnough ? $scaled['lines'] : [],
        ],
        'implied' => [
            'enough' => $impliedPieces !== null,
            'pieces' => $impliedPieces,
            'entered_pieces' => $entered,
            'divergence' => $flag['divergence'],
            'flag' => $flag['flag'],
            'total_flour_grams' => $flourBasis,
            'total_bakers_percent' => $impliedPieces !== null ? $totalBakers : null,
        ],
    ];
}
