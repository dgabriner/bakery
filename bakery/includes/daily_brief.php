<?php
/**
 * Daily Bakery Brief — deterministic shift handoff payload.
 *
 * Reuses dashboard command center and demand review; does not duplicate their SQL.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/dashboard_command_center.php';
require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/operational_exceptions.php';

/** Minimum absolute unit change to surface in the brief. */
define('BAKERY_BRIEF_MIN_UNIT_DELTA', 3);

/** Minimum relative change (0–1) when standing qty > 0. */
define('BAKERY_BRIEF_MIN_RELATIVE_DELTA', 0.20);

/**
 * Build the full daily brief for one operating date.
 *
 * @param array{role?:string} $options
 * @return array<string, mixed>
 */
function bakery_daily_brief_build(PDO $db, string $date, array $options = []): array
{
    $today = date('Y-m-d');
    $now = date('g:i A');
    $role = (string)($options['role'] ?? 'manager');
    $weekday = bakery_standing_day_from_date($date);
    $dayNames = bakery_day_names();
    $dayLabel = $dayNames[$weekday] ?? date('l', strtotime($date));

    $commandCenter = bakery_dashboard_command_center($db, $date);
    $links = $commandCenter['links'];
    $links['daily_brief'] = (defined('BASE_URL') ? BASE_URL : '') . 'daily_brief.php?date=' . rawurlencode($date);
    $links['daily_run'] = (defined('BASE_URL') ? BASE_URL : '') . 'daily_run.php?date=' . rawurlencode($date);
    $links['ingredient_requirements'] = (defined('BASE_URL') ? BASE_URL : '')
        . 'ingredient_requirements.php?date=' . rawurlencode($date) . '&source=demand';

    $dailyRun = null;
    if (is_file(__DIR__ . '/daily_run.php')) {
        require_once __DIR__ . '/daily_run.php';
        try {
            $dailyRun = bakery_daily_run_build($db, $date);
            if (!empty($dailyRun['links']['daily_run'])) {
                $links['daily_run'] = $dailyRun['links']['daily_run'];
            }
        } catch (Throwable $e) {
            error_log('daily brief daily run: ' . $e->getMessage());
        }
    }

    $demandReview = null;
    $demandError = null;
    try {
        $demandReview = bakery_demand_review_build($db, $date);
    } catch (Throwable $e) {
        error_log('daily brief demand review: ' . $e->getMessage());
        $demandError = bakery_dashboard_safe_error_message($e);
    }

    $mode = bakery_daily_brief_resolve_mode($commandCenter, $date, $today, $dailyRun);
    $runStatus = bakery_daily_brief_run_status($commandCenter, $dailyRun);

    $scale = bakery_daily_brief_scale($commandCenter, $demandReview);
    $importantChanges = bakery_daily_brief_important_changes($db, $date, $demandReview);
    $production = bakery_daily_brief_production($db, $date, $commandCenter);
    $customerNotes = bakery_daily_brief_customer_notes($db, $date);
    $drivers = bakery_daily_brief_drivers($db, $date, $commandCenter);
    $ingredientAlerts = bakery_daily_brief_ingredient_alerts($db, $date);
    $handoff = $mode === 'handoff'
        ? bakery_daily_brief_handoff($commandCenter, $production, $drivers, $db, $date, $dailyRun)
        : null;

    $dateRelation = 'other';
    if ($date === $today) {
        $dateRelation = 'today';
    } elseif ($date === date('Y-m-d', strtotime($today . ' +1 day'))) {
        $dateRelation = 'tomorrow';
    } elseif ($date < $today) {
        $dateRelation = 'past';
    } elseif ($date > $today) {
        $dateRelation = 'future';
    }

    $exceptions = bakery_ops_enrich_exceptions($commandCenter['exceptions'], $date);
    $hasDataErrors = $demandError !== null
        || !empty($commandCenter['section_errors'])
        || !empty($commandCenter['has_blocking_error']);
    $isNormalDay = !$hasDataErrors && $exceptions === [] && $importantChanges === [];

    return [
        'date' => $date,
        'weekday' => $weekday,
        'day_label' => $dayLabel,
        'date_display' => date('l, F j, Y', strtotime($date)),
        'current_time' => $now,
        'date_relation' => $dateRelation,
        'mode' => $mode,
        'mode_label' => $mode === 'handoff' ? 'Handoff / current state' : 'Start-of-day / upcoming',
        'run_status' => $runStatus,
        'scale' => $scale,
        'important_changes' => $importantChanges,
        'production' => $production,
        'customer_notes' => $customerNotes,
        'drivers' => $drivers,
        'ingredient_alerts' => $ingredientAlerts,
        'handoff' => $handoff,
        'exceptions' => $exceptions,
        'is_normal_day' => $isNormalDay,
        'links' => $links,
        'demand_error' => $demandError,
        'section_errors' => $commandCenter['section_errors'],
        'inventory_ready' => !empty($commandCenter['inventory_ready']),
        'daily_run' => $dailyRun ? [
            'is_closed' => !empty($dailyRun['is_closed']),
            'operational_complete' => !empty($dailyRun['operational_complete']),
            'progress' => $dailyRun['progress']['label'] ?? '',
            'next_action' => $dailyRun['next_action']['label'] ?? null,
        ] : null,
        'role' => $role,
        'visibility' => [
            'full' => in_array($role, ['administrator', 'manager'], true),
            'production' => in_array($role, ['administrator', 'manager', 'baker'], true),
            'delivery' => in_array($role, ['administrator', 'manager', 'driver'], true),
        ],
    ];
}

