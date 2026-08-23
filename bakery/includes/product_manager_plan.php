<?php
/**
 * Product Manager Plan Center — board: standards × standing × cover demand × FG stock.
 *
 * Read-only planning lens. Commit / autosave stay on Production Center.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/production_cadence.php';
require_once __DIR__ . '/product_inventory.php';
require_once __DIR__ . '/product_pack_yields.php';

/**
 * Normalize Y-m-d focus date.
 */
function bakery_product_manager_plan_resolve_date(?string $input, ?string $today = null): string
{
    $today = $today ?: date('Y-m-d');
    $input = trim((string)$input);
    if ($input !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
        $dt = DateTime::createFromFormat('!Y-m-d', $input);
        if ($dt && $dt->format('Y-m-d') === $input) {
            return $input;
        }
    }
    return $today;
}

/**
 * Cadence family filter: all | daily (Pan Dulce/Traditional) | sour_flour.
 */
function bakery_product_manager_plan_normalize_family(?string $family): string
{
    $family = strtolower(trim((string)$family));
    if ($family === '' || $family === 'all') {
        return 'all';
    }
    if ($family === BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR || $family === 'sour_flour' || $family === 'sf') {
        return BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR;
    }
    return BAKERY_PRODUCTION_CADENCE_DAILY;
}

/**
 * Standing order totals by product for a calendar weekday (1=Mon..7=Sun).
 *
 * @return array<int,int>
 */
