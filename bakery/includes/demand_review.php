<?php
/**
 * Demand review helpers for Daily Orders.
 *
 * standing_orders = recurring forecast/template
 * daily_orders    = dated commercial demand for a specific delivery date
 *
 * These helpers compare the two without inventing a new lifecycle or schema.
 */


if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sfb_origin.php';

/**
 * Build a customer-level standing vs daily comparison for one delivery date.
 *
 * @param array{driver_id?:int,zone?:string,customer?:string,product_id?:int,review?:string} $filters
 * @return array{
 *   date:string,
 *   day_of_week:int,
 *   day_name:string,
 *   week_start:string,
 *   summary:array,
 *   product_totals:array,
 *   customers:array,
 *   advanced_status_count:int
 * }
 */
function bakery_demand_review_build(PDO $db, $date, array $filters = []) {
    if (!function_exists('bakery_customer_delivery_is_skipped')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }

    $dayOfWeek = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($dayOfWeek);
    $weekStart = bakery_week_start_monday($date);
    $dayName = $dateObject->format('l');

    $driverId = isset($filters['driver_id']) ? (int)$filters['driver_id'] : 0;
    $zoneFilter = isset($filters['zone']) ? trim((string)$filters['zone']) : '';
    $customerFilter = isset($filters['customer']) ? trim((string)$filters['customer']) : '';
    $productId = isset($filters['product_id']) ? (int)$filters['product_id'] : 0;
    $reviewFilter = isset($filters['review']) ? trim((string)$filters['review']) : '';

    $zoneJoin = bakery_customer_zone_join_sql();
    $zoneSelect = 'COALESCE(z.name, NULLIF(c.zone, \'\'), \'No Zone\') AS zone_label';

    // Standing forecast lines for this weekday.
    $standingSql = "
        SELECT so.customer_id, so.product_id, so.quantity,
               c.name AS customer_name, c.address, c.zone, c.zone_id,
               {$zoneSelect},
               p.name AS product_name
        FROM standing_orders so
        JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        {$zoneJoin}
        JOIN products p ON p.id = so.product_id
        WHERE so.day_of_week {$dayClause['sql']}
          AND so.quantity > 0
    ";
    $standingParams = $dayClause['values'];
    if ($zoneFilter !== '') {
        $standingSql .= " AND COALESCE(z.name, c.zone, '') = ?";
        $standingParams[] = $zoneFilter;
    }
    if ($customerFilter !== '') {
        $standingSql .= ' AND c.name LIKE ?';
        $standingParams[] = '%' . $customerFilter . '%';
    }
    if ($productId > 0) {
        $standingSql .= ' AND so.product_id = ?';
        $standingParams[] = $productId;
    }
    $standingStmt = $db->prepare($standingSql);
    $standingStmt->execute($standingParams);
    $standingRows = $standingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Dated daily orders + items for this date.
    $dailySql = "
        SELECT do.id AS daily_order_id, do.customer_id, do.status, do.total_amount,
               do.driver_id AS legacy_driver_id,
               c.name AS customer_name, c.address, c.zone, c.zone_id,
               {$zoneSelect},
               doi.id AS item_id, doi.product_id, doi.quantity, doi.delivered_quantity,
               doi.unit_price, doi.line_total,
               p.name AS product_name,
               (
                   SELECT doa.delivery_status
                   FROM daily_order_assignments doa
                   WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                   ORDER BY doa.id LIMIT 1
               ) AS assignment_status,
               COALESCE(
                   (SELECT d.name FROM daily_order_assignments doa
                    JOIN drivers d ON d.id = doa.driver_id
                    WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                    ORDER BY doa.route_order, doa.id LIMIT 1),
                   (SELECT d.name FROM drivers d WHERE d.id = do.driver_id LIMIT 1)
               ) AS route_driver_name,
               (
                   SELECT doa.driver_id
                   FROM daily_order_assignments doa
                   WHERE doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
                   ORDER BY doa.route_order, doa.id LIMIT 1
               ) AS assignment_driver_id
        FROM daily_orders do
        JOIN customers c ON c.id = do.customer_id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        {$zoneJoin}
        LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
        LEFT JOIN products p ON p.id = doi.product_id
        WHERE do.order_date = ?
    ";
    $dailyParams = [$date];
    if ($zoneFilter !== '') {
        $dailySql .= " AND COALESCE(z.name, c.zone, '') = ?";
        $dailyParams[] = $zoneFilter;
    }
    if ($customerFilter !== '') {
        $dailySql .= ' AND c.name LIKE ?';
        $dailyParams[] = '%' . $customerFilter . '%';
    }
    if ($productId > 0) {
        // Keep customers that have this product on standing OR daily.
        $dailySql .= ' AND (
            EXISTS (
                SELECT 1 FROM daily_order_items doi2
                WHERE doi2.daily_order_id = do.id AND doi2.product_id = ?
            )
            OR EXISTS (
                SELECT 1 FROM standing_orders so2
                WHERE so2.customer_id = do.customer_id
                  AND so2.product_id = ?
                  AND so2.day_of_week ' . $dayClause['sql'] . '
                  AND so2.quantity > 0
            )
        )';
        $dailyParams[] = $productId;
        $dailyParams[] = $productId;
        foreach ($dayClause['values'] as $v) {
            $dailyParams[] = $v;
        }
    }
    if ($driverId > 0) {
        $dailySql .= ' AND (
            do.driver_id = ?
            OR EXISTS (
                SELECT 1 FROM daily_order_assignments doa
                WHERE doa.daily_order_id = do.id
                  AND doa.delivery_date = do.order_date
                  AND doa.driver_id = ?
            )
        )';
        $dailyParams[] = $driverId;
        $dailyParams[] = $driverId;
    }

    $dailyStmt = $db->prepare($dailySql);
    $dailyStmt->execute($dailyParams);
    $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    // Standing-route driver filter (for expected customers not yet on daily).
    $standingRouteByCustomer = [];
    $routeStmt = $db->prepare("
        SELECT sr.customer_id, sr.driver_id, d.name AS driver_name
        FROM standing_routes sr
        JOIN drivers d ON d.id = sr.driver_id
        WHERE sr.day_of_week {$dayClause['sql']}
    ");
    $routeStmt->execute($dayClause['values']);
    foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
        $cid = (int)$route['customer_id'];
        if (!isset($standingRouteByCustomer[$cid])) {
            $standingRouteByCustomer[$cid] = $route;
        }
    }

    $customers = [];

    foreach ($standingRows as $row) {
        $cid = (int)$row['customer_id'];
        if ($driverId > 0) {
            $routeDriver = (int)($standingRouteByCustomer[$cid]['driver_id'] ?? 0);
            // If they already have a daily order, driver filter is applied on daily query;
            // for standing-only customers, filter by standing route.
            // We'll re-check after merge.
        }
        if (!isset($customers[$cid])) {
            $customers[$cid] = bakery_demand_review_empty_customer($row, $standingRouteByCustomer[$cid] ?? null);
        }
        $pid = (int)$row['product_id'];
        $customers[$cid]['standing_items'][$pid] = [
            'product_id' => $pid,
            'product_name' => $row['product_name'],
            'standing_qty' => (int)$row['quantity'],
            'daily_qty' => null,
            'item_id' => null,
            'source' => 'standing',
        ];
        $customers[$cid]['has_standing'] = true;
    }

    foreach ($dailyRows as $row) {
        $cid = (int)$row['customer_id'];
        if (!isset($customers[$cid])) {
            $customers[$cid] = bakery_demand_review_empty_customer($row, $standingRouteByCustomer[$cid] ?? null);
        }
        $customers[$cid]['daily_order_id'] = (int)$row['daily_order_id'];
        $customers[$cid]['status'] = $row['status'];
        $customers[$cid]['total_amount'] = (float)$row['total_amount'];
        $customers[$cid]['assignment_status'] = $row['assignment_status'];
        $customers[$cid]['route_driver_name'] = $row['route_driver_name'] ?: ($customers[$cid]['route_driver_name'] ?? null);
        $customers[$cid]['has_daily'] = true;

        if ($row['product_id'] === null) {
            continue;
        }
        $pid = (int)$row['product_id'];
        if (!isset($customers[$cid]['standing_items'][$pid]) && !isset($customers[$cid]['daily_items'][$pid])) {
            // placeholder; merged below
        }
        if (!isset($customers[$cid]['line_map'][$pid])) {
            $customers[$cid]['line_map'][$pid] = [
                'product_id' => $pid,
                'product_name' => $row['product_name'],
                'standing_qty' => null,
                'daily_qty' => null,
                'item_id' => null,
                'delivered_quantity' => null,
                'unit_price' => null,
            ];
        }
        $customers[$cid]['line_map'][$pid]['daily_qty'] = (int)$row['quantity'];
        $customers[$cid]['line_map'][$pid]['item_id'] = (int)$row['item_id'];
        $customers[$cid]['line_map'][$pid]['delivered_quantity'] = $row['delivered_quantity'];
        $customers[$cid]['line_map'][$pid]['unit_price'] = $row['unit_price'];
        $customers[$cid]['line_map'][$pid]['product_name'] = $row['product_name'];
    }

    // Merge standing into line_map and classify.
    $dropIds = [];
    foreach ($customers as $cid => $customer) {
        if (!isset($customer['line_map'])) {
            $customer['line_map'] = [];
        }
        foreach ($customer['standing_items'] as $pid => $standingLine) {
            if (!isset($customer['line_map'][$pid])) {
                $customer['line_map'][$pid] = [
                    'product_id' => $pid,
                    'product_name' => $standingLine['product_name'],
                    'standing_qty' => $standingLine['standing_qty'],
                    'daily_qty' => null,
                    'item_id' => null,
                    'delivered_quantity' => null,
                    'unit_price' => null,
                ];
            } else {
                $customer['line_map'][$pid]['standing_qty'] = $standingLine['standing_qty'];
                if ($customer['line_map'][$pid]['product_name'] === null || $customer['line_map'][$pid]['product_name'] === '') {
                    $customer['line_map'][$pid]['product_name'] = $standingLine['product_name'];
                }
            }
        }

        // Product filter: drop customers with no matching product lines after merge.
        if ($productId > 0) {
            if (!isset($customer['line_map'][$productId])) {
                $dropIds[] = $cid;
                continue;
            }
            $customer['line_map'] = [$productId => $customer['line_map'][$productId]];
        }

        // Driver filter for standing-only customers.
        if ($driverId > 0 && empty($customer['has_daily'])) {
            $routeDriver = (int)($standingRouteByCustomer[$cid]['driver_id'] ?? 0);
            if ($routeDriver !== $driverId) {
                $dropIds[] = $cid;
                continue;
            }
        }

        $customer['paused'] = bakery_customer_week_is_paused($db, $cid, $weekStart)
            || (function_exists('bakery_customer_delivery_in_pause_range')
                && bakery_customer_delivery_in_pause_range($db, $cid, $date));
        if (function_exists('bakery_customer_delivery_is_skipped')) {
            $customer['customer_skipped'] = bakery_customer_delivery_is_skipped($db, $cid, $date);
            if ($customer['customer_skipped']) {
                $customer['paused'] = true;
            }
        }
        $customer['state'] = bakery_demand_review_classify_customer($customer);
        $customer['diff_lines'] = bakery_demand_review_diff_lines($customer['line_map']);
        $customer['standing_units'] = 0;
        $customer['daily_units'] = 0;
        foreach ($customer['line_map'] as $line) {
            $customer['standing_units'] += (int)($line['standing_qty'] ?? 0);
            $customer['daily_units'] += (int)($line['daily_qty'] ?? 0);
        }
        $customer['is_advanced'] = bakery_demand_review_is_advanced_status(
            $customer['status'] ?? null,
            $customer['assignment_status'] ?? null
        );
        unset($customer['standing_items']);
        $customers[$cid] = $customer;
    }
    foreach ($dropIds as $cid) {
        unset($customers[$cid]);
    }

    // Apply review-state filter.
    if ($reviewFilter !== '' && $reviewFilter !== 'all') {
        $customers = array_filter($customers, function ($c) use ($reviewFilter) {
            return bakery_demand_review_matches_filter($c, $reviewFilter);
        });
    }

    // Sort: exceptions first, then name.
    uasort($customers, function ($a, $b) {
        $rank = [
            'missing_daily' => 0,
            'changed' => 1,
            'one_off' => 2,
            'empty_daily' => 3,
            'paused' => 4,
            'matches' => 5,
        ];
        $ra = $rank[$a['state']] ?? 9;
        $rb = $rank[$b['state']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });

    $summary = [
        'expected_customers' => 0,
        'customers_with_daily' => 0,
        'matches' => 0,
        'changed' => 0,
        'one_off' => 0,
        'missing_daily' => 0,
        'empty_daily' => 0,
        'paused' => 0,
        'standing_units' => 0,
        'daily_units' => 0,
        'unit_delta' => 0,
    ];
    $productTotals = [];
    $advancedStatusCount = 0;

    foreach ($customers as $customer) {
        if ($customer['has_standing']) {
            $summary['expected_customers']++;
        }
        if ($customer['has_daily']) {
            $summary['customers_with_daily']++;
        }
        if (isset($summary[$customer['state']])) {
            $summary[$customer['state']]++;
        }
        $summary['standing_units'] += $customer['standing_units'];
        $summary['daily_units'] += $customer['daily_units'];
        if ($customer['is_advanced']) {
            $advancedStatusCount++;
        }
        foreach ($customer['line_map'] as $line) {
            $pid = (int)$line['product_id'];
            if (!isset($productTotals[$pid])) {
                $productTotals[$pid] = [
                    'product_id' => $pid,
                    'product_name' => $line['product_name'],
                    'standing_qty' => 0,
                    'daily_qty' => 0,
                ];
            }
            $productTotals[$pid]['standing_qty'] += (int)($line['standing_qty'] ?? 0);
            $productTotals[$pid]['daily_qty'] += (int)($line['daily_qty'] ?? 0);
        }
    }
    $summary['unit_delta'] = $summary['daily_units'] - $summary['standing_units'];

    uasort($productTotals, function ($a, $b) {
        $da = $a['daily_qty'] - $a['standing_qty'];
        $dbv = $b['daily_qty'] - $b['standing_qty'];
        if ($da !== $dbv) {
            // Larger absolute diffs first.
            return abs($dbv) <=> abs($da);
        }
        return strcasecmp($a['product_name'], $b['product_name']);
    });

    return [
        'date' => $date,
        'day_of_week' => $dayOfWeek,
        'day_name' => $dayName,
        'week_start' => $weekStart,
        'summary' => $summary,
        'product_totals' => array_values($productTotals),
        'customers' => array_values($customers),
        'advanced_status_count' => $advancedStatusCount,
    ];
}