function bakery_daily_brief_resolve_mode(array $commandCenter, string $date, string $today, ?array $dailyRun = null): string
{
    if ($date > $today) {
        return 'upcoming';
    }
    if ($date < $today) {
        return 'handoff';
    }

    if ($dailyRun !== null) {
        if (!empty($dailyRun['is_closed'])) {
            return 'handoff';
        }
        $progressComplete = (int)($dailyRun['progress']['complete'] ?? 0);
        if ($progressComplete > 0) {
            return 'handoff';
        }
        foreach ($dailyRun['stages'] ?? [] as $stage) {
            if (in_array($stage['ui_state'] ?? '', ['in_progress', 'complete'], true)
                && ($stage['key'] ?? '') !== 'confirm_demand') {
                return 'handoff';
            }
        }
    }

    foreach ($commandCenter['stages'] ?? [] as $stage) {
        if (!in_array($stage['key'] ?? '', ['production', 'pack', 'load', 'delivery', 'invoice'], true)) {
            continue;
        }
        if (in_array($stage['state'] ?? '', ['attention', 'ok'], true) && ($stage['state'] ?? '') === 'ok') {
            $key = $stage['key'];
            if ($key === 'delivery') {
                $delivered = (int)($stage['metrics']['delivered']['value'] ?? 0);
                $open = (int)($stage['metrics']['pending']['value'] ?? 0)
                    + (int)($stage['metrics']['in_transit']['value'] ?? 0);
                if ($delivered > 0 || $open > 0) {
                    return 'handoff';
                }
            } elseif ($key === 'invoice') {
                $invoiced = (int)($stage['metrics']['invoiced']['value'] ?? 0);
                $deliveredOrders = (int)($stage['metrics']['delivered_orders']['value'] ?? 0);
                if ($invoiced > 0 || $deliveredOrders > 0) {
                    return 'handoff';
                }
            } elseif ($key !== 'demand' && ($stage['state'] ?? '') === 'ok') {
                $summary = strtolower((string)($stage['summary'] ?? ''));
                if ($summary !== '' && strpos($summary, 'nothing') === false && strpos($summary, 'no ') !== 0) {
                    return 'handoff';
                }
            }
        }
        if (($stage['state'] ?? '') === 'attention') {
            return 'handoff';
        }
    }

    return 'upcoming';
}

