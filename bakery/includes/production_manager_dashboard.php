<?php
/**
 * Production Manager Dashboard — dough-first board for one delivery day.
 *
 * Groups bake quantities by dough type with pieces, batch size (gal/tray),
 * dough weight, and expandable SKU rows. Complements Production Center
 * (edit/commit) and Daily Production (baker sheet).
 *
 * Extra sense tabs (same page, no second module):
 *   week   — past week / typical weekday order volume
 *   routes — standing route plan vs dated assignments (incomplete OK)
 *   supply — forecasted demand vs produced / on-hand
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_plan.php';
require_once __DIR__ . '/product_pack_yields.php';
require_once __DIR__ . '/product_inventory.php';

/** @var list<string> */
define('BAKERY_PMD_VIEWS', ['batches', 'week', 'routes', 'supply']);

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
 * Resolve the sense tab (default: batches).
 */
function bakery_pmd_resolve_view(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, BAKERY_PMD_VIEWS, true) ? $raw : 'batches';
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
             WHERE delivery_date = ?'
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
                'dough_weight' => bakery_pmd_format_dough_weight(0),
                'prior_pieces' => 0,
                'delta_vs_prior' => 0,
            ],
            'doughs' => [],
            'links' => bakery_pmd_links($date),
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
        'links' => bakery_pmd_links($date),
    ];
}

/**
 * Shared deep links for the Production Manager sense board.
 *
 * @return array<string,string>
 */
function bakery_pmd_links(string $date): array
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = rawurlencode($date);
    return [
        'production_center' => $base . 'production_center.php?date=' . $q,
        'production' => $base . 'production.php?date=' . $q,
        'pack_list' => $base . 'pack_list.php?date=' . $q,
        'ingredient_requirements' => $base . 'ingredient_requirements.php?date=' . $q . '&source=demand',
        'product_manager_plan' => $base . 'product_manager_plan.php?date=' . $q,
        'daily_orders' => $base . 'daily_orders.php?date=' . $q,
        'daily_route' => $base . 'daily_route.php?date=' . $q,
        'route_manager' => $base . 'route_manager.php?date=' . $q,
        'inventory' => $base . 'inventory.php?date=' . $q,
        'standing_routes' => $base . 'standing_routes.php',
    ];
}

/**
 * Past-week daily order volume + typical weekday sense for the selected day.
 *
 * Uses dated daily_orders / items (commercial commitment). Incomplete days
 * still show — zeros mean no dated orders on file yet.
 *
 * @return array<string,mixed>
 */