/**
 * Preview what generate_from_standing would do for a date (read-only).
 *
 * @return array
 */
function bakery_demand_review_preview_generate(PDO $db, $date) {
    $review = bakery_demand_review_build($db, $date, []);
    $wouldCreateCustomers = 0;
    $wouldCreateItems = 0;
    $wouldOverwrite = 0;
    $wouldMatch = 0;
    $pausedSkipped = 0;
    $overwriteExamples = [];

    foreach ($review['customers'] as $customer) {
        if (!empty($customer['paused']) && $customer['has_standing']) {
            $pausedSkipped++;
            continue;
        }
        if (!$customer['has_standing']) {
            continue;
        }
        if (!$customer['has_daily']) {
            $wouldCreateCustomers++;
            foreach ($customer['line_map'] as $line) {
                if ((int)($line['standing_qty'] ?? 0) > 0) {
                    $wouldCreateItems++;
                }
            }
            continue;
        }
        foreach ($customer['line_map'] as $line) {
            $standingQty = $line['standing_qty'];
            $dailyQty = $line['daily_qty'];
            if ($standingQty === null) {
                continue; // generation does not remove one-off lines
            }
            if ($dailyQty === null) {
                $wouldCreateItems++;
            } elseif ((int)$dailyQty === (int)$standingQty) {
                $wouldMatch++;
            } else {
                $wouldOverwrite++;
                if (count($overwriteExamples) < 12) {
                    $overwriteExamples[] = [
                        'customer_name' => $customer['customer_name'],
                        'product_name' => $line['product_name'],
                        'standing_qty' => (int)$standingQty,
                        'daily_qty' => (int)$dailyQty,
                        'status' => $customer['status'],
                    ];
                }
            }
        }
    }

    return [
        'date' => $date,
        'day_name' => $review['day_name'],
        'expected_customers' => $review['summary']['expected_customers'],
        'customers_with_daily' => $review['summary']['customers_with_daily'],
        'missing_daily' => $review['summary']['missing_daily'],
        'changed' => $review['summary']['changed'],
        'would_create_customers' => $wouldCreateCustomers,
        'would_create_items' => $wouldCreateItems,
        'would_overwrite_changed_items' => $wouldOverwrite,
        'would_leave_matching_items' => $wouldMatch,
        'paused_customers_skipped' => $pausedSkipped,
        'overwrite_examples' => $overwriteExamples,
        'advanced_status_count' => $review['advanced_status_count'],
        'warning' => $wouldOverwrite > 0
            ? 'Re-running generation overwrites dated quantities that already differ from standing, unless you choose to preserve them.'
            : null,
    ];
}