function bakery_daily_brief_run_status(array $commandCenter, ?array $dailyRun = null): array
{
    if ($dailyRun !== null) {
        $critical = 0;
        foreach ($dailyRun['blockers'] ?? [] as $b) {
            if (($b['severity'] ?? '') === 'critical') {
                $critical++;
            }
        }
        if (!empty($dailyRun['is_closed'])) {
            return [
                'label' => 'Day closed',
                'tone' => 'ok',
                'critical_count' => $critical,
                'completed_stages' => [],
                'open_stages' => [],
            ];
        }
        if ($critical > 0) {
            return [
                'label' => 'Blocked — ' . $critical . ' blocker' . ($critical === 1 ? '' : 's'),
                'tone' => 'critical',
                'critical_count' => $critical,
                'completed_stages' => [],
                'open_stages' => [],
            ];
        }
        if (!empty($dailyRun['operational_complete'])) {
            return [
                'label' => 'Ready to close',
                'tone' => 'ok',
                'critical_count' => 0,
                'completed_stages' => [],
                'open_stages' => [],
            ];
        }
        $next = $dailyRun['next_action']['label'] ?? null;
        if ($next) {
            return [
                'label' => 'Next: ' . $next,
                'tone' => 'warning',
                'critical_count' => 0,
                'completed_stages' => [],
                'open_stages' => [],
            ];
        }
        if (!empty($dailyRun['progress']['label'])) {
            return [
                'label' => $dailyRun['progress']['label'],
                'tone' => 'warning',
                'critical_count' => 0,
                'completed_stages' => [],
                'open_stages' => [],
            ];
        }
    }

    $stages = $commandCenter['stages'] ?? [];
    $exceptions = $commandCenter['exceptions'] ?? [];
    $critical = 0;
    foreach ($exceptions as $ex) {
        if (($ex['severity'] ?? '') === 'critical') {
            $critical++;
        }
    }

    $allEmpty = true;
    $allOk = true;
    foreach ($stages as $stage) {
        $state = $stage['state'] ?? 'unknown';
        if ($state !== 'empty') {
            $allEmpty = false;
        }
        if (!in_array($state, ['ok', 'empty'], true)) {
            $allOk = false;
        }
    }

    if ($allEmpty) {
        $label = 'Not started';
        $tone = 'muted';
    } elseif ($critical > 0) {
        $label = 'Blocked — ' . $critical . ' urgent item' . ($critical === 1 ? '' : 's');
        $tone = 'critical';
    } elseif (!$allOk) {
        $label = 'In progress — needs attention';
        $tone = 'warning';
    } else {
        $label = 'On track';
        $tone = 'ok';
    }

    $completedStages = [];
    $openStages = [];
    foreach ($stages as $stage) {
        if (($stage['state'] ?? '') === 'ok') {
            $completedStages[] = $stage['label'] ?? $stage['key'];
        } elseif (in_array($stage['state'] ?? '', ['attention', 'unknown'], true)) {
            $openStages[] = $stage['label'] ?? $stage['key'];
        }
    }

    return [
        'label' => $label,
        'tone' => $tone,
        'critical_count' => $critical,
        'completed_stages' => $completedStages,
        'open_stages' => $openStages,
    ];
}

function bakery_daily_brief_metric_value(array $stage, string $key): ?int
{
    if (!isset($stage['metrics'][$key])) {
        return null;
    }
    $metric = $stage['metrics'][$key];
    if (($metric['state'] ?? '') === 'unavailable') {
        return null;
    }
    return $metric['value'] === null ? null : (int)$metric['value'];
}

function bakery_daily_brief_stage_by_key(array $commandCenter, string $key): ?array
{
    foreach ($commandCenter['stages'] ?? [] as $stage) {
        if (($stage['key'] ?? '') === $key) {
            return $stage;
        }
    }
    return null;
}

function bakery_daily_brief_scale(array $commandCenter, ?array $demandReview): array
{
    $demand = bakery_daily_brief_stage_by_key($commandCenter, 'demand');
    $production = bakery_daily_brief_stage_by_key($commandCenter, 'production');
    $delivery = bakery_daily_brief_stage_by_key($commandCenter, 'delivery');
    $load = bakery_daily_brief_stage_by_key($commandCenter, 'load');

    $customers = bakery_daily_brief_metric_value($demand ?? [], 'customers');
    $committedUnits = bakery_daily_brief_metric_value($production ?? [], 'required_units');
    $productCount = null;
    if ($demandReview && !empty($demandReview['product_totals'])) {
        $productCount = count($demandReview['product_totals']);
    }

    $driversWithWork = bakery_daily_brief_metric_value($load ?? [], 'drivers_with_work');
    $delivered = bakery_daily_brief_metric_value($delivery ?? [], 'delivered');

    return [
        'customer_deliveries' => $customers,
        'committed_units' => $committedUnits,
        'products' => $productCount,
        'drivers' => $driversWithWork,
        'delivered_stops' => $delivered,
    ];
}

function bakery_daily_brief_is_meaningful_diff(?int $standing, ?int $daily): bool
{
    if ($standing === null && $daily === null) {
        return false;
    }
    if ($standing === null) {
        return $daily !== null && $daily >= BAKERY_BRIEF_MIN_UNIT_DELTA;
    }
    if ($daily === null) {
        return $standing >= BAKERY_BRIEF_MIN_UNIT_DELTA;
    }
    $delta = abs($daily - $standing);
    if ($delta === 0) {
        return false;
    }
    if ($delta >= BAKERY_BRIEF_MIN_UNIT_DELTA) {
        return true;
    }
    if ($standing > 0 && ($delta / $standing) >= BAKERY_BRIEF_MIN_RELATIVE_DELTA) {
        return true;
    }
    return false;
}

