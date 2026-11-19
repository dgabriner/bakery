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

/** @var list<string> */
define('BAKERY_PMD_SOURCES', ['bake_list', 'last_entered']);

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
 * Resolve bake-quantity source (default: current bake list).
 */
function bakery_pmd_resolve_source(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, BAKERY_PMD_SOURCES, true) ? $raw : 'bake_list';
}

/**
 * Most recent saved Production Center plan (kitchen-note / typed targets).
 *
 * Ignores store-demand estimates. Only dates with planned_quantity > 0 count.
 *
 * @return array{date:string,date_display:string,pieces:int,products:int,quantities:array<int,int>}|null
 */
function bakery_pmd_entered_plan_for_date(PDO $db, string $date): ?array
{
    $date = bakery_pmd_resolve_date($date);
    if (!function_exists('bakery_production_plan_draft_quantities')) {
        return null;
    }
    $quantities = bakery_production_plan_draft_quantities($db, $date);
    $quantities = array_filter(
        $quantities,
        static fn(int $qty): bool => $qty > 0
    );
    if ($quantities === []) {
        return null;
    }
    return [
        'date' => $date,
        'date_display' => date('D, M j, Y', strtotime($date)),
        'pieces' => (int)array_sum($quantities),
        'products' => count($quantities),
        'quantities' => $quantities,
    ];
}