function bakery_demand_review_empty_customer(array $row, $standingRoute = null) {
    return [
        'customer_id' => (int)$row['customer_id'],
        'customer_name' => $row['customer_name'],
        'address' => $row['address'] ?? '',
        'zone' => $row['zone'] ?? '',
        'zone_id' => isset($row['zone_id']) ? (int)$row['zone_id'] : null,
        'zone_label' => $row['zone_label'] ?? ($row['zone'] ?: 'No Zone'),
        'daily_order_id' => null,
        'status' => null,
        'total_amount' => 0,
        'assignment_status' => null,
        'route_driver_name' => $standingRoute['driver_name'] ?? ($row['route_driver_name'] ?? null),
        'has_standing' => false,
        'has_daily' => false,
        'paused' => false,
        'standing_items' => [],
        'line_map' => [],
        'state' => 'matches',
        'diff_lines' => [],
        'standing_units' => 0,
        'daily_units' => 0,
        'is_advanced' => false,
    ];
}

/**
 * Classify a customer demand row using terminology grounded in stored data.
 */
function bakery_demand_review_classify_customer(array $customer) {
    if (!empty($customer['customer_skipped'])) {
        return 'customer_skipped';
    }
    if (!empty($customer['paused']) && empty($customer['has_daily'])) {
        return 'paused';
    }
    if (!empty($customer['has_standing']) && empty($customer['has_daily'])) {
        return 'missing_daily';
    }
    if (!empty($customer['has_daily']) && empty($customer['has_standing'])) {
        $hasItems = false;
        foreach ($customer['line_map'] as $line) {
            if ($line['daily_qty'] !== null && (int)$line['daily_qty'] > 0) {
                $hasItems = true;
                break;
            }
        }
        return $hasItems ? 'one_off' : 'empty_daily';
    }
    if (!empty($customer['has_daily']) && empty($customer['line_map'])) {
        return 'empty_daily';
    }

    $hasDailyItems = false;
    $hasDiff = false;
    foreach ($customer['line_map'] as $line) {
        $s = $line['standing_qty'];
        $d = $line['daily_qty'];
        if ($d !== null && (int)$d > 0) {
            $hasDailyItems = true;
        }
        if ($s === null && $d !== null) {
            $hasDiff = true;
        } elseif ($s !== null && $d === null) {
            $hasDiff = true;
        } elseif ($s !== null && $d !== null && (int)$s !== (int)$d) {
            $hasDiff = true;
        }
    }

    if (!empty($customer['has_daily']) && !$hasDailyItems && !empty($customer['has_standing'])) {
        // Dated order shell exists but no line items — standing expected units are unfulfilled on this date.
        return 'empty_daily';
    }
    if ($hasDiff) {
        return 'changed';
    }
    if (!empty($customer['paused'])) {
        return 'paused';
    }
    return 'matches';
}