function bakery_daily_brief_format_qty_change(string $productName, ?int $standing, ?int $daily): string
{
    if ($standing !== null && $daily !== null) {
        $delta = $daily - $standing;
        if ($delta > 0) {
            return $productName . ': +' . number_format($delta) . ' (standing ' . number_format($standing) . ' → daily ' . number_format($daily) . ')';
        }
        if ($delta < 0) {
            return $productName . ': ' . number_format($delta) . ' (standing ' . number_format($standing) . ' → daily ' . number_format($daily) . ')';
        }
    }
    if ($standing === null && $daily !== null && $daily > 0) {
        return $productName . ': ' . number_format($daily) . ' added (not on standing for this weekday)';
    }
    if ($standing !== null && $daily === null && $standing > 0) {
        return $productName . ': standing ' . number_format($standing) . ' — not on dated order';
    }
    return $productName . ': changed';
}

/**
 * @return list<array{severity:string,category:string,title:string,detail:string,href?:string}>
 */
function bakery_daily_brief_important_changes(PDO $db, string $date, ?array $demandReview): array
{
    if (!$demandReview || empty($demandReview['customers'])) {
        return [];
    }

    $base = defined('BASE_URL') ? BASE_URL : '';
    $changes = [];
    $dayLabel = $demandReview['day_name'] ?? date('l', strtotime($date));
    $weekday = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($weekday);
    $standingRouteDrivers = [];
    if (table_exists($db, 'standing_routes') && table_exists($db, 'drivers')) {
        try {
            $routeStmt = $db->prepare("
                SELECT sr.customer_id, d.name AS driver_name
                FROM standing_routes sr
                JOIN drivers d ON d.id = sr.driver_id
                WHERE sr.day_of_week {$dayClause['sql']}
            ");
            $routeStmt->execute($dayClause['values']);
            foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $routeRow) {
                $standingRouteDrivers[(int)$routeRow['customer_id']] = (string)$routeRow['driver_name'];
            }
        } catch (Throwable $e) {
            error_log('daily brief standing routes: ' . $e->getMessage());
        }
    }

    foreach ($demandReview['customers'] as $customer) {
        $name = (string)($customer['customer_name'] ?? 'Customer');
        $state = (string)($customer['state'] ?? '');
        $customerHref = $base . 'customer_record.php?customer_id=' . (int)$customer['customer_id']
            . '&date=' . rawurlencode($date);

        if ($state === 'missing_daily') {
            $units = (int)($customer['standing_units'] ?? 0);
            $changes[] = [
                'severity' => 'critical',
                'category' => 'demand',
                'title' => $name . ' — no dated order',
                'detail' => 'Standing forecast expects '
                    . ($units > 0 ? number_format($units) . ' units' : 'delivery')
                    . ' on ' . $dayLabel . ', but no daily order exists yet.',
                'href' => $base . 'daily_orders.php?date=' . rawurlencode($date),
            ];
            continue;
        }

        if ($state === 'empty_daily') {
            $changes[] = [
                'severity' => 'critical',
                'category' => 'demand',
                'title' => $name . ' — empty dated order',
                'detail' => 'A daily order row exists but has no line items; standing forecast may be unfulfilled.',
                'href' => $customerHref,
            ];
            continue;
        }

        if ($state === 'one_off') {
            $units = (int)($customer['daily_units'] ?? 0);
            if ($units > 0) {
                $changes[] = [
                    'severity' => 'warning',
                    'category' => 'demand',
                    'title' => $name . ' — one-off order',
                    'detail' => number_format($units) . ' units with no standing forecast for this weekday.',
                    'href' => $customerHref,
                ];
            }
            continue;
        }

        if ($state === 'changed') {
            $meaningful = [];
            foreach ($customer['diff_lines'] ?? [] as $diff) {
                if (bakery_daily_brief_is_meaningful_diff(
                    $diff['standing_qty'] ?? null,
                    $diff['daily_qty'] ?? null
                )) {
                    $meaningful[] = bakery_daily_brief_format_qty_change(
                        (string)$diff['product_name'],
                        $diff['standing_qty'] ?? null,
                        $diff['daily_qty'] ?? null
                    );
                }
            }
            if ($meaningful !== []) {
                $changes[] = [
                    'severity' => 'warning',
                    'category' => 'demand',
                    'title' => $name . ' — order changed from standing',
                    'detail' => implode('; ', array_slice($meaningful, 0, 4))
                        . (count($meaningful) > 4 ? ' (+' . (count($meaningful) - 4) . ' more)' : ''),
                    'href' => $customerHref,
                ];
            }
        }

        $standingDriver = trim((string)($standingRouteDrivers[(int)$customer['customer_id']] ?? ''));
        $routeDriver = trim((string)($customer['route_driver_name'] ?? ''));
        if ($standingDriver !== '' && $routeDriver !== '' && strcasecmp($standingDriver, $routeDriver) !== 0) {
            $changes[] = [
                'severity' => 'info',
                'category' => 'route',
                'title' => $name . ' — route reassignment',
                'detail' => 'Standing route: ' . $standingDriver . ' → assigned: ' . $routeDriver . '.',
                'href' => $base . 'driver_assignment.php?date=' . rawurlencode($date),
            ];
        }
    }

    if (table_exists($db, 'daily_order_assignments')) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM daily_order_assignments doa
                JOIN daily_orders do ON do.id = doa.daily_order_id
                WHERE doa.delivery_date = ? AND do.order_date = ?
                  AND doa.delivery_status = 'cancelled'
            ");
            $stmt->execute([$date, $date]);
            $cancelled = (int)$stmt->fetchColumn();
            if ($cancelled > 0) {
                $changes[] = [
                    'severity' => 'warning',
                    'category' => 'delivery',
                    'title' => $cancelled . ' cancelled stop' . ($cancelled === 1 ? '' : 's'),
                    'detail' => 'Delivery assignment' . ($cancelled === 1 ? ' was' : 's were') . ' marked cancelled for this date.',
                    'href' => $base . 'driver_assignment.php?date=' . rawurlencode($date),
                ];
            }
        } catch (Throwable $e) {
            error_log('daily brief cancellations: ' . $e->getMessage());
        }
    }

    $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($changes, static function ($a, $b) use ($severityRank) {
        $ra = $severityRank[$a['severity']] ?? 9;
        $rb = $severityRank[$b['severity']] ?? 9;
        return $ra <=> $rb ?: strcmp($a['title'], $b['title']);
    });

    return array_slice($changes, 0, 20);
}