function bakery_pmd_week_orders(PDO $db, string $date): array
{
    $date = bakery_pmd_resolve_date($date);
    $start = date('Y-m-d', strtotime($date . ' -6 days'));
    $weekday = bakery_standing_day_from_date($date);
    $dayNames = function_exists('bakery_day_names') ? bakery_day_names() : [];
    $dayLabel = $dayNames[$weekday] ?? date('l', strtotime($date));

    $byDate = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($start . " +{$i} days"));
        $wd = bakery_standing_day_from_date($d);
        $byDate[$d] = [
            'date' => $d,
            'label' => date('D n/j', strtotime($d)),
            'weekday' => $wd,
            'weekday_label' => $dayNames[$wd] ?? date('l', strtotime($d)),
            'is_selected' => $d === $date,
            'customers' => 0,
            'lines' => 0,
            'pieces' => 0,
            'has_orders' => false,
        ];
    }

    if (table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_items')) {
        try {
            $stmt = $db->prepare(
                'SELECT do.order_date,
                        COUNT(DISTINCT do.id) AS order_count,
                        COUNT(DISTINCT do.customer_id) AS customers,
                        COUNT(doi.id) AS lines,
                        COALESCE(SUM(doi.quantity), 0) AS pieces
                 FROM daily_orders do
                 LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
                 WHERE do.order_date BETWEEN ? AND ?
                 GROUP BY do.order_date'
            );
            $stmt->execute([$start, $date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = (string)$row['order_date'];
                if (!isset($byDate[$d])) {
                    continue;
                }
                $byDate[$d]['customers'] = (int)$row['customers'];
                $byDate[$d]['lines'] = (int)$row['lines'];
                $byDate[$d]['pieces'] = (int)$row['pieces'];
                $byDate[$d]['has_orders'] = ((int)$row['order_count']) > 0;
            }
        } catch (Throwable $e) {
            error_log('pmd week orders: ' . $e->getMessage());
        }
    }

    $days = array_values($byDate);
    $pieceVals = array_map(static fn(array $d): int => (int)$d['pieces'], $days);
    $activeDays = array_values(array_filter($days, static fn(array $d): bool => !empty($d['has_orders'])));
    $activePieces = array_map(static fn(array $d): int => (int)$d['pieces'], $activeDays);
    $weekTotal = array_sum($pieceVals);
    $weekAvg = $activePieces !== []
        ? (int)round(array_sum($activePieces) / count($activePieces))
        : 0;

    // Typical for this weekday: last 8 matching weekdays ending on selected date.
    $typicalPieces = [];
    $typicalCustomers = [];
    if (table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_items')) {
        try {
            $lookbackStart = date('Y-m-d', strtotime($date . ' -56 days'));
            $stmt = $db->prepare(
                'SELECT do.order_date,
                        COUNT(DISTINCT do.customer_id) AS customers,
                        COALESCE(SUM(doi.quantity), 0) AS pieces
                 FROM daily_orders do
                 LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
                 WHERE do.order_date BETWEEN ? AND ?
                 GROUP BY do.order_date
                 ORDER BY do.order_date DESC'
            );
            $stmt->execute([$lookbackStart, $date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (bakery_standing_day_from_date((string)$row['order_date']) !== $weekday) {
                    continue;
                }
                $typicalPieces[] = (int)$row['pieces'];
                $typicalCustomers[] = (int)$row['customers'];
                if (count($typicalPieces) >= 8) {
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('pmd typical weekday: ' . $e->getMessage());
        }
    }

    $typicalAvg = $typicalPieces !== []
        ? (int)round(array_sum($typicalPieces) / count($typicalPieces))
        : 0;
    $selected = $byDate[$date];
    $vsTypical = $typicalAvg > 0 ? ((int)$selected['pieces'] - $typicalAvg) : null;

    // Top products on the selected day (simple sense of mix).
    $topProducts = [];
    if (table_exists($db, 'daily_order_items') && table_exists($db, 'daily_orders') && table_exists($db, 'products')) {
        try {
            $stmt = $db->prepare(
                'SELECT p.id AS product_id, p.name,
                        COALESCE(SUM(doi.quantity), 0) AS pieces,
                        COUNT(DISTINCT do.customer_id) AS customers
                 FROM daily_order_items doi
                 JOIN daily_orders do ON do.id = doi.daily_order_id
                 JOIN products p ON p.id = doi.product_id
                 WHERE do.order_date = ?
                 GROUP BY p.id, p.name
                 HAVING pieces > 0
                 ORDER BY pieces DESC, p.name ASC
                 LIMIT 12'
            );
            $stmt->execute([$date]);
            $topProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($topProducts as &$tp) {
                $tp['product_id'] = (int)$tp['product_id'];
                $tp['pieces'] = (int)$tp['pieces'];
                $tp['customers'] = (int)$tp['customers'];
            }
            unset($tp);
        } catch (Throwable $e) {
            error_log('pmd week top products: ' . $e->getMessage());
        }
    }

    return [
        'date' => $date,
        'start' => $start,
        'weekday' => $weekday,
        'weekday_label' => $dayLabel,
        'days' => $days,
        'summary' => [
            'week_pieces' => $weekTotal,
            'week_avg_active' => $weekAvg,
            'active_days' => count($activeDays),
            'selected_pieces' => (int)$selected['pieces'],
            'selected_customers' => (int)$selected['customers'],
            'typical_weekday_avg' => $typicalAvg,
            'typical_sample_size' => count($typicalPieces),
            'vs_typical' => $vsTypical,
        ],
        'top_products' => $topProducts,
        'incomplete' => count($activeDays) < 7,
        'links' => bakery_pmd_links($date),
    ];
}

/**
 * Standing weekday route plan lined up with dated route actuals.
 *
 * Incomplete data is expected — missing assignments or delivery times still
 * appear so the gap is visible.
 *
 * @return array<string,mixed>
 */
function bakery_pmd_route_plan_vs_actual(PDO $db, string $date): array
{
    $date = bakery_pmd_resolve_date($date);
    $weekday = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($weekday);
    $dayNames = function_exists('bakery_day_names') ? bakery_day_names() : [];
    $dayLabel = $dayNames[$weekday] ?? date('l', strtotime($date));

    $plannedByCustomer = [];
    if (table_exists($db, 'standing_routes') && table_exists($db, 'customers') && table_exists($db, 'drivers')) {
        try {
            $sql = "SELECT sr.customer_id, c.name AS customer_name, c.zone,
                           sr.driver_id AS plan_driver_id, d.name AS plan_driver_name,
                           sr.route_order AS plan_route_order
                    FROM standing_routes sr
                    JOIN customers c ON c.id = sr.customer_id
                    JOIN drivers d ON d.id = sr.driver_id
                    WHERE sr.day_of_week {$dayClause['sql']}
                    ORDER BY d.name, COALESCE(sr.route_order, 9999), c.name";
            $stmt = $db->prepare($sql);
            $stmt->execute($dayClause['values']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int)$row['customer_id'];
                $plannedByCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => (string)$row['customer_name'],
                    'zone' => (string)($row['zone'] ?? ''),
                    'plan_driver_id' => (int)$row['plan_driver_id'],
                    'plan_driver_name' => (string)$row['plan_driver_name'],
                    'plan_route_order' => $row['plan_route_order'] !== null ? (int)$row['plan_route_order'] : null,
                ];
            }
        } catch (Throwable $e) {
            error_log('pmd route plan: ' . $e->getMessage());
        }
    }

    $actualByCustomer = [];
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        try {
            $stmt = $db->prepare(
                "SELECT do.customer_id, c.name AS customer_name, c.zone,
                        doa.driver_id AS actual_driver_id, d.name AS actual_driver_name,
                        doa.route_order AS actual_route_order,
                        doa.delivery_status,
                        doa.scheduled_delivery_time,
                        doa.actual_delivery_time,
                        doa.estimated_delivery_time
                 FROM daily_order_assignments doa
                 JOIN daily_orders do ON do.id = doa.daily_order_id
                 JOIN customers c ON c.id = do.customer_id
                 JOIN drivers d ON d.id = doa.driver_id
                 WHERE doa.delivery_date = ?
                 ORDER BY d.name, COALESCE(doa.route_order, 9999), c.name"
            );
            $stmt->execute([$date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cid = (int)$row['customer_id'];
                // If a customer somehow has multiple assignments, keep the first (lowest route order).
                if (isset($actualByCustomer[$cid])) {
                    continue;
                }
                $actualByCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => (string)$row['customer_name'],
                    'zone' => (string)($row['zone'] ?? ''),
                    'actual_driver_id' => (int)$row['actual_driver_id'],
                    'actual_driver_name' => (string)$row['actual_driver_name'],
                    'actual_route_order' => isset($row['actual_route_order']) ? (int)$row['actual_route_order'] : null,
                    'delivery_status' => (string)($row['delivery_status'] ?? 'pending'),
                    'scheduled_delivery_time' => $row['scheduled_delivery_time'] ?? null,
                    'actual_delivery_time' => $row['actual_delivery_time'] ?? null,
                    'estimated_delivery_time' => $row['estimated_delivery_time'] ?? null,
                ];
            }
        } catch (Throwable $e) {
            error_log('pmd route actual: ' . $e->getMessage());
        }
    }

    $ids = array_unique(array_merge(array_keys($plannedByCustomer), array_keys($actualByCustomer)));
    $rows = [];
    $match = 0;
    $reassigned = 0;
    $planOnly = 0;
    $actualOnly = 0;
    $delivered = 0;
    $pending = 0;

    foreach ($ids as $cid) {
        $plan = $plannedByCustomer[$cid] ?? null;
        $act = $actualByCustomer[$cid] ?? null;
        $name = $plan['customer_name'] ?? $act['customer_name'] ?? ('#' . $cid);
        $zone = $plan['zone'] ?? $act['zone'] ?? '';
        $planDriver = $plan['plan_driver_name'] ?? null;
        $actDriver = $act['actual_driver_name'] ?? null;
        $status = $act['delivery_status'] ?? null;

        if ($plan && $act) {
            $same = ((int)$plan['plan_driver_id'] === (int)$act['actual_driver_id']);
            $alignment = $same ? 'match' : 'reassigned';
            if ($same) {
                $match++;
            } else {
                $reassigned++;
            }
        } elseif ($plan && !$act) {
            $alignment = 'plan_only';
            $planOnly++;
        } else {
            $alignment = 'actual_only';
            $actualOnly++;
        }

        if ($status === 'delivered') {
            $delivered++;
        } elseif ($status !== null && !in_array($status, ['cancelled'], true)) {
            $pending++;
        }

        $rows[] = [
            'customer_id' => (int)$cid,
            'customer_name' => $name,
            'zone' => $zone,
            'plan_driver_name' => $planDriver,
            'plan_route_order' => $plan['plan_route_order'] ?? null,
            'actual_driver_name' => $actDriver,
            'actual_route_order' => $act['actual_route_order'] ?? null,
            'delivery_status' => $status,
            'scheduled_delivery_time' => $act['scheduled_delivery_time'] ?? null,
            'actual_delivery_time' => $act['actual_delivery_time'] ?? null,
            'alignment' => $alignment,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $da = (string)($a['plan_driver_name'] ?? $a['actual_driver_name'] ?? '');
        $dbn = (string)($b['plan_driver_name'] ?? $b['actual_driver_name'] ?? '');
        $byDriver = strcasecmp($da, $dbn);
        if ($byDriver !== 0) {
            return $byDriver;
        }
        $oa = $a['plan_route_order'] ?? $a['actual_route_order'] ?? 9999;
        $ob = $b['plan_route_order'] ?? $b['actual_route_order'] ?? 9999;
        return $oa <=> $ob ?: strcasecmp($a['customer_name'], $b['customer_name']);
    });

    return [
        'date' => $date,
        'weekday' => $weekday,
        'weekday_label' => $dayLabel,
        'rows' => $rows,
        'summary' => [
            'planned_stops' => count($plannedByCustomer),
            'actual_stops' => count($actualByCustomer),
            'matched' => $match,
            'reassigned' => $reassigned,
            'plan_only' => $planOnly,
            'actual_only' => $actualOnly,
            'delivered' => $delivered,
            'open' => $pending,
        ],
        'incomplete' => $planOnly > 0 || $actualOnly > 0 || count($actualByCustomer) === 0,
        'links' => bakery_pmd_links($date),
    ];
}