function bakery_demand_review_diff_lines(array $lineMap) {
    $diffs = [];
    foreach ($lineMap as $line) {
        $s = $line['standing_qty'];
        $d = $line['daily_qty'];
        if ($s === null && $d === null) {
            continue;
        }
        if ($s !== null && $d !== null && (int)$s === (int)$d) {
            continue;
        }
        $kind = 'changed';
        if ($s !== null && $d === null) {
            $kind = 'missing_on_daily';
        } elseif ($s === null && $d !== null) {
            $kind = 'daily_only';
        }
        $diffs[] = [
            'product_id' => (int)$line['product_id'],
            'product_name' => $line['product_name'],
            'standing_qty' => $s === null ? null : (int)$s,
            'daily_qty' => $d === null ? null : (int)$d,
            'kind' => $kind,
        ];
    }
    return $diffs;
}

function bakery_demand_review_is_advanced_status($orderStatus, $assignmentStatus) {
    $advancedOrder = ['in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];
    $advancedAssign = ['in_transit', 'delivered', 'failed'];
    if ($orderStatus && in_array($orderStatus, $advancedOrder, true)) {
        return true;
    }
    if ($assignmentStatus && in_array($assignmentStatus, $advancedAssign, true)) {
        return true;
    }
    return false;
}

function bakery_demand_review_matches_filter(array $customer, $filter) {
    switch ($filter) {
        case 'differences':
            return in_array($customer['state'], ['changed', 'missing_daily', 'one_off', 'empty_daily'], true);
        case 'missing':
            return $customer['state'] === 'missing_daily';
        case 'changed':
            return $customer['state'] === 'changed';
        case 'one_off':
            return $customer['state'] === 'one_off';
        case 'matches':
            return $customer['state'] === 'matches';
        case 'paused':
            return $customer['state'] === 'paused' || !empty($customer['paused']);
        case 'empty':
            return $customer['state'] === 'empty_daily';
        default:
            return true;
    }
}

function bakery_demand_review_state_label($state) {
    $labels = [
        'matches' => 'Matches standing',
        'changed' => 'Changed for this date',
        'one_off' => 'Added for this date',
        'missing_daily' => 'No dated order yet',
        'empty_daily' => 'Dated order has no items',
        'paused' => 'Standing paused this week',
        'customer_skipped' => 'Customer skipped this delivery',
    ];
    return $labels[$state] ?? $state;
}

function bakery_demand_review_state_help($state) {
    $help = [
        'matches' => 'Dated quantities match this weekday\'s standing forecast.',
        'changed' => 'At least one product quantity differs from standing for this date.',
        'one_off' => 'This dated order has no standing forecast for this weekday.',
        'missing_daily' => 'Standing forecast exists, but no daily_orders row for this date.',
        'empty_daily' => 'A daily order exists, but it currently has no line items.',
        'paused' => 'Customer standing is paused for this week; generation skips them.',
    ];
    return $help[$state] ?? '';
}

/**
 * Effective operating demand lines for one delivery date.
 *
 * Per customer: a committed daily order (non-empty shell) uses dated line quantities;
 * otherwise standing forecast for the weekday applies (respecting weekly pauses).
 *
 * @param array{product_id?:int} $filters
 * @return array<int, array{
 *   customer_id:int,
 *   customer_name:string,
 *   customer_zone:string,
 *   product_id:int,
 *   product_name:string,
 *   quantity:int,
 *   source:string,
 *   dough_type_name:string
 * }>
 */
function bakery_operating_demand_lines(PDO $db, string $date, array $filters = []) {
    if (!function_exists('bakery_pan_dulce_standard_products')) {
        require_once __DIR__ . '/pan_dulce_standards.php';
    }
    if (!function_exists('bakery_customer_delivery_is_skipped')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }

    $review = bakery_demand_review_build($db, $date, $filters);
    $lines = [];
    $customersWithDemand = [];

    foreach ($review['customers'] as $customer) {
        if (!empty($customer['paused']) && empty($customer['has_daily'])) {
            continue;
        }

        $state = (string)($customer['state'] ?? '');
        $useDaily = !empty($customer['has_daily'])
            && $state !== 'empty_daily'
            && $state !== 'missing_daily';

        if (!$useDaily && empty($customer['has_standing'])) {
            continue;
        }

        $customerId = (int)$customer['customer_id'];
        foreach ($customer['line_map'] as $line) {
            if ($useDaily) {
                $qty = $line['daily_qty'] !== null ? (int)$line['daily_qty'] : 0;
                $source = 'daily';
            } else {
                $qty = (int)($line['standing_qty'] ?? 0);
                $source = 'standing';
            }
            if ($qty <= 0) {
                continue;
            }

            $customersWithDemand[$customerId] = true;
            $pid = (int)$line['product_id'];
            $lines[] = [
                'customer_id' => $customerId,
                'customer_name' => (string)$customer['customer_name'],
                'customer_zone' => (string)($customer['zone_label'] ?? $customer['zone'] ?? ''),
                'product_id' => $pid,
                'product_name' => (string)$line['product_name'],
                'quantity' => $qty,
                'source' => $source,
                'dough_type_name' => '',
            ];
        }
    }

    // Routed customers with no standing or dated order yet: assume standard Pan Dulce.
    $dayOfWeek = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($dayOfWeek);
    $driverId = isset($filters['driver_id']) ? (int)$filters['driver_id'] : 0;
    $zoneFilter = isset($filters['zone']) ? trim((string)$filters['zone']) : '';
    $customerFilter = isset($filters['customer']) ? trim((string)$filters['customer']) : '';
    $productId = isset($filters['product_id']) ? (int)$filters['product_id'] : 0;
    $zoneJoin = bakery_customer_zone_join_sql();
    $zoneSelect = "COALESCE(z.name, NULLIF(c.zone, ''), 'No Zone') AS zone_label";

    $routeSql = "
        SELECT sr.customer_id, sr.driver_id, c.name AS customer_name, {$zoneSelect}
        FROM standing_routes sr
        JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        {$zoneJoin}
        WHERE sr.day_of_week {$dayClause['sql']}
          AND NOT EXISTS (
              SELECT 1 FROM standing_orders so
              WHERE so.customer_id = sr.customer_id
                AND so.day_of_week {$dayClause['sql']}
                AND so.quantity > 0
          )
          AND NOT EXISTS (
              SELECT 1 FROM daily_orders do
              WHERE do.customer_id = sr.customer_id
                AND do.order_date = ?
          )
    ";
    $routeParams = array_merge($dayClause['values'], $dayClause['values'], [$date]);
    if ($driverId > 0) {
        $routeSql .= ' AND sr.driver_id = ?';
        $routeParams[] = $driverId;
    }
    if ($zoneFilter !== '') {
        $routeSql .= " AND COALESCE(z.name, c.zone, '') = ?";
        $routeParams[] = $zoneFilter;
    }
    if ($customerFilter !== '') {
        $routeSql .= ' AND c.name LIKE ?';
        $routeParams[] = '%' . $customerFilter . '%';
    }

    $routeStmt = $db->prepare($routeSql);
    $routeStmt->execute($routeParams);
    $standardLines = bakery_pan_dulce_standard_demand_lines($db);

    foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
        $customerId = (int)$route['customer_id'];
        if (isset($customersWithDemand[$customerId]) || $standardLines === []) {
            continue;
        }
        if (function_exists('bakery_customer_delivery_is_paused')
            && bakery_customer_delivery_is_paused($db, $customerId, $date)) {
            continue;
        }

        foreach ($standardLines as $line) {
            if ($productId > 0 && (int)$line['product_id'] !== $productId) {
                continue;
            }
            $lines[] = [
                'customer_id' => $customerId,
                'customer_name' => (string)$route['customer_name'],
                'customer_zone' => (string)($route['zone_label'] ?? ''),
                'product_id' => (int)$line['product_id'],
                'product_name' => (string)$line['product_name'],
                'quantity' => (int)$line['quantity'],
                'source' => 'pan_dulce_standard',
                'dough_type_name' => '',
            ];
        }
    }

    if (!empty($lines) && table_exists($db, 'products')) {
        $productIds = array_values(array_unique(array_column($lines, 'product_id')));
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $dtStmt = $db->prepare(
            "SELECT p.id, COALESCE(dt.name, 'Unclassified') AS dough_type_name
             FROM products p
             LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
             WHERE p.id IN ({$placeholders})"
        );
        $dtStmt->execute($productIds);
        $doughByProduct = [];
        foreach ($dtStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $doughByProduct[(int)$row['id']] = (string)$row['dough_type_name'];
        }
        foreach ($lines as &$line) {
            $line['dough_type_name'] = $doughByProduct[$line['product_id']] ?? 'Unclassified';
        }
        unset($line);
    }

    return $lines;
}