function bakery_daily_brief_production(PDO $db, string $date, array $commandCenter): array
{
    $weekday = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($weekday);
    $base = defined('BASE_URL') ? BASE_URL : '';
    $links = $commandCenter['links'];

    $requiredByProduct = [];
    $hasDailyItems = false;

    try {
        require_once __DIR__ . '/demand_review.php';
        $operatingDemand = bakery_operating_demand_by_product($db, $date);
        $hasDailyItems = $operatingDemand['has_daily'];

        if (!empty($operatingDemand['by_product'])) {
            $productIds = array_keys($operatingDemand['by_product']);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("
                SELECT id AS product_id, name AS product_name
                FROM products
                WHERE id IN ({$placeholders})
            ");
            $stmt->execute($productIds);
            $names = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int)$row['product_id']] = $row['product_name'];
            }
            foreach ($operatingDemand['by_product'] as $pid => $qty) {
                $requiredByProduct[(int)$pid] = [
                    'product_id' => (int)$pid,
                    'product_name' => $names[(int)$pid] ?? ('Product #' . $pid),
                    'required' => (int)$qty,
                    'produced' => 0,
                    'remaining' => (int)$qty,
                    'short' => false,
                ];
            }
        }

        $inventoryReady = !empty($commandCenter['inventory_ready']);
        if ($inventoryReady && $requiredByProduct !== [] && table_exists($db, 'product_inventory_days')) {
            $productIds = array_keys($requiredByProduct);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("
                SELECT product_id,
                       COALESCE(produced_quantity, 0) AS produced_quantity,
                       COALESCE(available_quantity, 0) AS available_quantity,
                       COALESCE(loaded_quantity, 0) AS loaded_quantity
                FROM product_inventory_days
                WHERE delivery_date = ? AND product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$date], $productIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $pid = (int)$row['product_id'];
                if (!isset($requiredByProduct[$pid])) {
                    continue;
                }
                $produced = (int)$row['produced_quantity'];
                $stock = (int)$row['available_quantity'] + (int)$row['loaded_quantity'];
                $required = $requiredByProduct[$pid]['required'];
                $requiredByProduct[$pid]['produced'] = $produced;
                $requiredByProduct[$pid]['remaining'] = max(0, $required - max($produced, $stock));
                $requiredByProduct[$pid]['short'] = $required > $stock;
            }
        }
    } catch (Throwable $e) {
        error_log('daily brief production: ' . $e->getMessage());
    }

    uasort($requiredByProduct, static function ($a, $b) {
        return ($b['required'] <=> $a['required']) ?: strcmp($a['product_name'], $b['product_name']);
    });

    $totalRequired = array_sum(array_column($requiredByProduct, 'required'));
    $avgRequired = $requiredByProduct !== [] ? $totalRequired / count($requiredByProduct) : 0;
    $highlights = [];
    $shortages = [];

    foreach ($requiredByProduct as $row) {
        if ($row['short']) {
            $shortages[] = $row['product_name'] . ' — need ' . number_format($row['required'])
                . ', stock short';
        }
        if ($row['remaining'] > 0 && ($commandCenter['inventory_ready'] ?? false)) {
            $highlights[] = $row;
        } elseif ($avgRequired > 0 && $row['required'] >= ($avgRequired * 1.75)) {
            $highlights[] = $row;
        }
    }

    if ($highlights === []) {
        $highlights = array_slice(array_values($requiredByProduct), 0, 6);
    } else {
        $highlights = array_slice($highlights, 0, 8);
    }

    $planShort = bakery_daily_brief_metric_value(
        bakery_daily_brief_stage_by_key($commandCenter, 'production') ?? [],
        'plan_short'
    );

    $summaryParts = [];
    if ($totalRequired > 0) {
        $summaryParts[] = number_format($totalRequired) . ' units across ' . count($requiredByProduct) . ' products';
    }
    if ($shortages !== []) {
        $summaryParts[] = count($shortages) . ' stock shortfall' . (count($shortages) === 1 ? '' : 's');
    }
    if ($planShort !== null && $planShort > 0) {
        $summaryParts[] = $planShort . ' under-planned in Production Center';
    }

    return [
        'summary' => $summaryParts !== [] ? implode(' · ', $summaryParts) : 'No production demand on file',
        'top_products' => array_slice(array_values($requiredByProduct), 0, 10),
        'highlights' => $highlights,
        'shortages' => $shortages,
        'total_required' => $totalRequired,
        'product_count' => count($requiredByProduct),
        'source' => $hasDailyItems ? 'daily orders' : 'standing forecast',
        'href_production' => $links['production'] ?? $base . 'production.php?date=' . rawurlencode($date),
        'href_production_center' => $links['production_center'] ?? '',
        'href_inventory' => $links['inventory'] ?? '',
    ];
}