function bakery_pmd_latest_entered_plan(PDO $db): ?array
{
    if (!function_exists('table_exists') || !table_exists($db, 'production_plan_items')) {
        return null;
    }
    try {
        $stmt = $db->query(
            'SELECT delivery_date
             FROM production_plan_items
             WHERE planned_quantity > 0
             GROUP BY delivery_date
             HAVING SUM(planned_quantity) > 0
             ORDER BY delivery_date DESC
             LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    } catch (Throwable $e) {
        error_log('pmd last entered plan: ' . $e->getMessage());
        return null;
    }
    if (!$row) {
        return null;
    }
    return bakery_pmd_entered_plan_for_date($db, (string)$row['delivery_date']);
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
 * @param array{source?:string} $options
 * @return array<string,mixed>
 */
function bakery_pmd_build(PDO $db, string $date, array $options = []): array
{
    $date = bakery_pmd_resolve_date($date);
    $sourceMode = bakery_pmd_resolve_source((string)($options['source'] ?? 'bake_list'));
    $rawEnteredDate = trim((string)($options['entered_date'] ?? ''));
    $enteredDate = '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawEnteredDate) === 1) {
        $enteredDt = DateTime::createFromFormat('!Y-m-d', $rawEnteredDate);
        if ($enteredDt && $enteredDt->format('Y-m-d') === $rawEnteredDate) {
            $enteredDate = $rawEnteredDate;
        }
    }
    $priorDate = date('Y-m-d', strtotime($date . ' -7 days'));

    $bakeList = bakery_production_bake_list($db, $date);
    $priorBake = bakery_production_bake_list($db, $priorDate);
    $draft = function_exists('bakery_production_plan_draft_quantities')
        ? bakery_production_plan_draft_quantities($db, $date)
        : [];
    $entered = null;
    if ($sourceMode === 'last_entered') {
        $entered = $enteredDate !== ''
            ? bakery_pmd_entered_plan_for_date($db, $enteredDate)
            : bakery_pmd_latest_entered_plan($db);
    }

    $bakeByProduct = [];
    foreach ($bakeList['items'] as $item) {
        $bakeByProduct[(int)$item['product_id']] = $item;
    }
    if ($entered !== null) {
        $overlay = [];
        foreach ($entered['quantities'] as $pid => $qty) {
            $pid = (int)$pid;
            $overlay[$pid] = [
                'product_id' => $pid,
                'demand_quantity' => (int)($bakeByProduct[$pid]['demand_quantity'] ?? 0),
                'bake_quantity' => (int)$qty,
                'source' => 'last_entered',
            ];
        }
        $bakeByProduct = $overlay;
        $bakeList['items'] = array_values($overlay);
        $bakeList['committed'] = false;
    }
    $priorByProduct = [];
    foreach ($priorBake['items'] as $item) {
        $priorByProduct[(int)$item['product_id']] = (int)$item['bake_quantity'];
    }

    $quantityDate = ($entered !== null && !empty($entered['date'])) ? (string)$entered['date'] : $date;
    $quantityDateDisplay = date('l, F j, Y', strtotime($quantityDate));
    $dateMismatch = $quantityDate !== $date;

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
        $invStmt->execute([$quantityDate]);
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
            'quantity_date' => $quantityDate,
            'quantity_date_display' => $quantityDateDisplay,
            'date_mismatch' => $dateMismatch,
            'committed' => !empty($bakeList['committed']),
            'bake_source' => $entered !== null
                ? 'last_entered'
                : (!empty($bakeList['committed']) ? 'committed_plan' : 'demand'),
            'entered_plan' => $entered,
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
                pl.id AS product_line_id, pl.name AS product_line_name
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
                'product_line_id' => (int)($product['product_line_id'] ?? 0) > 0
                    ? (int)$product['product_line_id']
                    : null,
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
            'product_line_id' => $bucket['product_line_id'] ?? null,
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
        'quantity_date' => $quantityDate,
        'quantity_date_display' => $quantityDateDisplay,
        'date_mismatch' => $dateMismatch,
        'committed' => !empty($bakeList['committed']),
        'bake_source' => $entered !== null
            ? 'last_entered'
            : (!empty($bakeList['committed']) ? 'committed_plan' : 'demand'),
        'entered_plan' => $entered,
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
 * Run one SELECT. $statementLoader replaces the query in tests so a retrieval
 * failure can be distinguished from a real empty week.
 *
 * @param callable|null $statementLoader function(string $sql, array $params): array
 * @return list<array<string,mixed>>
 */
function bakery_pmd_fetch_rows(PDO $db, string $sql, array $params, ?callable $statementLoader = null): array
{
    if ($statementLoader !== null) {
        $rows = $statementLoader($sql, $params);
        return is_array($rows) ? array_values($rows) : [];
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}

/**
 * Past-week daily order volume + typical weekday sense for the selected day.
 *
 * Uses dated daily_orders / items (commercial commitment). Incomplete days
 * still show — zeros mean no dated orders on file yet. A failed query is
 * load_state=unavailable and must not be read as an empty week.
 *
 * @param callable|null $statementLoader test seam; production callers omit it
 * @return array<string,mixed>
 */
function bakery_pmd_week_orders(PDO $db, string $date, ?callable $statementLoader = null): array
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

    // `lines` is a reserved word in MariaDB (1064). Keep the result key, not the alias.
    $loadState = 'unavailable';
    if (table_exists($db, 'daily_orders') && table_exists($db, 'daily_order_items')) {
        try {
            $rows = bakery_pmd_fetch_rows(
                $db,
                'SELECT do.order_date,
                        COUNT(DISTINCT do.id) AS order_count,
                        COUNT(DISTINCT do.customer_id) AS customers,
                        COUNT(doi.id) AS line_count,
                        COALESCE(SUM(doi.quantity), 0) AS pieces
                 FROM daily_orders do
                 LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
                 WHERE do.order_date BETWEEN ? AND ?
                 GROUP BY do.order_date',
                [$start, $date],
                $statementLoader
            );
            foreach ($rows as $row) {
                $d = (string)$row['order_date'];
                if (!isset($byDate[$d])) {
                    continue;
                }
                $byDate[$d]['customers'] = (int)$row['customers'];
                $byDate[$d]['lines'] = (int)$row['line_count'];
                $byDate[$d]['pieces'] = (int)$row['pieces'];
                $byDate[$d]['has_orders'] = ((int)$row['order_count']) > 0;
            }
            $loadState = 'ready';
        } catch (Throwable $e) {
            error_log('pmd week orders: ' . $e->getMessage());
            $loadState = 'unavailable';
        }
    }

    if ($loadState !== 'ready') {
        foreach ($byDate as &$failedDay) {
            $failedDay['customers'] = null;
            $failedDay['lines'] = null;
            $failedDay['pieces'] = null;
            $failedDay['has_orders'] = false;
        }
        unset($failedDay);
    }

    $days = array_values($byDate);
    $activeDays = $loadState === 'ready'
        ? array_values(array_filter($days, static fn(array $d): bool => !empty($d['has_orders'])))
        : [];
    $activePieces = array_map(static fn(array $d): int => (int)$d['pieces'], $activeDays);
    $weekTotal = $loadState === 'ready'
        ? array_sum(array_map(static fn(array $d): int => (int)$d['pieces'], $days))
        : null;
    $weekAvg = $loadState === 'ready'
        ? ($activePieces !== [] ? (int)round(array_sum($activePieces) / count($activePieces)) : 0)
        : null;

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
        'load_state' => $loadState,
        'summary' => [
            'week_pieces' => $weekTotal,
            'week_avg_active' => $weekAvg,
            'active_days' => $loadState === 'ready' ? count($activeDays) : null,
            'selected_pieces' => $loadState === 'ready' ? (int)$selected['pieces'] : null,
            'selected_customers' => $loadState === 'ready' ? (int)$selected['customers'] : null,
            'typical_weekday_avg' => $loadState === 'ready' ? $typicalAvg : null,
            'typical_sample_size' => $loadState === 'ready' ? count($typicalPieces) : null,
            'vs_typical' => $loadState === 'ready' ? $vsTypical : null,
        ],
        'top_products' => $loadState === 'ready' ? $topProducts : [],
        'incomplete' => $loadState === 'ready' && count($activeDays) < 7,
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

/**
 * Bake day on the Production Manager page. Invalid input is today.
 */
function bakery_pmd_resolve_bake_date(string $raw): string
{
    $raw = trim($raw);
    $dt = DateTime::createFromFormat('!Y-m-d', $raw);
    if ($dt && $dt->format('Y-m-d') === $raw) {
        return $raw;
    }
    return date('Y-m-d');
}

/**
 * @param list<string> $coverDates
 */
function bakery_pmd_resolve_cover(string $raw, array $coverDates, string $bakeDate): string
{
    $raw = trim($raw);
    if (in_array($raw, $coverDates, true)) {
        return $raw;
    }
    return $coverDates[0] ?? $bakeDate;
}

/**
 * @return list<array{family:string,cover_dates:list<string>,active:bool}>
 */
function bakery_pmd_bake_families(string $bakeDate): array
{
    if (!function_exists('bakery_production_cadence_cover_dates')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $families = [];
    foreach ([BAKERY_PRODUCTION_CADENCE_DAILY, BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR] as $family) {
        $covers = bakery_production_cadence_cover_dates($family, $bakeDate);
        $families[] = [
            'family' => $family,
            'cover_dates' => $covers,
            'active' => $covers !== [],
        ];
    }
    return $families;
}

/**
 * @return list<string>
 */
function bakery_pmd_bake_cover_dates(string $bakeDate): array
{
    $dates = [];
    foreach (bakery_pmd_bake_families($bakeDate) as $family) {
        foreach ($family['cover_dates'] as $date) {
            $dates[$date] = true;
        }
    }
    $out = array_keys($dates);
    sort($out);
    return $out;
}

/**
 * @param list<string> $dates
 */
function bakery_pmd_format_cover_dates(array $dates): string
{
    $out = [];
    foreach ($dates as $date) {
        $ts = strtotime((string)$date);
        if ($ts) {
            $out[] = date('D n/j', $ts);
        }
    }
    return implode(', ', $out);
}

function bakery_pmd_bake_cover_label(string $bakeDate): string
{
    if (!function_exists('bakery_production_cadence_cover_dates')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $parts = [];
    foreach (bakery_pmd_bake_families($bakeDate) as $family) {
        if ($family['cover_dates'] === []) {
            continue;
        }
        $days = bakery_pmd_format_cover_dates($family['cover_dates']);
        $familyLabel = function_exists('bakery_t')
            ? bakery_t('production_cadence.family.' . $family['family'])
            : $family['family'];
        $bakeLabel = date('l, F j', strtotime($bakeDate));
        if (function_exists('bakery_t')) {
            $parts[] = bakery_t('production_cadence.day_chip', [
                'family' => $familyLabel,
                'bake' => $bakeLabel,
                'days' => $days,
            ]);
        } else {
            $parts[] = $familyLabel . ' ' . $days;
        }
    }
    return implode(' · ', $parts);
}

/**
 * @param list<int> $values newest first or any order
 */
function bakery_pmd_median(array $values): int
{
    $nums = array_map('intval', array_values($values));
    sort($nums);
    $count = count($nums);
    if ($count === 0) {
        return 0;
    }
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return (int)$nums[$mid];
    }
    return (int)round(($nums[$mid - 1] + $nums[$mid]) / 2);
}

/**
 * @param list<int> $samples newest first
 * @return array{last:?int,median:?int,samples:int}
 */
function bakery_pmd_usual_summary(array $samples): array
{
    $samples = array_values(array_map('intval', $samples));
    return [
        'last' => $samples === [] ? null : (int)$samples[0],
        'median' => count($samples) >= 3 ? bakery_pmd_median($samples) : null,
        'samples' => count($samples),
    ];
}

/**
 * @param list<int> $productIds
 * @return array<int, array{product_line_name:string,product_line_id:?int,dough_type_id:?int}>
 */
function bakery_pmd_product_line_names(PDO $db, array $productIds): array
{
    static $cache = [];
    $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
    $missing = [];
    foreach ($productIds as $id) {
        if (!array_key_exists($id, $cache)) {
            $missing[] = $id;
        }
    }
    if ($missing !== []) {
        $placeholders = implode(',', array_fill(0, count($missing), '?'));
        $stmt = $db->prepare(
            "SELECT p.id, pl.id AS product_line_id, pl.name AS product_line_name, p.dough_type_id
             FROM products p
             LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
             LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
             WHERE p.id IN ({$placeholders})"
        );
        $stmt->execute($missing);
        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[(int)$row['id']] = $row;
        }
        foreach ($missing as $id) {
            $row = $found[$id] ?? null;
            $lineId = (int)($row['product_line_id'] ?? 0);
            $doughId = (int)($row['dough_type_id'] ?? 0);
            $cache[$id] = [
                'product_line_name' => (string)($row['product_line_name'] ?? ''),
                'product_line_id' => $lineId > 0 ? $lineId : null,
                'dough_type_id' => $doughId > 0 ? $doughId : null,
            ];
        }
    }
    $out = [];
    foreach ($productIds as $id) {
        $out[$id] = $cache[$id];
    }
    return $out;
}

/**
 * Previous same weekdays of operating demand, newest first.
 * A sample is one bake day's cover window. Dated beats standing inside each cover date.
 *
 * @return list<array{date:string,by_product:array<int,int>}>
 */
function bakery_pmd_bake_day_history(PDO $db, string $bakeDate, int $weeks = 8): array
{
    if (!function_exists('bakery_operating_demand_by_product')) {
        require_once __DIR__ . '/demand_review.php';
    }
    if (!function_exists('bakery_production_cadence_family')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    $weeks = max(0, min(8, $weeks));
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $demandCache = [];
    $demandFor = static function (string $date) use ($db, &$demandCache): array {
        if (!isset($demandCache[$date])) {
            $built = bakery_operating_demand_by_product($db, $date);
            $byProduct = is_array($built['by_product'] ?? null) ? $built['by_product'] : [];
            $demandCache[$date] = $byProduct;
        }
        return $demandCache[$date];
    };
    $history = [];
    $lineNames = [];
    for ($i = 1; $i <= $weeks; $i++) {
        $past = date('Y-m-d', strtotime($bakeDate . ' -' . (7 * $i) . ' days'));
        $coversByFamily = [];
        $seenProducts = [];
        foreach (bakery_pmd_bake_families($past) as $family) {
            $coversByFamily[$family['family']] = $family['cover_dates'];
            foreach ($family['cover_dates'] as $cover) {
                foreach ($demandFor($cover) as $pid => $qty) {
                    if ((int)$qty > 0) {
                        $seenProducts[(int)$pid] = true;
                    }
                }
            }
        }
        $ids = array_keys($seenProducts);
        $unknown = [];
        foreach ($ids as $pid) {
            if (!isset($lineNames[$pid])) {
                $unknown[] = $pid;
            }
        }
        if ($unknown !== []) {
            $lineNames += bakery_pmd_product_line_names($db, $unknown);
        }
        $byProduct = [];
        foreach ($ids as $pid) {
            $family = bakery_production_cadence_family($lineNames[$pid]['product_line_name'] ?? '');
            $qty = 0;
            foreach ($coversByFamily[$family] ?? [] as $cover) {
                $qty += (int)($demandFor($cover)[$pid] ?? 0);
            }
            if ($qty > 0) {
                $byProduct[$pid] = $qty;
            }
        }
        $history[] = [
            'date' => $past,
            'by_product' => $byProduct,
        ];
    }
    return $history;
}

/**
 * @param list<array<string,mixed>> $doughs
 * @return list<array<string,mixed>>
 */
function bakery_pmd_attach_formulas(PDO $db, array $doughs): array
{
    if ($doughs === [] || !function_exists('table_exists') || !table_exists($db, 'formula_ingredients')) {
        return $doughs;
    }
    if (!function_exists('bakery_formula_structure_load_map')) {
        require_once __DIR__ . '/formula_structure.php';
    }
    $doughIds = [];
    $productIds = [];
    foreach ($doughs as $dough) {
        $doughId = (int)($dough['dough_type_id'] ?? 0);
        if ($doughId > 0) {
            $doughIds[] = $doughId;
        }
        foreach ($dough['products'] ?? [] as $product) {
            $productIds[] = (int)($product['product_id'] ?? 0);
        }
    }
    $doughIds = array_values(array_unique(array_filter($doughIds)));
    if ($doughIds === []) {
        return $doughs;
    }
    $placeholders = implode(',', array_fill(0, count($doughIds), '?'));
    $percentStmt = $db->prepare(
        "SELECT dough_type_id, SUM(percentage) AS total_percentage
         FROM formula_ingredients
         WHERE dough_type_id IN ({$placeholders})
         GROUP BY dough_type_id"
    );
    $percentStmt->execute($doughIds);
    $percentages = $percentStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $ingredientStmt = $db->prepare(
        "SELECT fi.dough_type_id, i.id AS ingredient_id, i.name, i.unit, fi.percentage
         FROM formula_ingredients fi
         JOIN ingredients i ON i.id = fi.ingredient_id
         WHERE fi.dough_type_id IN ({$placeholders})
         ORDER BY fi.dough_type_id, fi.percentage DESC"
    );
    $ingredientStmt->execute($doughIds);
    $ingredientsByDough = [];
    foreach ($ingredientStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ingredientsByDough[(int)$row['dough_type_id']][] = $row;
    }
    $structures = bakery_formula_structure_load_map($db, $doughIds);
    $partsByProduct = bakery_formula_parts_load_map($db, $productIds);
    $catalog = function_exists('bakery_formula_subformula_catalog')
        ? bakery_formula_subformula_catalog($db)
        : [];
    foreach ($doughs as &$dough) {
        $doughId = (int)($dough['dough_type_id'] ?? 0);
        $totalPct = (float)($percentages[$doughId] ?? 0);
        $ingredients = $ingredientsByDough[$doughId] ?? [];
        if ($doughId <= 0 || $totalPct <= 0 || $ingredients === []) {
            $dough['formula'] = null;
            continue;
        }
        $structure = $structures[$doughId] ?? (function_exists('bakery_formula_normalize_structure')
            ? bakery_formula_normalize_structure([])
            : null);
        if ($catalog !== [] && function_exists('bakery_formula_decorate_subformulas')) {
            $ingredients = bakery_formula_decorate_subformulas($ingredients, $catalog);
        }
        $lossRows = [];
        foreach ($dough['products'] as $product) {
            $lossRows[] = [
                'weight_grams' => $product['weight_grams'] ?? null,
                'quantity' => (int)($product['bake_quantity'] ?? 0),
            ];
        }
        $loss = function_exists('bakery_formula_mix_loss_grams')
            ? (float)bakery_formula_mix_loss_grams(is_array($structure) ? $structure : [], $lossRows)
            : 0.0;
        $doughGrams = (float)($dough['dough_grams'] ?? 0) + $loss;
        $flour = $doughGrams / ($totalPct / 100);
        $addProducts = [];
        foreach ($dough['products'] as $product) {
            $product['quantity'] = (int)($product['bake_quantity'] ?? 0);
            $addProducts[] = $product;
        }
        $addIns = function_exists('bakery_formula_mix_add_in_rows')
            ? bakery_formula_mix_add_in_rows($addProducts, $partsByProduct)
            : [];
        $dough['formula'] = [
            'total_percentage' => $totalPct,
            'ingredients' => $ingredients,
            'structure' => $structure,
            'add_in_rows' => $addIns,
            'total_flour_grams' => $flour,
            'total_dough_grams' => $doughGrams,
            'loss_grams' => $loss,
        ];
    }
    unset($dough);
    return $doughs;
}

/**
 * Pieces mixed on a bake day, split by family. Uses the bake list (committed snapshot or demand).
 *
 * @return array{pieces:int,by_family:array<string,int>,family_active:array<string,bool>}
 */
function bakery_pmd_bake_day_family_pieces(PDO $db, string $bakeDate): array
{
    if (!function_exists('bakery_production_bake_list')) {
        require_once __DIR__ . '/production_plan.php';
    }
    if (!function_exists('bakery_production_cadence_family')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    $byFamily = [
        BAKERY_PRODUCTION_CADENCE_DAILY => 0,
        BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR => 0,
    ];
    $active = [
        BAKERY_PRODUCTION_CADENCE_DAILY => false,
        BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR => false,
    ];
    $lineCache = [];
    foreach (bakery_pmd_bake_families($bakeDate) as $family) {
        $active[$family['family']] = $family['active'];
        if ($family['cover_dates'] === []) {
            continue;
        }
        foreach ($family['cover_dates'] as $cover) {
            $list = bakery_production_bake_list($db, $cover);
            $ids = [];
            foreach ($list['items'] as $item) {
                $ids[] = (int)$item['product_id'];
            }
            $missing = [];
            foreach ($ids as $pid) {
                if (!isset($lineCache[$pid])) {
                    $missing[] = $pid;
                }
            }
            if ($missing !== []) {
                $lineCache += bakery_pmd_product_line_names($db, $missing);
            }
            foreach ($list['items'] as $item) {
                $pid = (int)$item['product_id'];
                $lineFamily = bakery_production_cadence_family($lineCache[$pid]['product_line_name'] ?? '');
                if ($lineFamily !== $family['family']) {
                    continue;
                }
                $byFamily[$family['family']] += (int)$item['bake_quantity'];
            }
        }
    }
    return [
        'pieces' => $byFamily[BAKERY_PRODUCTION_CADENCE_DAILY] + $byFamily[BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR],
        'by_family' => $byFamily,
        'family_active' => $active,
    ];
}

/**
 * Monday-Sunday kitchen week around a bake day.
 *
 * @return array{bake_date:string,days:list<array<string,mixed>>}
 */
function bakery_pmd_bake_week(PDO $db, string $bakeDate): array
{
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $anchor = new DateTimeImmutable($bakeDate);
    $monday = $anchor->modify('-' . ((int)$anchor->format('N') - 1) . ' days');
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $day = $monday->modify('+' . $i . ' days')->format('Y-m-d');
        $pieces = bakery_pmd_bake_day_family_pieces($db, $day);
        $crew = function_exists('bakery_production_crew_for_day')
            ? bakery_production_crew_for_day($db, $day)
            : ['people' => []];
        $days[] = [
            'date' => $day,
            'label' => date('D n/j', strtotime($day)),
            'is_selected' => $day === $bakeDate,
            'pieces' => (int)$pieces['pieces'],
            'by_family' => $pieces['by_family'],
            'family_active' => $pieces['family_active'],
            'crew' => is_array($crew['people'] ?? null) ? $crew['people'] : [],
        ];
    }
    return [
        'bake_date' => $bakeDate,
        'days' => $days,
    ];
}

/**
 * What the kitchen mixes on $bakeDate, summed across the delivery dates that run covers.
 *
 * @param array{source?:string,entered_date?:string,usual_weeks?:int} $options
 * @return array<string,mixed>
 */
function bakery_pmd_bake_day(PDO $db, string $bakeDate, array $options = []): array
{
    if (!function_exists('bakery_production_cadence_family')) {
        require_once __DIR__ . '/production_cadence.php';
    }
    if (!function_exists('bakery_operating_demand_lines')) {
        require_once __DIR__ . '/demand_review.php';
    }
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $sourceMode = bakery_pmd_resolve_source((string)($options['source'] ?? 'bake_list'));
    $usualWeeks = max(0, min(8, (int)($options['usual_weeks'] ?? 1)));
    $families = bakery_pmd_bake_families($bakeDate);
    $coversByFamily = [];
    $coverDates = [];
    $anyActive = false;
    foreach ($families as $family) {
        $coversByFamily[$family['family']] = $family['cover_dates'];
        if ($family['active']) {
            $anyActive = true;
        }
        foreach ($family['cover_dates'] as $date) {
            $coverDates[$date] = true;
        }
    }
    $coverDates = array_keys($coverDates);
    sort($coverDates);

    $boards = [];
    foreach ($coverDates as $cover) {
        $child = ['source' => 'bake_list'];
        if ($sourceMode === 'last_entered') {
            $child = ['source' => 'last_entered', 'entered_date' => $cover];
        }
        $boards[$cover] = bakery_pmd_build($db, $cover, $child);
    }

    $destinations = [];
    foreach ($coverDates as $cover) {
        foreach (bakery_operating_demand_lines($db, $cover) as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            if ($pid <= 0 || (int)($line['quantity'] ?? 0) <= 0) {
                continue;
            }
            $destinations[$pid][] = [
                'date' => $cover,
                'customer_id' => (int)($line['customer_id'] ?? 0),
                'customer_name' => (string)($line['customer_name'] ?? ''),
                'zone' => (string)($line['customer_zone'] ?? ''),
                'quantity' => (int)$line['quantity'],
                'source' => (string)($line['source'] ?? 'standing'),
                'leads' => [],
            ];
        }
    }

    $merged = [];
    foreach ($boards as $cover => $board) {
        foreach ($board['doughs'] as $dough) {
            $family = bakery_production_cadence_family((string)($dough['product_line_name'] ?? ''));
            if (!in_array($cover, $coversByFamily[$family] ?? [], true)) {
                continue;
            }
            $key = (int)($dough['dough_type_id'] ?? 0);
            if (!isset($merged[$key])) {
                $merged[$key] = $dough;
                $merged[$key]['family'] = $family;
                $merged[$key]['cover_dates'] = $coversByFamily[$family];
                $merged[$key]['products'] = [];
                $merged[$key]['leads'] = [];
            }
            foreach ($dough['products'] as $product) {
                $pid = (int)$product['product_id'];
                $slice = [
                    'date' => $cover,
                    'bake_quantity' => (int)$product['bake_quantity'],
                    'demand_quantity' => (int)$product['demand_quantity'],
                    'committed' => !empty($board['committed']),
                    'source' => (string)$product['source'],
                ];
                if (!isset($merged[$key]['products'][$pid])) {
                    $product['deliveries'] = [$slice];
                    $product['destinations'] = [];
                    $merged[$key]['products'][$pid] = $product;
                } else {
                    foreach (['bake_quantity', 'demand_quantity', 'planned_quantity', 'produced_quantity', 'dough_grams'] as $field) {
                        $merged[$key]['products'][$pid][$field] = (int)$merged[$key]['products'][$pid][$field] + (int)($product[$field] ?? 0);
                    }
                    $merged[$key]['products'][$pid]['deliveries'][] = $slice;
                }
            }
        }
    }

    $history = $usualWeeks > 0 ? bakery_pmd_bake_day_history($db, $bakeDate, $usualWeeks) : [];
    $doughs = [];
    $sumPieces = 0;
    $sumDemand = 0;
    $sumPlanned = 0;
    $sumProduced = 0;
    $sumDoughGrams = 0;
    $sumUsual = 0;
    $driftCount = 0;
    $productCount = 0;
    $anyCommit = false;
    $anyOpen = false;
    $anyEntered = false;
    foreach ($coverDates as $cover) {
        $board = $boards[$cover];
        if (!empty($board['committed'])) {
            $anyCommit = true;
        } else {
            $anyOpen = true;
        }
        if (($board['bake_source'] ?? '') === 'last_entered') {
            $anyEntered = true;
        }
        $driftCount += (int)($board['changed_since']['count'] ?? 0);
    }
    if ($coverDates === []) {
        $anyOpen = true;
    }

    foreach ($merged as $bucket) {
        $pieces = 0;
        $demand = 0;
        $produced = 0;
        $doughGrams = 0;
        $products = [];
        $series = array_fill(0, count($history), 0);
        foreach ($bucket['products'] as $product) {
            $pid = (int)$product['product_id'];
            $productSeries = [];
            foreach ($history as $index => $sample) {
                $qty = (int)($sample['by_product'][$pid] ?? 0);
                $productSeries[] = $qty;
                $series[$index] += $qty;
            }
            $usual = bakery_pmd_usual_summary($productSeries);
            $bakeQty = (int)$product['bake_quantity'];
            $weight = (int)($product['weight_grams'] ?? 0);
            $doughGramsProduct = ($bakeQty > 0 && $weight > 0) ? $bakeQty * $weight : (int)$product['dough_grams'];
            $producedQty = (int)$product['produced_quantity'];
            $product['dough_grams'] = $doughGramsProduct;
            $product['dough_weight'] = bakery_pmd_format_dough_weight($doughGramsProduct);
            $product['left'] = max(0, $bakeQty - $producedQty);
            $product['usual_last'] = (int)($usual['last'] ?? 0);
            $product['usual_median'] = $usual['median'];
            $product['prior_bake_quantity'] = $product['usual_last'];
            $product['delta_vs_prior'] = $bakeQty - $product['usual_last'];
            $product['batch_label'] = bakery_pack_batch_label($db, $pid, $bakeQty);
            $scale = bakery_pack_input_scale($db, $pid);
            $product['batch_unit'] = $scale['unit'];
            $product['pcs_per_batch_unit'] = $scale['pcs_per'];
            $product['batch_quantity'] = ($bakeQty > 0 && $scale['pcs_per'] > 0 && $scale['unit'] !== 'piece')
                ? round($bakeQty / $scale['pcs_per'], 3)
                : null;
            $kept = [];
            foreach ($product['deliveries'] as $delivery) {
                if (in_array($delivery['date'], $bucket['cover_dates'], true)) {
                    $kept[] = $delivery;
                }
            }
            $product['deliveries'] = $kept;
            $where = [];
            foreach ($destinations[$pid] ?? [] as $dest) {
                if (in_array($dest['date'], $bucket['cover_dates'], true)) {
                    $where[] = $dest;
                }
            }
            usort($where, static function (array $a, array $b): int {
                return [$a['date'], $a['customer_name']] <=> [$b['date'], $b['customer_name']];
            });
            $product['destinations'] = $where;
            $products[] = $product;
            $pieces += $bakeQty;
            $demand += (int)$product['demand_quantity'];
            $sumPlanned += (int)($product['planned_quantity'] ?? 0);
            $produced += $producedQty;
            $doughGrams += $doughGramsProduct;
        }
        usort($products, static function (array $a, array $b): int {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
        $doughUsual = bakery_pmd_usual_summary($series);
        $doughTypeId = $bucket['dough_type_id'];
        $doughYield = $doughTypeId ? bakery_pack_dough_yield($db, (int)$doughTypeId) : null;
        $batch = bakery_pmd_dough_batch($doughYield, $pieces);
        $standard = bakery_pmd_standard_batches(
            !empty($bucket['standard_batches']['standard_batch_grams'])
                ? (int)$bucket['standard_batches']['standard_batch_grams']
                : null,
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
        $usualLast = (int)($doughUsual['last'] ?? 0);
        $doughs[] = [
            'dough_type_id' => $doughTypeId,
            'dough_type_name' => $bucket['dough_type_name'],
            'product_line_id' => $bucket['product_line_id'] ?? null,
            'product_line_name' => $bucket['product_line_name'],
            'family' => $bucket['family'],
            'cover_dates' => $bucket['cover_dates'],
            'leads' => [],
            'product_count' => count($products),
            'pieces' => $pieces,
            'demand_pieces' => $demand,
            'produced_pieces' => $produced,
            'left_pieces' => max(0, $pieces - $produced),
            'prior_pieces' => $usualLast,
            'usual_last' => $usualLast,
            'usual_median' => $doughUsual['median'],
            'delta_vs_prior' => $pieces - $usualLast,
            'dough_grams' => $doughGrams,
            'dough_weight' => bakery_pmd_format_dough_weight($doughGrams),
            'batch' => $batch,
            'standard_batches' => $standard,
            'statuses' => $statuses,
            'products' => $products,
            'links' => bakery_pmd_links($bucket['cover_dates'][0] ?? $bakeDate),
        ];
        $sumPieces += $pieces;
        $sumDemand += $demand;
        $sumProduced += $produced;
        $sumDoughGrams += $doughGrams;
        $sumUsual += $usualLast;
        $productCount += count($products);
    }

    usort($doughs, static function (array $a, array $b): int {
        return $b['pieces'] <=> $a['pieces'] ?: strcasecmp((string)$a['dough_type_name'], (string)$b['dough_type_name']);
    });
    $doughs = bakery_pmd_attach_formulas($db, $doughs);

    if ($anyEntered) {
        $bakeSource = 'last_entered';
    } elseif ($anyCommit && !$anyOpen) {
        $bakeSource = 'committed_plan';
    } elseif ($anyCommit && $anyOpen) {
        $bakeSource = 'mixed';
    } else {
        $bakeSource = 'demand';
    }
    $linkDate = $bakeDate;
    foreach ($families as $family) {
        if ($family['family'] === BAKERY_PRODUCTION_CADENCE_DAILY && $family['cover_dates'] !== []) {
            $linkDate = $family['cover_dates'][0];
            break;
        }
    }
    if ($linkDate === $bakeDate) {
        foreach ($families as $family) {
            if ($family['cover_dates'] !== []) {
                $linkDate = $family['cover_dates'][0];
                break;
            }
        }
    }

    return [
        'date' => $bakeDate,
        'bake_date' => $bakeDate,
        'prior_date' => date('Y-m-d', strtotime($bakeDate . ' -7 days')),
        'date_display' => date('l, F j, Y', strtotime($bakeDate)),
        'quantity_date' => $bakeDate,
        'quantity_date_display' => date('l, F j, Y', strtotime($bakeDate)),
        'date_mismatch' => false,
        'cover_dates' => $coverDates,
        'cover_label' => bakery_pmd_bake_cover_label($bakeDate),
        'families' => $families,
        'any_family_active' => $anyActive,
        'committed' => $bakeSource === 'committed_plan',
        'bake_source' => $bakeSource,
        'entered_plan' => null,
        'has_daily' => false,
        'inventory_ready' => $boards === [] ? false : !empty(reset($boards)['inventory_ready']),
        'commit' => null,
        'changed_since' => ['count' => $driftCount, 'latest' => null, 'examples' => []],
        'summary' => [
            'dough_types' => count($doughs),
            'products' => $productCount,
            'pieces' => $sumPieces,
            'demand_pieces' => $sumDemand,
            'planned_pieces' => $sumPlanned,
            'produced_pieces' => $sumProduced,
            'dough_grams' => $sumDoughGrams,
            'dough_weight' => bakery_pmd_format_dough_weight($sumDoughGrams),
            'prior_pieces' => $sumUsual,
            'delta_vs_prior' => $sumPieces - $sumUsual,
        ],
        'doughs' => $doughs,
        'links' => bakery_pmd_links($linkDate),
    ];
}