/**
 * Count how operating-demand lines mix dated vs standing vs Pan Dulce standard.
 *
 * @param list<array<string,mixed>> $lines
 * @return array{
 *   daily_customers:int,
 *   standing_customers:int,
 *   standard_customers:int,
 *   has_daily:bool,
 *   has_standing:bool,
 *   mode:string
 * }
 */
function bakery_operating_demand_mix_from_lines(array $lines): array
{
    $dailyCustomers = [];
    $standingCustomers = [];
    $standardCustomers = [];
    foreach ($lines as $line) {
        $cid = (int)($line['customer_id'] ?? 0);
        $src = (string)($line['source'] ?? 'standing');
        if ($src === 'daily') {
            $dailyCustomers[$cid] = true;
        } elseif ($src === 'pan_dulce_standard') {
            $standardCustomers[$cid] = true;
        } else {
            $standingCustomers[$cid] = true;
        }
    }
    $dailyN = count($dailyCustomers);
    $standingN = count($standingCustomers);
    $standardN = count($standardCustomers);
    $mode = 'none';
    if ($dailyN > 0 && ($standingN > 0 || $standardN > 0)) {
        $mode = 'merged';
    } elseif ($dailyN > 0) {
        $mode = 'dated';
    } elseif ($standingN > 0 || $standardN > 0) {
        $mode = 'standing';
    }

    return [
        'daily_customers' => $dailyN,
        'standing_customers' => $standingN,
        'standard_customers' => $standardN,
        'has_daily' => $dailyN > 0,
        'has_standing' => $standingN > 0,
        'mode' => $mode,
    ];
}

