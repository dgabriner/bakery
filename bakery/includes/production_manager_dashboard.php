<?php
/**
 * Production Manager Dashboard — dough-first board for one delivery day.
 *
 * Groups bake quantities by dough type with pieces, batch size (gal/tray),
 * dough weight, and expandable SKU rows. Complements Production Center
 * (edit/commit) and Daily Production (baker sheet).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_plan.php';
require_once __DIR__ . '/product_pack_yields.php';
require_once __DIR__ . '/product_inventory.php';

/**
 * Resolve an operating date (default: tomorrow).
 */
function bakery_pmd_resolve_date(string $raw): string
{
    $raw = trim($raw);
    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    if ($dt && $dt->format('Y-m-d') === $raw) {
        return $raw;
    }
    return date('Y-m-d', strtotime('+1 day'));
}

/**
 * Format dough grams for kitchen display.
 *
 * @return array{grams:int,lb:float,label:string}
 */
function bakery_pmd_format_dough_weight(int $grams): array
{
    $grams = max(0, $grams);
    $lb = $grams > 0 ? round($grams / 453.592, 1) : 0.0;
    if ($grams <= 0) {
        return ['grams' => 0, 'lb' => 0.0, 'label' => ''];
    }
    return [
        'grams' => $grams,
        'lb' => $lb,
        'label' => number_format($lb, 1) . ' lb · ' . number_format($grams) . ' g',
    ];
}

/**
 * Format a float for batch labels (trim trailing zeros).
 */
function bakery_pmd_fmt_qty(float $n, int $decimals = 2): string
{
    $s = number_format($n, $decimals, '.', '');
    $s = rtrim(rtrim($s, '0'), '.');
    return $s === '' ? '0' : $s;
}

/**
 * Dough-level batch size from dough_type_pack_yields + piece total.
 *
 * @param array<string,mixed>|null $doughYield
 * @return array{
 *   unit:string,
 *   gallons:?float,
 *   trays:?int,
 *   tray_remainder:?int,
 *   pieces_per_tray:?int,
 *   trays_per_gallon:?float,
 *   pcs_per_gallon:?float,
 *   label:string
 * }
 */
function bakery_pmd_dough_batch(?array $doughYield, int $pieces): array
{
    $out = [
        'unit' => 'piece',
        'gallons' => null,
        'trays' => null,
        'tray_remainder' => null,
        'pieces_per_tray' => null,
        'trays_per_gallon' => null,
        'pcs_per_gallon' => null,
        'label' => $pieces > 0 ? (number_format($pieces) . ' pcs') : '',
    ];
    if ($pieces <= 0 || !$doughYield) {
        return $out;
    }

    $ppt = (int)($doughYield['pieces_per_tray'] ?? 0);
    $tpg = isset($doughYield['trays_per_gallon']) ? (float)$doughYield['trays_per_gallon'] : 0.0;
    if ($ppt > 1) {
        $out['unit'] = 'tray';
        $out['pieces_per_tray'] = $ppt;
        $out['trays'] = intdiv($pieces, $ppt);
        $out['tray_remainder'] = $pieces % $ppt;
        $trayBit = $out['trays'] . ' tray' . ($out['trays'] === 1 ? '' : 's');
        if ($out['tray_remainder'] > 0) {
            $trayBit .= ' + ' . $out['tray_remainder'] . ' pcs';
        }
        $out['label'] = $trayBit . ' · ' . number_format($pieces) . ' pcs';
    }
    if ($tpg > 0 && $ppt > 0) {
        $perGal = $tpg * $ppt;
        $gals = $pieces / $perGal;
        $out['unit'] = 'gallon';
        $out['trays_per_gallon'] = $tpg;
        $out['pcs_per_gallon'] = $perGal;
        $out['gallons'] = round($gals, 3);
        $out['label'] = bakery_pmd_fmt_qty($gals) . ' gal · ' . number_format($pieces) . ' pcs'
            . ($ppt > 1 ? (' · ' . ($out['trays'] ?? 0) . ' trays') : '');
    }
    return $out;
}

/**
 * Theoretical dough batches from standard_batch_dough_grams.
 *
 * @return array{standard_batch_grams:?int,batches:?float,batches_ceil:?int,label:string}
 */
function bakery_pmd_standard_batches(?int $standardBatchGrams, int $doughGrams): array
{
    $out = [
        'standard_batch_grams' => $standardBatchGrams && $standardBatchGrams > 0 ? $standardBatchGrams : null,
        'batches' => null,
        'batches_ceil' => null,
        'label' => '',
    ];
    if ($out['standard_batch_grams'] === null || $doughGrams <= 0) {
        return $out;
    }
    $batches = $doughGrams / $out['standard_batch_grams'];
    $out['batches'] = round($batches, 2);
    $out['batches_ceil'] = (int)ceil($batches);
    $out['label'] = bakery_pmd_fmt_qty((float)$out['batches'], 2) . ' × '
        . number_format($out['standard_batch_grams']) . ' g mix'
        . ' (ceil ' . $out['batches_ceil'] . ')';
    return $out;
}