/**
 * Forecasted / dated demand need vs produced and on-hand inventory.
 *
 * Standing is forecast; dated demand is the operating commitment (via bake list).
 *
 * @return array<string,mixed>
 */
function bakery_pmd_demand_vs_supply(PDO $db, string $date): array
{
    $date = bakery_pmd_resolve_date($date);
    $bakeList = bakery_production_bake_list($db, $date);
    $inventoryReady = function_exists('bakery_inventory_ready') && bakery_inventory_ready($db);

    $onHand = [];
    $produced = [];
    $loaded = [];
    if ($inventoryReady && table_exists($db, 'product_inventory_days')) {
        try {
            $stmt = $db->prepare(
                'SELECT product_id,
                        COALESCE(available_quantity, 0) AS available_quantity,
                        COALESCE(produced_quantity, 0) AS produced_quantity,
                        COALESCE(loaded_quantity, 0) AS loaded_quantity
                 FROM product_inventory_days
                 WHERE delivery_date = ?'
            );
            $stmt->execute([$date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['product_id'];
                $onHand[$pid] = (int)$row['available_quantity'];
                $produced[$pid] = (int)$row['produced_quantity'];
                $loaded[$pid] = (int)$row['loaded_quantity'];
            }
        } catch (Throwable $e) {
            error_log('pmd supply inventory: ' . $e->getMessage());
        }
    }

    $rows = [];
    $sumDemand = 0;
    $sumBake = 0;
    $sumProduced = 0;
    $sumOnHand = 0;
    $shortCount = 0;
    $okCount = 0;

    $names = [];
    $productIds = [];
    foreach ($bakeList['items'] as $item) {
        $productIds[] = (int)$item['product_id'];
    }
    $productIds = array_values(array_unique(array_filter($productIds)));
    if ($productIds !== [] && table_exists($db, 'products')) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        try {
            $nameStmt = $db->prepare("SELECT id, name FROM products WHERE id IN ({$placeholders})");
            $nameStmt->execute($productIds);
            foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int)$row['id']] = (string)$row['name'];
            }
        } catch (Throwable $e) {
            error_log('pmd supply names: ' . $e->getMessage());
        }
    }

    foreach ($bakeList['items'] as $item) {
        $pid = (int)$item['product_id'];
        $demand = (int)$item['demand_quantity'];
        $bake = (int)$item['bake_quantity'];
        $made = (int)($produced[$pid] ?? 0);
        $hand = (int)($onHand[$pid] ?? 0);
        $load = (int)($loaded[$pid] ?? 0);
        // Cover = best finished-goods signal available for the day.
        $cover = max($made, $hand);
        $gap = $demand - $cover;
        if ($demand <= 0 && $bake <= 0 && $made <= 0 && $hand <= 0) {
            continue;
        }
        $tone = 'ok';
        if ($demand > 0 && $gap > 0) {
            $tone = 'short';
            $shortCount++;
        } elseif ($demand > 0 && $gap <= 0) {
            $okCount++;
        } elseif ($demand <= 0 && ($bake > 0 || $made > 0 || $hand > 0)) {
            $tone = 'extra';
        }

        $rows[] = [
            'product_id' => $pid,
            'name' => $names[$pid] ?? ('#' . $pid),
            'demand' => $demand,
            'bake' => $bake,
            'produced' => $made,
            'on_hand' => $hand,
            'loaded' => $load,
            'cover' => $cover,
            'gap' => $gap,
            'tone' => $tone,
            'source' => (string)($item['source'] ?? ''),
        ];
        $sumDemand += $demand;
        $sumBake += $bake;
        $sumProduced += $made;
        $sumOnHand += $hand;
    }

    usort($rows, static function (array $a, array $b): int {
        $toneRank = ['short' => 0, 'extra' => 1, 'ok' => 2];
        $ra = $toneRank[$a['tone']] ?? 9;
        $rb = $toneRank[$b['tone']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        // Larger shortage first.
        if ($a['tone'] === 'short' && $b['tone'] === 'short') {
            return $b['gap'] <=> $a['gap'];
        }
        return strcasecmp($a['name'], $b['name']);
    });

    return [
        'date' => $date,
        'committed' => !empty($bakeList['committed']),
        'bake_source' => !empty($bakeList['committed']) ? 'committed_plan' : 'demand',
        'inventory_ready' => $inventoryReady,
        'rows' => $rows,
        'summary' => [
            'products' => count($rows),
            'demand' => $sumDemand,
            'bake' => $sumBake,
            'produced' => $sumProduced,
            'on_hand' => $sumOnHand,
            'short_skus' => $shortCount,
            'covered_skus' => $okCount,
            'net_gap' => $sumDemand - max($sumProduced, $sumOnHand),
        ],
        'incomplete' => !$inventoryReady || ($sumProduced === 0 && $sumOnHand === 0 && $sumDemand > 0),
        'links' => bakery_pmd_links($date),
    ];
}