/**
 * Customers contributing effective demand for one product on one delivery date.
 *
 * @return list<array{id:int,name:string,zone:string,quantity:int,source:string,day_of_week:int}>
 */
function bakery_operating_demand_customers_for_product(PDO $db, string $date, int $productId): array
{
    if ($productId <= 0) {
        return [];
    }
    $weekday = bakery_standing_day_from_date($date);
    $out = [];
    foreach (bakery_operating_demand_lines($db, $date, ['product_id' => $productId]) as $line) {
        if ((int)$line['product_id'] !== $productId) {
            continue;
        }
        $out[] = [
            'id' => (int)$line['customer_id'],
            'name' => (string)$line['customer_name'],
            'zone' => (string)($line['customer_zone'] ?? ''),
            'quantity' => (int)$line['quantity'],
            'source' => (string)($line['source'] ?? 'standing'),
            'day_of_week' => $weekday,
        ];
    }
    usort($out, static function ($a, $b) {
        $z = strcasecmp((string)$a['zone'], (string)$b['zone']);
        if ($z !== 0) {
            return $z;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    return $out;
}

/**
 * Aggregate effective operating demand by product for one delivery date.
 *
 * Dated beats standing per customer. Never all-or-nothing per date.
 *
 * @return array{
 *   by_product: array<int,int>,
 *   has_daily: bool,
 *   required_units: int,
 *   product_count: int,
 *   sources: array<int,string>,
 *   mix: array<string,mixed>
 * }
 */
function bakery_operating_demand_by_product(PDO $db, string $date, array $filters = []) {
    $byProduct = [];
    $sources = [];
    $lines = bakery_operating_demand_lines($db, $date, $filters);

    foreach ($lines as $line) {
        $pid = (int)$line['product_id'];
        $byProduct[$pid] = ($byProduct[$pid] ?? 0) + (int)$line['quantity'];
        $src = (string)($line['source'] ?? 'standing');
        if (!isset($sources[$pid])) {
            $sources[$pid] = $src;
        } elseif ($sources[$pid] !== $src) {
            $sources[$pid] = 'mixed';
        }
    }

    $mix = bakery_operating_demand_mix_from_lines($lines);

    return [
        'by_product' => $byProduct,
        'has_daily' => $mix['has_daily'],
        'required_units' => array_sum($byProduct),
        'product_count' => count($byProduct),
        'sources' => $sources,
        'mix' => $mix,
    ];
}