/**
 * Build the Production Manager Dashboard payload for one delivery date.
 *
 * @return array<string,mixed>
 */
function bakery_pmd_build(PDO $db, string $date): array
{
    $date = bakery_pmd_resolve_date($date);
    $priorDate = date('Y-m-d', strtotime($date . ' -7 days'));

    $bakeList = bakery_production_bake_list($db, $date);
    $priorBake = bakery_production_bake_list($db, $priorDate);
    $draft = function_exists('bakery_production_plan_draft_quantities')
        ? bakery_production_plan_draft_quantities($db, $date)
        : [];

    $bakeByProduct = [];
    foreach ($bakeList['items'] as $item) {
        $bakeByProduct[(int)$item['product_id']] = $item;
    }
    $priorByProduct = [];
    foreach ($priorBake['items'] as $item) {
        $priorByProduct[(int)$item['product_id']] = (int)$item['bake_quantity'];
    }

    $inventoryReady = function_exists('bakery_inventory_ready') && bakery_inventory_ready($db);
    $onHandByProduct = [];
    $producedByProduct = [];
    if ($inventoryReady && table_exists($db, 'product_inventory_days')) {
        $invStmt = $db->prepare(
            'SELECT product_id,
                    COALESCE(available_quantity, 0) AS available_quantity,
                    COALESCE(produced_quantity, 0) AS produced_quantity
             FROM product_inventory_days
             WHERE inventory_date = ?'
        );
        $invStmt->execute([$date]);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            $onHandByProduct[$pid] = (int)$row['available_quantity'];
            $producedByProduct[$pid] = (int)$row['produced_quantity'];
        }
    }

    $productIds = array_keys($bakeByProduct);
    if ($productIds === []) {
        return [
            'date' => $date,
            'prior_date' => $priorDate,
            'date_display' => date('l, F j, Y', strtotime($date)),
            'committed' => !empty($bakeList['committed']),
            'bake_source' => !empty($bakeList['committed']) ? 'committed_plan' : 'demand',
            'has_daily' => !empty($bakeList['has_daily']),
            'inventory_ready' => $inventoryReady,
            'commit' => $bakeList['commit'] ?? null,
            'changed_since' => $bakeList['changed_since'] ?? ['count' => 0, 'latest' => null, 'examples' => []],
            'summary' => [
                'dough_types' => 0,
                'products' => 0,
                'pieces' => 0,
                'demand_pieces' => 0,
                'planned_pieces' => (int)array_sum($draft),
                'produced_pieces' => 0,
                'dough_grams' => 0,
                'prior_pieces' => 0,
            ],
            'doughs' => [],
            'links' => [
                'production_center' => (defined('BASE_URL') ? BASE_URL : '') . 'production_center.php?date=' . rawurlencode($date),
                'production' => (defined('BASE_URL') ? BASE_URL : '') . 'production.php?date=' . rawurlencode($date),
                'pack_list' => (defined('BASE_URL') ? BASE_URL : '') . 'pack_list.php?date=' . rawurlencode($date),
                'ingredient_requirements' => (defined('BASE_URL') ? BASE_URL : '') . 'ingredient_requirements.php?date=' . rawurlencode($date) . '&source=demand',
                'product_manager_plan' => (defined('BASE_URL') ? BASE_URL : '') . 'product_manager_plan.php?date=' . rawurlencode($date),
            ],
        ];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $hasStandardBatch = function_exists('column_exists')
        && column_exists($db, 'dough_types', 'standard_batch_dough_grams');
    $standardSelect = $hasStandardBatch ? 'dt.standard_batch_dough_grams' : 'NULL AS standard_batch_dough_grams';

    $stmt = $db->prepare(
        "SELECT p.id, p.name, p.weight_grams, p.dough_type_id,
                dt.name AS dough_type_name, {$standardSelect},
                pl.name AS product_line_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE p.id IN ({$placeholders})
         ORDER BY COALESCE(dt.name, 'ZZZ'), p.name"
    );
    $stmt->execute($productIds);
    $catalog = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $doughBuckets = [];
    foreach ($catalog as $product) {
        $pid = (int)$product['id'];
        $item = $bakeByProduct[$pid];
        $doughKey = (int)($product['dough_type_id'] ?? 0);
        $doughName = trim((string)($product['dough_type_name'] ?? ''));
        if ($doughName === '') {
            $doughName = function_exists('bakery_t')
                ? bakery_t('production_manager.no_dough')
                : 'No dough type';
            $doughKey = -1;
        }
        if (!isset($doughBuckets[$doughKey])) {
            $doughBuckets[$doughKey] = [
                'dough_type_id' => $doughKey > 0 ? $doughKey : null,
                'dough_type_name' => $doughName,
                'product_line_name' => (string)($product['product_line_name'] ?? ''),
                'standard_batch_dough_grams' => isset($product['standard_batch_dough_grams'])
                    ? (int)$product['standard_batch_dough_grams']
                    : null,
                'products' => [],
            ];
        }

        $bakeQty = (int)$item['bake_quantity'];
        $demandQty = (int)$item['demand_quantity'];
        $plannedQty = (int)($draft[$pid] ?? 0);
        $weight = (int)($product['weight_grams'] ?? 0);
        $doughGrams = ($bakeQty > 0 && $weight > 0) ? $bakeQty * $weight : 0;
        $produced = (int)($producedByProduct[$pid] ?? 0);
        $onHand = (int)($onHandByProduct[$pid] ?? 0);
        $left = max(0, $bakeQty - $produced);
        $batchLabel = bakery_pack_batch_label($db, $pid, $bakeQty);
        $breakdown = bakery_pack_count_breakdown($db, $pid, $bakeQty);
        $scale = bakery_pack_input_scale($db, $pid);
        $batchQty = null;
        if ($bakeQty > 0 && $scale['pcs_per'] > 0 && $scale['unit'] !== 'piece') {
            $batchQty = round($bakeQty / $scale['pcs_per'], 3);
        }

        $doughBuckets[$doughKey]['products'][] = [
            'product_id' => $pid,
            'name' => (string)$product['name'],
            'weight_grams' => $weight,
            'bake_quantity' => $bakeQty,
            'demand_quantity' => $demandQty,
            'planned_quantity' => $plannedQty,
            'produced_quantity' => $produced,
            'on_hand' => $onHand,
            'left' => $left,
            'dough_grams' => $doughGrams,
            'dough_weight' => bakery_pmd_format_dough_weight($doughGrams),
            'batch_label' => $batchLabel,
            'batch_unit' => $scale['unit'],
            'batch_quantity' => $batchQty,
            'pcs_per_batch_unit' => $scale['pcs_per'],
            'pack' => $breakdown,
            'prior_bake_quantity' => (int)($priorByProduct[$pid] ?? 0),
            'delta_vs_prior' => $bakeQty - (int)($priorByProduct[$pid] ?? 0),
            'source' => (string)$item['source'],
        ];
    }

    $doughs = [];
    $sumPieces = 0;
    $sumDemand = 0;
    $sumProduced = 0;
    $sumDoughGrams = 0;
    $sumPrior = 0;
    $productCount = 0;

    foreach ($doughBuckets as $bucket) {
        $pieces = 0;
        $demand = 0;
        $produced = 0;
        $doughGrams = 0;
        $prior = 0;
        foreach ($bucket['products'] as $p) {
            $pieces += (int)$p['bake_quantity'];
            $demand += (int)$p['demand_quantity'];
            $produced += (int)$p['produced_quantity'];
            $doughGrams += (int)$p['dough_grams'];
            $prior += (int)$p['prior_bake_quantity'];
        }
        $doughTypeId = $bucket['dough_type_id'];
        $doughYield = $doughTypeId ? bakery_pack_dough_yield($db, (int)$doughTypeId) : null;
        $batch = bakery_pmd_dough_batch($doughYield, $pieces);
        // If dough yield missing, prefer dominant product batch unit summary.
        if ($batch['unit'] === 'piece' && $bucket['products'] !== []) {
            $unitVotes = [];
            foreach ($bucket['products'] as $p) {
                if ($p['batch_unit'] === 'piece' || $p['bake_quantity'] <= 0) {
                    continue;
                }
                $unitVotes[$p['batch_unit']] = ($unitVotes[$p['batch_unit']] ?? 0) + (int)$p['bake_quantity'];
            }
            arsort($unitVotes);
            $topUnit = $unitVotes !== [] ? (string)array_key_first($unitVotes) : '';
            if ($topUnit !== '') {
                $qtySum = 0.0;
                $labels = [];
                foreach ($bucket['products'] as $p) {
                    if ($p['batch_unit'] === $topUnit && $p['batch_quantity'] !== null) {
                        $qtySum += (float)$p['batch_quantity'];
                    }
                    if ($p['batch_label'] !== '') {
                        $labels[] = $p['name'] . ': ' . $p['batch_label'];
                    }
                }
                $batch['unit'] = $topUnit;
                if ($topUnit === 'gallon') {
                    $batch['gallons'] = round($qtySum, 3);
                    $batch['label'] = bakery_pmd_fmt_qty($qtySum) . ' gal · ' . number_format($pieces) . ' pcs';
                } elseif ($topUnit === 'tray') {
                    $batch['trays'] = (int)round($qtySum);
                    $batch['label'] = bakery_pmd_fmt_qty($qtySum, 1) . ' trays · ' . number_format($pieces) . ' pcs';
                } elseif ($topUnit === 'barra') {
                    $batch['label'] = bakery_pmd_fmt_qty($qtySum, 0) . ' barras · ' . number_format($pieces) . ' pcs';
                }
                if (count($labels) > 1 && $batch['label'] === number_format($pieces) . ' pcs') {
                    $batch['label'] = number_format($pieces) . ' pcs (mixed SKU batches)';
                }
            }
        }

        $standard = bakery_pmd_standard_batches(
            $bucket['standard_batch_dough_grams'] > 0 ? (int)$bucket['standard_batch_dough_grams'] : null,
            $doughGrams
        );

        $statuses = [];
        if ($pieces > 0 && $produced === 0) {
            $statuses[] = ['code' => 'not_started', 'label' => 'Not started', 'tone' => 'warn'];
        } elseif ($pieces > 0 && $produced > 0 && $produced < $pieces) {
            $statuses[] = ['code' => 'partial', 'label' => number_format($produced) . ' made', 'tone' => 'info'];
        } elseif ($pieces > 0 && $produced >= $pieces) {
            $statuses[] = ['code' => 'done', 'label' => 'Made', 'tone' => 'ok'];
        }
        if ($demand > 0 && $pieces < $demand) {
            $statuses[] = ['code' => 'under', 'label' => 'Bake below demand', 'tone' => 'danger'];
        }

        $doughs[] = [
            'dough_type_id' => $doughTypeId,
            'dough_type_name' => $bucket['dough_type_name'],
            'product_line_name' => $bucket['product_line_name'],
            'product_count' => count($bucket['products']),
            'pieces' => $pieces,
            'demand_pieces' => $demand,
            'produced_pieces' => $produced,
            'left_pieces' => max(0, $pieces - $produced),
            'prior_pieces' => $prior,
            'delta_vs_prior' => $pieces - $prior,
            'dough_grams' => $doughGrams,
            'dough_weight' => bakery_pmd_format_dough_weight($doughGrams),
            'batch' => $batch,
            'standard_batches' => $standard,
            'statuses' => $statuses,
            'products' => $bucket['products'],
        ];

        $sumPieces += $pieces;
        $sumDemand += $demand;
        $sumProduced += $produced;
        $sumDoughGrams += $doughGrams;
        $sumPrior += $prior;
        $productCount += count($bucket['products']);
    }

    usort($doughs, static function (array $a, array $b): int {
        return $b['pieces'] <=> $a['pieces'] ?: strcasecmp($a['dough_type_name'], $b['dough_type_name']);
    });

    return [
        'date' => $date,
        'prior_date' => $priorDate,
        'date_display' => date('l, F j, Y', strtotime($date)),
        'committed' => !empty($bakeList['committed']),
        'bake_source' => !empty($bakeList['committed']) ? 'committed_plan' : 'demand',
        'has_daily' => !empty($bakeList['has_daily']),
        'inventory_ready' => $inventoryReady,
        'commit' => $bakeList['commit'] ?? null,
        'changed_since' => $bakeList['changed_since'] ?? ['count' => 0, 'latest' => null, 'examples' => []],
        'summary' => [
            'dough_types' => count($doughs),
            'products' => $productCount,
            'pieces' => $sumPieces,
            'demand_pieces' => $sumDemand,
            'planned_pieces' => (int)array_sum($draft),
            'produced_pieces' => $sumProduced,
            'dough_grams' => $sumDoughGrams,
            'dough_weight' => bakery_pmd_format_dough_weight($sumDoughGrams),
            'prior_pieces' => $sumPrior,
            'delta_vs_prior' => $sumPieces - $sumPrior,
        ],
        'doughs' => $doughs,
        'links' => [
            'production_center' => (defined('BASE_URL') ? BASE_URL : '') . 'production_center.php?date=' . rawurlencode($date),
            'production' => (defined('BASE_URL') ? BASE_URL : '') . 'production.php?date=' . rawurlencode($date),
            'pack_list' => (defined('BASE_URL') ? BASE_URL : '') . 'pack_list.php?date=' . rawurlencode($date),
            'ingredient_requirements' => (defined('BASE_URL') ? BASE_URL : '') . 'ingredient_requirements.php?date=' . rawurlencode($date) . '&source=demand',
            'product_manager_plan' => (defined('BASE_URL') ? BASE_URL : '') . 'product_manager_plan.php?date=' . rawurlencode($date),
        ],
    ];
}