/**
 * @return list<array{customer_name:string,note:string,source:string,href:string}>
 */
function bakery_daily_brief_customer_notes(PDO $db, string $date): array
{
    if (!table_exists($db, 'daily_orders')) {
        return [];
    }

    $base = defined('BASE_URL') ? BASE_URL : '';
    $notes = [];
    $hasAssignmentNotes = table_exists($db, 'daily_order_assignments')
        && function_exists('column_exists')
        && column_exists($db, 'daily_order_assignments', 'notes');

    try {
        $sql = "
            SELECT do.id, do.customer_id, do.notes AS order_notes, c.name AS customer_name
            FROM daily_orders do
            JOIN customers c ON c.id = do.customer_id
            " . bakery_sfb_ops_origin_clause('c', $db) . "
            WHERE do.order_date = ?
              AND do.notes IS NOT NULL AND TRIM(do.notes) <> ''
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $notes[] = [
                'customer_name' => (string)$row['customer_name'],
                'note' => trim((string)$row['order_notes']),
                'source' => 'Order note',
                'href' => $base . 'customer_record.php?customer_id=' . (int)$row['customer_id']
                    . '&date=' . rawurlencode($date),
            ];
        }

        if ($hasAssignmentNotes) {
            $stmt = $db->prepare("
                SELECT do.customer_id, c.name AS customer_name, doa.notes AS assignment_notes
                FROM daily_order_assignments doa
                JOIN daily_orders do ON do.id = doa.daily_order_id
                JOIN customers c ON c.id = do.customer_id
                " . bakery_sfb_ops_origin_clause('c', $db) . "
                WHERE doa.delivery_date = ? AND do.order_date = ?
                  AND doa.notes IS NOT NULL AND TRIM(doa.notes) <> ''
            ");
            $stmt->execute([$date, $date]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $note = trim((string)$row['assignment_notes']);
                if ($note === '') {
                    continue;
                }
                $notes[] = [
                    'customer_name' => (string)$row['customer_name'],
                    'note' => $note,
                    'source' => 'Delivery note',
                    'href' => $base . 'customer_record.php?customer_id=' . (int)$row['customer_id']
                        . '&date=' . rawurlencode($date),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('daily brief customer notes: ' . $e->getMessage());
    }

    return array_slice($notes, 0, 15);
}

function bakery_daily_brief_drivers(PDO $db, string $date, array $commandCenter): array
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $links = $commandCenter['links'];
    $drivers = [];
    $unassigned = bakery_daily_brief_metric_value(
        bakery_daily_brief_stage_by_key($commandCenter, 'delivery') ?? [],
        'unassigned'
    );

    if (!table_exists($db, 'daily_order_assignments') || !table_exists($db, 'drivers')) {
        return [
            'routes' => [],
            'unassigned' => $unassigned,
            'summary' => 'Driver data unavailable',
            'href_assignment' => $links['driver_assignment'] ?? '',
            'href_route' => $links['daily_route'] ?? '',
            'href_load' => $links['driver_load'] ?? '',
        ];
    }

    try {
        $stmt = $db->prepare("
            SELECT d.id AS driver_id, d.name AS driver_name,
                   COUNT(DISTINCT doa.id) AS stop_count,
                   COALESCE(SUM(doi.quantity), 0) AS units,
                   SUM(CASE WHEN doa.delivery_status = 'delivered' THEN 1 ELSE 0 END) AS delivered_stops,
                   SUM(CASE WHEN doa.delivery_status = 'failed' THEN 1 ELSE 0 END) AS failed_stops,
                   SUM(CASE WHEN doa.delivery_status IN ('pending','in_transit') THEN 1 ELSE 0 END) AS open_stops
            FROM daily_order_assignments doa
            JOIN drivers d ON d.id = doa.driver_id
            JOIN daily_orders do ON do.id = doa.daily_order_id
            LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
            WHERE doa.delivery_date = ? AND do.order_date = ?
              AND doa.delivery_status <> 'cancelled'
            GROUP BY d.id, d.name
            ORDER BY d.name
        ");
        $stmt->execute([$date, $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $loadedByDriver = [];
        if (!empty($commandCenter['inventory_ready']) && table_exists($db, 'driver_loads')) {
            $loadStmt = $db->prepare("
                SELECT dl.driver_id, COALESCE(SUM(li.loaded_quantity), 0) AS loaded_units
                FROM driver_loads dl
                LEFT JOIN driver_load_items li ON li.driver_load_id = dl.id
                WHERE dl.delivery_date = ?
                GROUP BY dl.driver_id
            ");
            $loadStmt->execute([$date]);
            foreach ($loadStmt->fetchAll(PDO::FETCH_ASSOC) as $loadRow) {
                $loadedByDriver[(int)$loadRow['driver_id']] = (int)$loadRow['loaded_units'];
            }
        }

        foreach ($rows as $row) {
            $driverId = (int)$row['driver_id'];
            $units = (int)$row['units'];
            $loaded = $loadedByDriver[$driverId] ?? null;
            $loadStatus = 'unknown';
            if ($loaded !== null) {
                if ($units === 0) {
                    $loadStatus = 'n/a';
                } elseif ($loaded >= $units) {
                    $loadStatus = 'loaded';
                } elseif ($loaded > 0) {
                    $loadStatus = 'partial';
                } else {
                    $loadStatus = 'not_loaded';
                }
            }

            $issues = [];
            if ((int)$row['failed_stops'] > 0) {
                $issues[] = (int)$row['failed_stops'] . ' failed';
            }
            if ($loadStatus === 'not_loaded' || $loadStatus === 'partial') {
                $issues[] = 'load incomplete';
            }

            $drivers[] = [
                'driver_id' => $driverId,
                'driver_name' => (string)$row['driver_name'],
                'stop_count' => (int)$row['stop_count'],
                'units' => $units,
                'delivered_stops' => (int)$row['delivered_stops'],
                'open_stops' => (int)$row['open_stops'],
                'load_status' => $loadStatus,
                'issues' => $issues,
            ];
        }
    } catch (Throwable $e) {
        error_log('daily brief drivers: ' . $e->getMessage());
    }

    $summary = count($drivers) . ' driver' . (count($drivers) === 1 ? '' : 's');
    if ($unassigned !== null && $unassigned > 0) {
        $summary .= ' · ' . $unassigned . ' unassigned order' . ($unassigned === 1 ? '' : 's');
    }

    return [
        'routes' => $drivers,
        'unassigned' => $unassigned,
        'summary' => $summary,
        'href_assignment' => $links['driver_assignment'] ?? $base . 'driver_assignment.php?date=' . rawurlencode($date),
        'href_route' => $links['daily_route'] ?? $base . 'daily_route.php?date=' . rawurlencode($date),
        'href_load' => $links['driver_load'] ?? $base . 'driver_load.php?date=' . rawurlencode($date),
    ];
}

function bakery_daily_brief_ingredient_alerts(PDO $db, string $date): array
{
    if (!is_file(__DIR__ . '/ingredient_requirements.php')
        || !is_file(__DIR__ . '/ingredient_units.php')) {
        return ['available' => false, 'exceptions' => [], 'href' => ''];
    }
    require_once __DIR__ . '/ingredient_requirements.php';

    try {
        $plan = bakery_ingredient_requirements_build($db, $date, 'demand');
    } catch (Throwable $e) {
        error_log('daily brief ingredients: ' . $e->getMessage());
        return ['available' => false, 'exceptions' => [], 'href' => ''];
    }

    $base = defined('BASE_URL') ? BASE_URL : '';
    $exceptions = [];
    foreach ($plan['exceptions'] ?? [] as $ex) {
        $exceptions[] = [
            'code' => (string)($ex['code'] ?? ''),
            'message' => (string)($ex['message'] ?? ''),
            'product_name' => (string)($ex['product_name'] ?? ''),
        ];
    }

    return [
        'available' => ($plan['error'] ?? null) === null,
        'exceptions' => array_slice($exceptions, 0, 8),
        'product_count' => (int)($plan['totals']['products'] ?? 0),
        'href' => $base . 'ingredient_requirements.php?date=' . rawurlencode($date) . '&source=demand',
    ];
}

function bakery_daily_brief_handoff(
    array $commandCenter,
    array $production,
    array $drivers,
    PDO $db,
    string $date,
    ?array $dailyRun = null
): array {
    $completed = [];
    $outstanding = [];

    foreach ($commandCenter['stages'] ?? [] as $stage) {
        $key = $stage['key'] ?? '';
        $state = $stage['state'] ?? '';
        $label = $stage['label'] ?? $key;
        $summary = (string)($stage['summary'] ?? '');

        if ($state === 'ok' && $summary !== '' && strpos(strtolower($summary), 'nothing') === false) {
            $completed[] = $label . ': ' . $summary;
        } elseif ($state === 'attention') {
            $outstanding[] = $label . ': ' . $summary;
        } elseif ($state === 'unknown') {
            $outstanding[] = $label . ': data unavailable';
        }
    }

    foreach ($commandCenter['exceptions'] ?? [] as $ex) {
        if (($ex['severity'] ?? '') === 'info') {
            continue;
        }
        $outstanding[] = (string)($ex['title'] ?? 'Exception');
    }

    if ($dailyRun !== null) {
        foreach ($dailyRun['blockers'] ?? [] as $b) {
            if (($b['severity'] ?? '') === 'info') {
                continue;
            }
            $title = (string)($b['title'] ?? '');
            if ($title !== '' && !in_array($title, $outstanding, true)) {
                $outstanding[] = $title;
            }
        }
        if (!empty($dailyRun['is_closed'])) {
            $completed[] = 'Manager closeout recorded';
        }
    }

    $remainingUnits = 0;
    foreach ($production['highlights'] ?? [] as $row) {
        if (($row['remaining'] ?? 0) > 0) {
            $remainingUnits += (int)$row['remaining'];
        }
    }
    if ($remainingUnits > 0) {
        $outstanding[] = 'Production: ~' . number_format($remainingUnits) . ' units still to produce or stock';
    }

    $recent = [];
    $delivery = bakery_daily_brief_stage_by_key($commandCenter, 'delivery');
    $delivered = bakery_daily_brief_metric_value($delivery ?? [], 'delivered');
    $failed = bakery_daily_brief_metric_value($delivery ?? [], 'failed');
    if ($delivered !== null && $delivered > 0) {
        $recent[] = $delivered . ' stop' . ($delivered === 1 ? '' : 's') . ' marked delivered';
    }
    if ($failed !== null && $failed > 0) {
        $recent[] = $failed . ' failed deliver' . ($failed === 1 ? 'y' : 'ies');
    }
    $invoice = bakery_daily_brief_stage_by_key($commandCenter, 'invoice');
    $invoiced = bakery_daily_brief_metric_value($invoice ?? [], 'invoiced');
    if ($invoiced !== null && $invoiced > 0) {
        $recent[] = $invoiced . ' order' . ($invoiced === 1 ? '' : 's') . ' invoiced';
    }
    foreach ($drivers['routes'] ?? [] as $route) {
        if (($route['load_status'] ?? '') === 'loaded' && ($route['open_stops'] ?? 0) === 0 && ($route['delivered_stops'] ?? 0) > 0) {
            $recent[] = $route['driver_name'] . ' route complete (' . $route['delivered_stops'] . ' stops)';
        }
    }

    if (is_file(__DIR__ . '/operational_timeline.php')) {
        require_once __DIR__ . '/operational_timeline.php';
        try {
            $since = date('Y-m-d H:i:s', strtotime('-8 hours'));
            $timeline = bakery_operational_timeline_fetch($db, [
                'operational_date' => $date,
                'since' => $since,
                'limit' => 8,
            ]);
            foreach ($timeline as $entry) {
                $summary = trim((string)($entry['summary'] ?? ''));
                if ($summary === '') {
                    continue;
                }
                $at = !empty($entry['occurred_at'])
                    ? date('g:i A', strtotime((string)$entry['occurred_at']))
                    : '';
                $recent[] = ($at !== '' ? $at . ' — ' : '') . $summary;
                if (count($recent) >= 6) {
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('daily brief timeline: ' . $e->getMessage());
        }
    }

    return [
        'completed' => array_slice($completed, 0, 8),
        'outstanding' => array_slice(array_unique($outstanding), 0, 12),
        'recent' => array_slice($recent, 0, 6),
    ];
}