function bakery_product_manager_plan_standing_by_product(PDO $db, int $weekday): array
{
    if ($weekday < 1 || $weekday > 7 || !table_exists($db, 'standing_orders')) {
        return [];
    }
    $stmt = $db->prepare('
        SELECT product_id, SUM(quantity) AS qty
        FROM standing_orders
        WHERE day_of_week = ?
        GROUP BY product_id
    ');
    $stmt->execute([$weekday]);
    $out = [];
    foreach ($stmt as $row) {
        $out[(int)$row['product_id']] = (int)$row['qty'];
    }
    return $out;
}

/**
 * Pan Dulce 1× suite standards by product_id.
 *
 * @return array<int,int>
 */
function bakery_product_manager_plan_standards_by_product(PDO $db): array
{
    if (!table_exists($db, 'pan_dulce_product_quantity_standards')) {
        return [];
    }
    $rows = $db->query('SELECT product_id, standard_quantity FROM pan_dulce_product_quantity_standards');
    $out = [];
    foreach ($rows as $row) {
        $out[(int)$row['product_id']] = (int)$row['standard_quantity'];
    }
    return $out;
}

/**
 * FG on-hand (available + loaded) keyed by product for a delivery date.
 *
 * @return array<int,array{available:int,loaded:int,produced:int,on_hand:int}>
 */
function bakery_product_manager_plan_inventory_by_product(PDO $db, string $date): array
{
    if (!bakery_inventory_ready($db)) {
        return [];
    }
    $stmt = $db->prepare('
        SELECT product_id, available_quantity, loaded_quantity, produced_quantity
        FROM product_inventory_days
        WHERE delivery_date = ?
    ');
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt as $row) {
        $avail = (int)$row['available_quantity'];
        $loaded = (int)$row['loaded_quantity'];
        $out[(int)$row['product_id']] = [
            'available' => $avail,
            'loaded' => $loaded,
            'produced' => (int)$row['produced_quantity'],
            'on_hand' => $avail + $loaded,
        ];
    }
    return $out;
}

/**
 * Saved plan quantities by product for a delivery date.
 *
 * @return array<int,int>
 */
function bakery_product_manager_plan_planned_by_product(PDO $db, string $date): array
{
    if (!table_exists($db, 'production_plan_items')) {
        return [];
    }
    $stmt = $db->prepare('
        SELECT product_id, planned_quantity
        FROM production_plan_items
        WHERE delivery_date = ?
    ');
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt as $row) {
        $out[(int)$row['product_id']] = (int)$row['planned_quantity'];
    }
    return $out;
}

/**
 * Build the Product Manager Plan Center board for a delivery focus date.
 *
 * Uses cadence: bake day that covers this delivery, then sums operating demand
 * across the full cover window. On-hand / planned are for the focus delivery date.
 *
 * @return array<string,mixed>
 */
function bakery_product_manager_plan_board(PDO $db, string $deliveryDate, string $familyFilter = 'all'): array
{
    $deliveryDate = bakery_product_manager_plan_resolve_date($deliveryDate);
    $familyFilter = bakery_product_manager_plan_normalize_family($familyFilter);

    $legs = bakery_production_cadence_delivery_legs($deliveryDate);
    $primaryFamily = $familyFilter === 'all'
        ? BAKERY_PRODUCTION_CADENCE_DAILY
        : $familyFilter;

    $bakeDate = bakery_production_cadence_bake_date_for_delivery($primaryFamily, $deliveryDate);
    if ($bakeDate === null) {
        $bakeDate = $deliveryDate;
    }
    $coverDates = bakery_production_cadence_cover_dates($primaryFamily, $bakeDate);
    if ($coverDates === []) {
        $coverDates = [$deliveryDate];
    }
    if (!in_array($deliveryDate, $coverDates, true)) {
        array_unshift($coverDates, $deliveryDate);
        $coverDates = array_values(array_unique($coverDates));
        sort($coverDates);
    }

    $demandByDate = [];
    $demandTotalByProduct = [];
    $hasDailyAny = false;
    foreach ($coverDates as $d) {
        $op = bakery_operating_demand_by_product($db, $d);
        $demandByDate[$d] = $op;
        if (!empty($op['has_daily'])) {
            $hasDailyAny = true;
        }
        foreach ($op['by_product'] as $pid => $qty) {
            $pid = (int)$pid;
            $demandTotalByProduct[$pid] = ($demandTotalByProduct[$pid] ?? 0) + (int)$qty;
        }
    }

    $weekday = bakery_production_cadence_weekday($deliveryDate);
    $standing = bakery_product_manager_plan_standing_by_product($db, $weekday);
    $standards = bakery_product_manager_plan_standards_by_product($db);
    $inventory = bakery_product_manager_plan_inventory_by_product($db, $deliveryDate);
    $planned = bakery_product_manager_plan_planned_by_product($db, $deliveryDate);
    $inventoryReady = bakery_inventory_ready($db);
    $packReady = bakery_pack_yields_ready($db);

    $products = $db->query("
        SELECT p.id, p.name, p.dough_type_id, dt.name AS dough_type_name, pl.name AS product_line_name
        FROM products p
        LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
        LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
        ORDER BY pl.name, dt.name, p.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];
    $summary = [
        'products_shown' => 0,
        'cover_demand' => 0,
        'focus_demand' => 0,
        'standing' => 0,
        'on_hand' => 0,
        'planned' => 0,
        'shortfall' => 0,
        'make_need' => 0,
        'attention' => 0,
    ];

    foreach ($products as $p) {
        $pid = (int)$p['id'];
        $line = (string)($p['product_line_name'] ?? '');
        $family = bakery_production_cadence_family($line !== '' ? $line : null);
        if ($familyFilter !== 'all' && $family !== $familyFilter) {
            continue;
        }

        $coverDemand = (int)($demandTotalByProduct[$pid] ?? 0);
        $focusDemand = (int)(($demandByDate[$deliveryDate]['by_product'][$pid] ?? 0));
        $standingQty = (int)($standing[$pid] ?? 0);
        $standardQty = (int)($standards[$pid] ?? 0);
        $inv = $inventory[$pid] ?? null;
        $onHand = $inv ? (int)$inv['on_hand'] : 0;
        $produced = $inv ? (int)$inv['produced'] : 0;
        $planQty = (int)($planned[$pid] ?? 0);
        $hasPlan = array_key_exists($pid, $planned);

        $perDate = [];
        foreach ($coverDates as $d) {
            $perDate[$d] = (int)(($demandByDate[$d]['by_product'][$pid] ?? 0));
        }

        // Skip empty rows unless show-all handled by caller; default hide quiet rows.
        if ($coverDemand === 0 && $standingQty === 0 && $standardQty === 0 && $onHand === 0 && $planQty === 0 && $produced === 0) {
            continue;
        }

        $shortfall = $inventoryReady ? max(0, $focusDemand - $onHand) : 0;
        $makeNeed = $inventoryReady ? max(0, max($focusDemand, $planQty) - $onHand) : max(0, $focusDemand);
        $afterDelivery = $inventoryReady ? ($onHand - $focusDemand) : null;

        $gallonsHint = null;
        $inputUnit = null;
        if ($packReady) {
            $yield = bakery_pack_product_yield($db, $pid);
            if ($yield) {
                $inputUnit = (string)$yield['input_unit'];
                if ($inputUnit === 'gallon' && $makeNeed > 0) {
                    $perGal = bakery_pack_pieces_per_gallon($db, $pid);
                    if ($perGal && $perGal > 0) {
                        $gallonsHint = round($makeNeed / $perGal, 2);
                    }
                } elseif ($inputUnit === 'tray' && $makeNeed > 0) {
                    $perTray = (float)($yield['pieces_per_input'] ?? $yield['pieces_per_tray'] ?? 20);
                    if ($perTray > 0) {
                        $gallonsHint = null; // reuse field as trays_hint below
                    }
                }
            }
        }

        $traysHint = null;
        if ($packReady && $inputUnit === 'tray' && $makeNeed > 0) {
            $yield = bakery_pack_product_yield($db, $pid);
            $perTray = (float)($yield['pieces_per_input'] ?? $yield['pieces_per_tray'] ?? 20);
            if ($perTray > 0) {
                $traysHint = round($makeNeed / $perTray, 2);
            }
        }

        $statuses = [];
        if ($inventoryReady && $shortfall > 0) {
            $statuses[] = ['code' => 'short', 'label' => $shortfall . ' short', 'tone' => 'danger'];
        }
        if ($inventoryReady && $makeNeed > 0) {
            $statuses[] = ['code' => 'make', 'label' => $makeNeed . ' to make', 'tone' => 'warn'];
        }
        if ($hasPlan && $planQty < $focusDemand) {
            $statuses[] = ['code' => 'plan_below', 'label' => 'Plan below demand', 'tone' => 'warn'];
        }
        if ($standardQty > 0 && $standingQty > 0 && $standingQty < $standardQty) {
            $statuses[] = ['code' => 'standing_below_std', 'label' => 'Standing below 1× suite', 'tone' => 'muted'];
        }
        if ($statuses === [] && $focusDemand > 0 && $shortfall === 0) {
            $statuses[] = ['code' => 'ok', 'label' => 'Covered', 'tone' => 'ok'];
        }

        $attention = false;
        foreach ($statuses as $st) {
            if (in_array($st['tone'], ['danger', 'warn'], true)) {
                $attention = true;
                break;
            }
        }

        $rows[] = [
            'product_id' => $pid,
            'name' => (string)$p['name'],
            'dough_type_name' => (string)($p['dough_type_name'] ?? ''),
            'product_line_name' => $line,
            'cadence_family' => $family,
            'standard_quantity' => $standardQty,
            'standing_quantity' => $standingQty,
            'focus_demand' => $focusDemand,
            'cover_demand' => $coverDemand,
            'demand_by_date' => $perDate,
            'on_hand' => $onHand,
            'produced' => $produced,
            'planned' => $planQty,
            'has_plan' => $hasPlan,
            'shortfall' => $shortfall,
            'make_need' => $makeNeed,
            'after_delivery' => $afterDelivery,
            'input_unit' => $inputUnit,
            'gallons_hint' => $gallonsHint,
            'trays_hint' => $traysHint,
            'statuses' => $statuses,
            'attention' => $attention,
        ];

        $summary['products_shown']++;
        $summary['cover_demand'] += $coverDemand;
        $summary['focus_demand'] += $focusDemand;
        $summary['standing'] += $standingQty;
        $summary['on_hand'] += $onHand;
        $summary['planned'] += $planQty;
        $summary['shortfall'] += $shortfall;
        $summary['make_need'] += $makeNeed;
        if ($attention) {
            $summary['attention']++;
        }
    }

    return [
        'delivery_date' => $deliveryDate,
        'bake_date' => $bakeDate,
        'cover_dates' => $coverDates,
        'family_filter' => $familyFilter,
        'primary_family' => $primaryFamily,
        'has_daily_orders' => $hasDailyAny,
        'inventory_ready' => $inventoryReady,
        'pack_ready' => $packReady,
        'legs' => $legs,
        'rows' => $rows,
        'summary' => $summary,
    ];
}
