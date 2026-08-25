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
        if (!empty($row['assignment_driver_id'])) {
            $customers[$cid]['assignment_driver_id'] = (int)$row['assignment_driver_id'];
        }
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
        'missing_standing_lines' => 0,
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
        if (empty($customer['paused'])
            && !empty($customer['has_standing'])
            && !empty($customer['has_daily'])
        ) {
            foreach ($customer['line_map'] as $line) {
                if ((int)($line['standing_qty'] ?? 0) > 0 && $line['daily_qty'] === null) {
                    $summary['missing_standing_lines']++;
                }
            }
        }
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
        'assignment_driver_id' => isset($standingRoute['driver_id']) ? (int)$standingRoute['driver_id'] : (isset($row['assignment_driver_id']) ? (int)$row['assignment_driver_id'] : null),
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

/**
 * Day-before visit context for dated-order prep.
 *
 * Standing stays the weekday template. Dated quantities on the selected date
 * are what this view edits. Yesterday shows assigned route and/or actual
 * delivery — never rewritten from this helper.
 *
 * @param array{driver_id?:int,zone?:string,customer?:string,product_id?:int,visit?:string} $filters
 * @return array{
 *   date:string,
 *   prior_date:string,
 *   day_name:string,
 *   prior_day_name:string,
 *   day_of_week:int,
 *   prior_day_of_week:int,
 *   summary:array,
 *   customers:array
 * }
 */
function bakery_demand_visit_compare_build(PDO $db, $date, array $filters = []) {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }
    $priorObject = clone $dateObject;
    $priorObject->modify('-1 day');
    $priorDate = $priorObject->format('Y-m-d');

    $driverId = isset($filters['driver_id']) ? (int)$filters['driver_id'] : 0;
    $zoneFilter = isset($filters['zone']) ? trim((string)$filters['zone']) : '';
    $customerFilter = isset($filters['customer']) ? trim((string)$filters['customer']) : '';
    $productId = isset($filters['product_id']) ? (int)$filters['product_id'] : 0;
    $visitFilter = isset($filters['visit']) ? trim((string)$filters['visit']) : 'all';

    $sharedFilters = [
        'zone' => $zoneFilter,
        'customer' => $customerFilter,
        'product_id' => $productId,
        'review' => 'all',
    ];
    $todayReview = bakery_demand_review_build($db, $date, $sharedFilters);
    $priorReview = bakery_demand_review_build($db, $priorDate, $sharedFilters);

    $byId = [];
    foreach ($todayReview['customers'] as $customer) {
        $cid = (int)$customer['customer_id'];
        $byId[$cid] = bakery_demand_visit_empty_row($customer, $date, $priorDate);
        bakery_demand_visit_apply_today($byId[$cid], $customer);
    }
    foreach ($priorReview['customers'] as $customer) {
        $cid = (int)$customer['customer_id'];
        if (!isset($byId[$cid])) {
            $byId[$cid] = bakery_demand_visit_empty_row($customer, $date, $priorDate);
        }
        bakery_demand_visit_apply_prior($byId[$cid], $customer);
    }

    bakery_demand_visit_attach_standing_routes($db, $byId, $date, $priorDate);

    if ($byId === []) {
        return bakery_demand_visit_empty_payload($todayReview, $priorReview, $date, $priorDate);
    }

    $customerIds = array_map('intval', array_keys($byId));
    bakery_demand_visit_attach_standing_days($db, $byId, $customerIds, $date);
    bakery_demand_visit_attach_standing_routes($db, $byId, $date, $priorDate);
    bakery_demand_visit_attach_history($db, $byId, $customerIds, $date, $priorDate);

    $dayLabels = function_exists('bakery_standing_day_labels')
        ? bakery_standing_day_labels()
        : [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    $summary = [
        'today_standing' => 0,
        'today_dated' => 0,
        'prior_assigned' => 0,
        'prior_delivered' => 0,
        'consecutive' => 0,
        'overdue' => 0,
        'prior_missed' => 0,
        'prior_only' => 0,
        'back_to_back' => 0,
        'extra_yesterday' => 0,
        'look' => 0,
    ];

    $dropIds = [];
    foreach ($byId as $cid => $row) {
        $row['lines'] = bakery_demand_visit_merge_lines($row);
        $row['flags'] = bakery_demand_visit_flags($row);
        $row['route_call'] = bakery_demand_visit_route_call($row);
        $row['flags']['is_deviation'] = bakery_demand_visit_is_deviation($row['route_call']);
        $row['last_expected_label'] = bakery_demand_visit_date_label($row['last_expected']['date'] ?? null, $dayLabels);
        $row['last_actual_label'] = bakery_demand_visit_date_label($row['last_actual']['date'] ?? null, $dayLabels);
        $row['prior_kind_label'] = bakery_demand_visit_kind_label($row['prior']['visit_kind']);

        if ($driverId > 0) {
            $todayDriver = (int)($row['today']['driver_id'] ?? 0);
            $priorDriver = (int)($row['prior']['driver_id'] ?? 0);
            $standingDriver = (int)($row['today']['standing_route_driver_id'] ?? 0);
            if ($todayDriver !== $driverId && $priorDriver !== $driverId && $standingDriver !== $driverId) {
                $dropIds[] = $cid;
                continue;
            }
        }

        if (!empty($row['today']['has_standing']) || !empty($row['flags']['on_standing_today'])) {
            $summary['today_standing']++;
        }
        if (!empty($row['today']['has_daily'])) {
            $summary['today_dated']++;
        }
        if (in_array($row['prior']['visit_kind'], ['delivered', 'assigned'], true)) {
            $summary['prior_assigned']++;
        }
        if ($row['prior']['visit_kind'] === 'delivered') {
            $summary['prior_delivered']++;
        }
        if (!empty($row['flags']['consecutive'])) {
            $summary['consecutive']++;
        }
        if (!empty($row['flags']['overdue'])) {
            $summary['overdue']++;
        }
        if (!empty($row['flags']['prior_missed'])) {
            $summary['prior_missed']++;
        }
        if (empty($row['today']['has_standing']) && empty($row['today']['has_daily']) && $row['prior']['visit_kind'] !== 'none') {
            $summary['prior_only']++;
        }
        if (($row['route_call'] ?? '') === 'back_to_back') {
            $summary['back_to_back']++;
        }
        if (($row['route_call'] ?? '') === 'extra_yesterday') {
            $summary['extra_yesterday']++;
        }
        if (!empty($row['flags']['is_deviation'])) {
            $summary['look']++;
        }
        $byId[$cid] = $row;
    }
    foreach ($dropIds as $cid) {
        unset($byId[$cid]);
    }

    $drivers = bakery_demand_visit_group_drivers(array_values($byId));
    foreach ($drivers as $idx => $group) {
        $drivers[$idx]['stops'] = array_values(array_filter(
            $group['stops'],
            static function ($stop) use ($visitFilter) {
                return bakery_demand_visit_matches_filter($stop, $visitFilter);
            }
        ));
    }
    $drivers = array_values(array_filter($drivers, static function ($group) {
        return $group['stops'] !== []
            || (int)$group['standing_count'] > 0
            || (int)$group['prior_count'] > 0;
    }));

    $byId = array_filter($byId, static function ($row) use ($visitFilter) {
        return bakery_demand_visit_matches_filter($row, $visitFilter);
    });

    uasort($byId, static function ($a, $b) {
        $rank = static function (array $row): int {
            if (!empty($row['flags']['overdue'])) {
                return 0;
            }
            if (!empty($row['flags']['prior_missed'])) {
                return 1;
            }
            if (!empty($row['flags']['consecutive'])) {
                return 2;
            }
            if (($row['today']['state'] ?? '') === 'missing_daily') {
                return 3;
            }
            return 4;
        };
        $ra = $rank($a);
        $rb = $rank($b);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    return [
        'date' => $date,
        'prior_date' => $priorDate,
        'day_name' => $todayReview['day_name'],
        'prior_day_name' => $priorReview['day_name'],
        'day_of_week' => (int)$todayReview['day_of_week'],
        'prior_day_of_week' => (int)$priorReview['day_of_week'],
        'summary' => $summary,
        'customers' => array_values($byId),
        'drivers' => $drivers,
    ];
}

function bakery_demand_visit_empty_payload(array $todayReview, array $priorReview, $date, $priorDate) {
    return [
        'date' => $date,
        'prior_date' => $priorDate,
        'day_name' => $todayReview['day_name'],
        'prior_day_name' => $priorReview['day_name'],
        'day_of_week' => (int)$todayReview['day_of_week'],
        'prior_day_of_week' => (int)$priorReview['day_of_week'],
        'summary' => [
            'today_standing' => 0,
            'today_dated' => 0,
            'prior_assigned' => 0,
            'prior_delivered' => 0,
            'consecutive' => 0,
            'overdue' => 0,
            'prior_missed' => 0,
            'prior_only' => 0,
            'back_to_back' => 0,
            'extra_yesterday' => 0,
            'look' => 0,
        ],
        'customers' => [],
        'drivers' => [],
    ];
}

function bakery_demand_visit_empty_row(array $source, $date, $priorDate) {
    return [
        'customer_id' => (int)$source['customer_id'],
        'customer_name' => $source['customer_name'],
        'address' => $source['address'] ?? '',
        'zone_label' => $source['zone_label'] ?? ($source['zone'] ?: 'No Zone'),
        'standing_days' => [],
        'today' => [
            'has_standing' => false,
            'has_daily' => false,
            'standing_units' => 0,
            'daily_units' => 0,
            'daily_order_id' => null,
            'status' => null,
            'assignment_status' => null,
            'state' => 'matches',
            'route_driver_name' => null,
            'driver_id' => null,
            'on_standing_route' => false,
            'standing_route_driver_id' => null,
            'standing_route_driver_name' => null,
            'standing_route_order' => null,
            'is_advanced' => false,
            'paused' => false,
            'line_map' => [],
        ],
        'prior' => [
            'date' => $priorDate,
            'has_standing' => false,
            'has_daily' => false,
            'standing_units' => 0,
            'daily_units' => 0,
            'delivered_units' => 0,
            'assigned' => false,
            'assignment_status' => null,
            'order_status' => null,
            'driver_name' => null,
            'driver_id' => null,
            'visit_kind' => 'none',
            'on_standing_route' => false,
            'line_map' => [],
        ],
        'last_actual' => null,
        'last_expected' => null,
        'lines' => [],
        'flags' => [],
    ];
}

function bakery_demand_visit_apply_today(array &$row, array $customer) {
    $row['today'] = [
        'has_standing' => !empty($customer['has_standing']),
        'has_daily' => !empty($customer['has_daily']),
        'standing_units' => (int)($customer['standing_units'] ?? 0),
        'daily_units' => (int)($customer['daily_units'] ?? 0),
        'daily_order_id' => !empty($customer['daily_order_id']) ? (int)$customer['daily_order_id'] : null,
        'status' => $customer['status'] ?? null,
        'assignment_status' => $customer['assignment_status'] ?? null,
        'state' => $customer['state'] ?? 'matches',
        'route_driver_name' => $customer['route_driver_name'] ?? null,
        'driver_id' => isset($customer['assignment_driver_id']) ? (int)$customer['assignment_driver_id'] : null,
        'on_standing_route' => false,
        'standing_route_driver_id' => null,
        'standing_route_driver_name' => null,
        'standing_route_order' => null,
        'is_advanced' => !empty($customer['is_advanced']),
        'paused' => !empty($customer['paused']),
        'line_map' => $customer['line_map'] ?? [],
    ];
}

function bakery_demand_visit_apply_prior(array &$row, array $customer) {
    $deliveredUnits = 0;
    foreach ($customer['line_map'] ?? [] as $line) {
        if ($line['delivered_quantity'] !== null && $line['delivered_quantity'] !== '') {
            $deliveredUnits += (int)$line['delivered_quantity'];
        } elseif (!empty($customer['has_daily']) && bakery_demand_visit_was_delivered($customer['status'] ?? null, $customer['assignment_status'] ?? null)) {
            $deliveredUnits += (int)($line['daily_qty'] ?? 0);
        }
    }
    $assigned = bakery_demand_visit_was_assigned($customer['assignment_status'] ?? null);
    $delivered = bakery_demand_visit_was_delivered($customer['status'] ?? null, $customer['assignment_status'] ?? null);
    $kind = 'none';
    if ($delivered) {
        $kind = 'delivered';
    } elseif ($assigned) {
        $kind = 'assigned';
    } elseif (!empty($customer['has_daily'])) {
        $kind = 'dated';
    } elseif (!empty($customer['has_standing'])) {
        $kind = 'standing_only';
    }

    $row['prior'] = [
        'date' => $row['prior']['date'],
        'has_standing' => !empty($customer['has_standing']),
        'has_daily' => !empty($customer['has_daily']),
        'standing_units' => (int)($customer['standing_units'] ?? 0),
        'daily_units' => (int)($customer['daily_units'] ?? 0),
        'delivered_units' => $deliveredUnits,
        'assigned' => $assigned,
        'assignment_status' => $customer['assignment_status'] ?? null,
        'order_status' => $customer['status'] ?? null,
        'driver_name' => $customer['route_driver_name'] ?? null,
        'driver_id' => isset($customer['assignment_driver_id']) ? (int)$customer['assignment_driver_id'] : null,
        'visit_kind' => $kind,
        'on_standing_route' => !empty($row['prior']['on_standing_route']),
        'line_map' => $customer['line_map'] ?? [],
    ];
}

function bakery_demand_visit_was_assigned($assignmentStatus) {
    if (!$assignmentStatus) {
        return false;
    }
    return !in_array((string)$assignmentStatus, ['cancelled', 'failed'], true);
}

function bakery_demand_visit_was_delivered($orderStatus, $assignmentStatus) {
    if ($assignmentStatus && (string)$assignmentStatus === 'delivered') {
        return true;
    }
    return $orderStatus && in_array((string)$orderStatus, ['delivered', 'invoiced'], true);
}

function bakery_demand_visit_attach_standing_days(PDO $db, array &$byId, array $customerIds, $date) {
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $stmt = $db->prepare(
        "SELECT customer_id,
                CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END AS dow,
                SUM(quantity) AS units
         FROM standing_orders
         WHERE customer_id IN ({$placeholders})
           AND quantity > 0
         GROUP BY customer_id, dow"
    );
    $stmt->execute($customerIds);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)$row['customer_id'];
        if (!isset($byId[$cid])) {
            continue;
        }
        $dow = (int)$row['dow'];
        $byId[$cid]['standing_days'][$dow] = (int)$row['units'];
    }

    foreach ($byId as $cid => $row) {
        $days = array_keys($row['standing_days']);
        sort($days);
        $expected = bakery_demand_last_expected_standing_date($days, $date);
        $byId[$cid]['last_expected'] = $expected;
    }
}

/**
 * Merge standing_routes for the selected weekday and the day before.
 * Route-only stops (no standing order qty) still belong on the Driver Manager board.
 */
function bakery_demand_visit_attach_standing_routes(PDO $db, array &$byId, $date, $priorDate) {
    if (!table_exists($db, 'standing_routes')) {
        return;
    }
    $todayDow = bakery_standing_day_from_date($date);
    $priorDow = bakery_standing_day_from_date($priorDate);
    $todayClause = bakery_standing_day_in_clause($todayDow);
    $priorClause = bakery_standing_day_in_clause($priorDow);
    $zoneJoin = bakery_customer_zone_join_sql();
    $zoneSelect = "COALESCE(z.name, NULLIF(c.zone, ''), 'No Zone') AS zone_label";

    $sql = "
        SELECT sr.customer_id, sr.driver_id, d.name AS driver_name,
               CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END AS dow,
               sr.route_order, c.name AS customer_name, c.address, c.zone,
               {$zoneSelect}
        FROM standing_routes sr
        JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        {$zoneJoin}
        JOIN drivers d ON d.id = sr.driver_id
        WHERE sr.day_of_week {$todayClause['sql']}
           OR sr.day_of_week {$priorClause['sql']}
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($todayClause['values'], $priorClause['values']));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $route) {
        $cid = (int)$route['customer_id'];
        $dow = (int)$route['dow'];
        if (!isset($byId[$cid])) {
            $byId[$cid] = bakery_demand_visit_empty_row($route, $date, $priorDate);
        }
        if (!isset($byId[$cid]['standing_days'][$dow])) {
            $byId[$cid]['standing_days'][$dow] = 0;
        }
        if ($dow === $todayDow) {
            $byId[$cid]['today']['on_standing_route'] = true;
            $byId[$cid]['today']['standing_route_driver_id'] = (int)$route['driver_id'];
            $byId[$cid]['today']['standing_route_driver_name'] = $route['driver_name'];
            $byId[$cid]['today']['standing_route_order'] = $route['route_order'] !== null ? (int)$route['route_order'] : null;
            if (empty($byId[$cid]['today']['route_driver_name'])) {
                $byId[$cid]['today']['route_driver_name'] = $route['driver_name'];
            }
            if (empty($byId[$cid]['today']['driver_id'])) {
                $byId[$cid]['today']['driver_id'] = (int)$route['driver_id'];
            }
        }
        if ($dow === $priorDow) {
            $byId[$cid]['prior']['on_standing_route'] = true;
        }
    }

    foreach ($byId as $cid => $row) {
        $days = array_keys($row['standing_days']);
        sort($days);
        $byId[$cid]['last_expected'] = bakery_demand_last_expected_standing_date($days, $date);
    }
}

/**
 * Most recent date strictly before $beforeDate whose weekday is in $standingDays (1-7).
 *
 * @param int[] $standingDays
 * @return array{date:string,day_of_week:int}|null
 */
function bakery_demand_last_expected_standing_date(array $standingDays, $beforeDate) {
    $standingDays = array_values(array_unique(array_map('intval', $standingDays)));
    if ($standingDays === []) {
        return null;
    }
    $cursor = DateTime::createFromFormat('!Y-m-d', $beforeDate);
    if (!$cursor) {
        return null;
    }
    for ($i = 0; $i < 21; $i++) {
        $cursor->modify('-1 day');
        $dow = (int)$cursor->format('N');
        if (in_array($dow, $standingDays, true)) {
            return [
                'date' => $cursor->format('Y-m-d'),
                'day_of_week' => $dow,
            ];
        }
    }
    return null;
}

function bakery_demand_visit_attach_history(PDO $db, array &$byId, array $customerIds, $date, $priorDate) {
    $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
    $windowStart = (new DateTimeImmutable($date))->modify('-21 days')->format('Y-m-d');
    $stmt = $db->prepare(
        "SELECT do.customer_id, do.order_date, do.status,
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
                ) AS driver_name,
                (SELECT COALESCE(SUM(doi.quantity), 0) FROM daily_order_items doi WHERE doi.daily_order_id = do.id) AS ordered_units,
                (SELECT COALESCE(SUM(COALESCE(doi.delivered_quantity, doi.quantity)), 0)
                 FROM daily_order_items doi WHERE doi.daily_order_id = do.id) AS delivered_units
         FROM daily_orders do
         WHERE do.customer_id IN ({$placeholders})
           AND do.order_date < ?
           AND do.order_date >= ?
         ORDER BY do.order_date DESC"
    );
    $stmt->execute(array_merge($customerIds, [$date, $windowStart]));

    $seenActual = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $hist) {
        $cid = (int)$hist['customer_id'];
        if (!isset($byId[$cid]) || isset($seenActual[$cid])) {
            continue;
        }
        if (!bakery_demand_visit_was_delivered($hist['status'] ?? null, $hist['assignment_status'] ?? null)) {
            continue;
        }
        $seenActual[$cid] = true;
        $byId[$cid]['last_actual'] = [
            'date' => $hist['order_date'],
            'status' => $hist['status'],
            'assignment_status' => $hist['assignment_status'],
            'driver_name' => $hist['driver_name'],
            'units' => (int)$hist['delivered_units'],
            'is_prior' => $hist['order_date'] === $priorDate,
        ];
    }
}

function bakery_demand_visit_merge_lines(array $row) {
    $merged = [];
    foreach ($row['today']['line_map'] as $pid => $line) {
        $pid = (int)$pid;
        $merged[$pid] = [
            'product_id' => $pid,
            'product_name' => $line['product_name'],
            'item_id' => $line['item_id'] ?? null,
            'standing_qty' => $line['standing_qty'] ?? null,
            'daily_qty' => $line['daily_qty'] ?? null,
            'prior_standing_qty' => null,
            'prior_ordered_qty' => null,
            'prior_delivered_qty' => null,
        ];
    }
    foreach ($row['prior']['line_map'] as $pid => $line) {
        $pid = (int)$pid;
        if (!isset($merged[$pid])) {
            $merged[$pid] = [
                'product_id' => $pid,
                'product_name' => $line['product_name'],
                'item_id' => null,
                'standing_qty' => null,
                'daily_qty' => null,
                'prior_standing_qty' => null,
                'prior_ordered_qty' => null,
                'prior_delivered_qty' => null,
            ];
        }
        $merged[$pid]['prior_standing_qty'] = $line['standing_qty'] ?? null;
        $merged[$pid]['prior_ordered_qty'] = $line['daily_qty'] ?? null;
        $merged[$pid]['prior_delivered_qty'] = ($line['delivered_quantity'] !== null && $line['delivered_quantity'] !== '')
            ? (int)$line['delivered_quantity']
            : null;
        if ($merged[$pid]['product_name'] === null || $merged[$pid]['product_name'] === '') {
            $merged[$pid]['product_name'] = $line['product_name'];
        }
    }
    uasort($merged, static function ($a, $b) {
        return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
    });
    return array_values($merged);
}

function bakery_demand_visit_flags(array $row) {
    $priorKind = $row['prior']['visit_kind'];
    $wentPrior = in_array($priorKind, ['delivered', 'assigned'], true);
    $onStandingToday = !empty($row['today']['on_standing_route']) || !empty($row['today']['has_standing']);
    $onStandingPrior = !empty($row['prior']['on_standing_route']) || !empty($row['prior']['has_standing']);
    $todayExpected = $onStandingToday || !empty($row['today']['has_daily']);
    $lastExpectedDate = $row['last_expected']['date'] ?? null;
    $lastActualDate = $row['last_actual']['date'] ?? null;
    $overdue = $lastExpectedDate !== null && ($lastActualDate === null || $lastActualDate < $lastExpectedDate);

    return [
        'on_standing_today' => $onStandingToday,
        'consecutive' => $onStandingToday && $wentPrior,
        'overdue' => $overdue,
        'prior_missed' => $onStandingPrior && !$wentPrior,
        'prior_only' => !$onStandingToday && $wentPrior,
        'extra_yesterday' => $wentPrior && !$onStandingToday,
        'went_prior' => $wentPrior,
        'today_missing_dated' => $onStandingToday && empty($row['today']['has_daily']),
        'one_off_today' => !empty($row['today']['has_daily']) && !$onStandingToday,
    ];
}

function bakery_demand_visit_route_call(array $row) {
    $flags = $row['flags'] ?? bakery_demand_visit_flags($row);
    if (!empty($flags['overdue'])) {
        return 'overdue';
    }
    if (!empty($flags['consecutive'])) {
        return 'back_to_back';
    }
    if (!empty($flags['extra_yesterday'])) {
        return 'extra_yesterday';
    }
    if (!empty($flags['prior_missed'])) {
        return 'missed_yesterday';
    }
    if (!empty($flags['today_missing_dated'])) {
        return 'not_on_today_route';
    }
    if (!empty($flags['one_off_today'])) {
        return 'one_off_today';
    }
    if (!empty($flags['on_standing_today'])) {
        return 'due';
    }
    return 'other';
}

function bakery_demand_visit_is_deviation($call) {
    return !in_array((string)$call, ['due', 'other'], true);
}

function bakery_demand_visit_matches_filter(array $row, $filter) {
    $flags = $row['flags'] ?? [];
    $call = $row['route_call'] ?? bakery_demand_visit_route_call($row);
    switch ($filter) {
        case 'deviations':
            return bakery_demand_visit_is_deviation($call);
        case 'yesterday_went':
            return !empty($flags['went_prior']);
        case 'yesterday_delivered':
            return ($row['prior']['visit_kind'] ?? '') === 'delivered';
        case 'yesterday_assigned':
            return ($row['prior']['visit_kind'] ?? '') === 'assigned';
        case 'consecutive':
            return !empty($flags['consecutive']);
        case 'overdue':
            return !empty($flags['overdue']);
        case 'prior_missed':
            return !empty($flags['prior_missed']);
        case 'today_standing':
            return !empty($flags['on_standing_today']) || !empty($row['today']['has_standing']);
        case 'prior_only':
        case 'extra_yesterday':
            return !empty($flags['extra_yesterday']) || !empty($flags['prior_only']);
        default:
            return true;
    }
}

function bakery_demand_visit_group_drivers(array $customers) {
    $groups = [];
    foreach ($customers as $row) {
        $driverId = (int)($row['today']['standing_route_driver_id'] ?? 0);
        $driverName = (string)($row['today']['standing_route_driver_name'] ?? '');
        if ($driverId <= 0) {
            $driverId = (int)($row['today']['driver_id'] ?? 0);
            $driverName = (string)($row['today']['route_driver_name'] ?? '');
        }
        if ($driverId <= 0) {
            $driverId = (int)($row['prior']['driver_id'] ?? 0);
            $driverName = (string)($row['prior']['driver_name'] ?? '');
        }
        if (!isset($groups[$driverId])) {
            $groups[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => $driverName !== '' ? $driverName : '',
                'standing_count' => 0,
                'prior_count' => 0,
                'look_count' => 0,
                'standing_names' => [],
                'prior_names' => [],
                'stops' => [],
            ];
        } elseif ($groups[$driverId]['driver_name'] === '' && $driverName !== '') {
            $groups[$driverId]['driver_name'] = $driverName;
        }
        $stopName = [
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
            'route_order' => $row['today']['standing_route_order'] ?? 9999,
        ];
        if (!empty($row['flags']['on_standing_today'])) {
            $groups[$driverId]['standing_count']++;
            $groups[$driverId]['standing_names'][] = $stopName;
        }
        if (!empty($row['flags']['went_prior'])) {
            $groups[$driverId]['prior_count']++;
            $groups[$driverId]['prior_names'][] = array_merge($stopName, [
                'is_extra' => !empty($row['flags']['extra_yesterday']),
                'visit_kind' => (string)($row['prior']['visit_kind'] ?? ''),
            ]);
        }
        if (!empty($row['flags']['is_deviation'])) {
            $groups[$driverId]['look_count']++;
        }
        $groups[$driverId]['stops'][] = $row;
    }

    uasort($groups, static function ($a, $b) {
        if ((int)$a['driver_id'] === 0) {
            return 1;
        }
        if ((int)$b['driver_id'] === 0) {
            return -1;
        }
        $look = ((int)$b['look_count'] <=> (int)$a['look_count']);
        if ($look !== 0) {
            return $look;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });

    foreach ($groups as $id => $group) {
        $byOrder = static function ($a, $b) {
            $orderA = $a['route_order'] ?? 9999;
            $orderB = $b['route_order'] ?? 9999;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        };
        usort($groups[$id]['standing_names'], $byOrder);
        usort($groups[$id]['prior_names'], $byOrder);
        usort($groups[$id]['stops'], static function ($a, $b) {
            $devA = !empty($a['flags']['is_deviation']) ? 0 : 1;
            $devB = !empty($b['flags']['is_deviation']) ? 0 : 1;
            if ($devA !== $devB) {
                return $devA <=> $devB;
            }
            $orderA = $a['today']['standing_route_order'] ?? 9999;
            $orderB = $b['today']['standing_route_order'] ?? 9999;
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        });
    }

    return array_values($groups);
}

function bakery_demand_visit_kind_label($kind) {
    $labels = [
        'delivered' => 'Delivered',
        'assigned' => 'On yesterday\'s route',
        'dated' => 'Dated, not on route',
        'standing_only' => 'Standing, not generated',
        'none' => 'No yesterday stop',
    ];
    return $labels[$kind] ?? (string)$kind;
}

function bakery_demand_visit_date_label($date, array $dayLabels) {
    if (!$date) {
        return null;
    }
    $dow = bakery_standing_day_from_date($date);
    $day = $dayLabels[$dow] ?? date('D', strtotime($date));
    return $day . ' ' . date('M j', strtotime($date));
}

/**
 * Set one dated line for a customer/date. Never writes standing.
 *
 * @return array{changed:bool,created:bool,daily_order_id:?int,quantity:int,item_id:?int}
 */
function bakery_demand_set_dated_quantity(PDO $db, int $customerId, string $date, int $productId, int $quantity): array {
    if (!function_exists('bakery_customer_ensure_daily_order')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }
    if ($customerId <= 0 || $productId <= 0) {
        throw new InvalidArgumentException('Invalid customer or product');
    }
    $quantity = max(0, $quantity);

    $stmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found or inactive');
    }

    $existing = bakery_customer_daily_order_row($db, $customerId, $date);
    if (!$existing && $quantity <= 0) {
        return [
            'changed' => false,
            'created' => false,
            'daily_order_id' => null,
            'quantity' => 0,
            'item_id' => null,
        ];
    }

    $created = false;
    if (!$existing) {
        $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
        $created = true;
    } else {
        $dailyOrderId = (int)$existing['id'];
    }

    $itemStmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $itemStmt->execute([$dailyOrderId, $productId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);
    $oldQty = $item ? (int)$item['quantity'] : null;
    $itemId = $item ? (int)$item['id'] : null;

    if ($quantity <= 0) {
        if ($itemId) {
            $db->prepare('DELETE FROM daily_order_items WHERE id = ?')->execute([$itemId]);
            $itemId = null;
        }
    } else {
        $product = bakery_customer_product_row($db, $productId);
        if (!$product) {
            throw new RuntimeException('Product not found');
        }
        $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
        $lineTotal = round($quantity * $unitPrice, 2);
        if ($itemId) {
            $db->prepare(
                'UPDATE daily_order_items SET quantity = ?, unit_price = ?, line_total = ? WHERE id = ?'
            )->execute([$quantity, $unitPrice, $lineTotal, $itemId]);
        } else {
            $db->prepare(
                'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
            $itemId = (int)$db->lastInsertId();
        }
    }

    bakery_customer_update_daily_total($db, $dailyOrderId);
    $changed = $created || $oldQty === null || $oldQty !== $quantity;
    if ($changed) {
        if (!isset($product) || !is_array($product)) {
            $product = bakery_customer_product_row($db, $productId) ?: [];
        }
        $productName = $product['name'] ?? ('#' . $productId);
        bakery_record_operational_event(
            $db,
            $oldQty === null ? BAKERY_OP_DAILY_ORDER_ITEM_ADDED : BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
            'Visit compare set dated qty for ' . $customer['name'] . ' — ' . $productName,
            [
                'operational_date' => $date,
                'customer_id' => $customerId,
                'daily_order_id' => $dailyOrderId,
                'product_id' => $productId,
                'metadata' => [
                    'product_name' => $productName,
                    'old_quantity' => $oldQty,
                    'new_quantity' => $quantity,
                    'source' => 'visit_compare',
                ],
            ]
        );
    }

    return [
        'changed' => $changed,
        'created' => $created,
        'daily_order_id' => $dailyOrderId,
        'quantity' => $quantity,
        'item_id' => $itemId,
    ];
}

/**
 * Overlay yesterday's assigned/actual quantities onto the selected date's dated order.
 * Creates the dated shell from standing when missing, then writes yesterday's product qtys.
 * Never writes standing.
 *
 * @return array{daily_order_id:int,created:bool,copied:int,source:string}
 */
function bakery_demand_copy_prior_quantities_to_dated(PDO $db, int $customerId, string $date): array {
    if (!function_exists('bakery_customer_ensure_daily_order')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }
    $priorDate = (clone $dateObject)->modify('-1 day')->format('Y-m-d');

    $stmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found or inactive');
    }

    $prior = bakery_customer_daily_order_row($db, $customerId, $priorDate);
    if (!$prior) {
        throw new RuntimeException('No dated order on the previous day to copy');
    }
    $priorItems = bakery_customer_daily_items($db, (int)$prior['id']);
    $deliveredStmt = $db->prepare(
        'SELECT product_id, delivered_quantity FROM daily_order_items WHERE daily_order_id = ?'
    );
    $deliveredStmt->execute([(int)$prior['id']]);
    $deliveredByProduct = [];
    foreach ($deliveredStmt->fetchAll(PDO::FETCH_ASSOC) as $drow) {
        $deliveredByProduct[(int)$drow['product_id']] = $drow['delivered_quantity'];
    }

    $copied = 0;
    $usedActual = false;
    $existing = bakery_customer_daily_order_row($db, $customerId, $date);
    $created = !$existing;
    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);

    foreach ($priorItems as $line) {
        $pid = (int)$line['product_id'];
        $deliveredRaw = $deliveredByProduct[$pid] ?? null;
        if ($deliveredRaw !== null && $deliveredRaw !== '') {
            $qty = (int)$deliveredRaw;
            $usedActual = true;
        } else {
            $qty = (int)$line['quantity'];
        }
        if ($qty <= 0) {
            continue;
        }
        bakery_demand_set_dated_quantity($db, $customerId, $date, $pid, $qty);
        $copied++;
    }
    if ($copied === 0) {
        throw new RuntimeException('Previous day has no copyable quantities');
    }

    bakery_record_operational_event(
        $db,
        BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
        'Copied previous-day quantities onto dated order for ' . $customer['name'],
        [
            'operational_date' => $date,
            'customer_id' => $customerId,
            'daily_order_id' => $dailyOrderId,
            'metadata' => [
                'source' => $usedActual ? 'prior_actual' : 'prior_assigned',
                'prior_date' => $priorDate,
                'copied' => $copied,
                'created' => $created,
            ],
        ]
    );

    return [
        'daily_order_id' => $dailyOrderId,
        'created' => $created,
        'copied' => $copied,
        'source' => $usedActual ? 'prior_actual' : 'prior_assigned',
    ];
}

/**
 * Ensure this customer's dated order exists from standing. Existing dated
 * quantities stay; missing standing products are added. Never writes standing.
 *
 * @return array{daily_order_id:int,created:bool,item_count:int,added:int}
 */
function bakery_demand_apply_standing_to_dated(PDO $db, int $customerId, string $date): array {
    if (!function_exists('bakery_customer_ensure_daily_order')) {
        require_once __DIR__ . '/customer_order_mutations.php';
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }

    $stmt = $db->prepare(
        'SELECT c.* FROM customers c
         WHERE c.id = ? AND c.is_active = 1 '
        . bakery_sfb_ops_origin_clause('c', $db) . '
         LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        throw new RuntimeException('Customer not found or inactive');
    }

    $existing = bakery_customer_daily_order_row($db, $customerId, $date);
    $created = !$existing;
    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
    $added = 0;

    if (!$created) {
        $dayOfWeek = bakery_standing_day_from_date($date);
        $have = [];
        foreach (bakery_customer_daily_items($db, $dailyOrderId) as $item) {
            $have[(int)$item['product_id']] = true;
        }
        foreach (bakery_customer_standing_lines($db, $customerId, $dayOfWeek) as $line) {
            $pid = (int)$line['product_id'];
            $qty = (int)$line['quantity'];
            if ($qty <= 0 || isset($have[$pid])) {
                continue;
            }
            bakery_demand_set_dated_quantity($db, $customerId, $date, $pid, $qty);
            $added++;
        }
    }

    $countStmt = $db->prepare('SELECT COUNT(*) FROM daily_order_items WHERE daily_order_id = ?');
    $countStmt->execute([$dailyOrderId]);
    $itemCount = (int)$countStmt->fetchColumn();

    return [
        'daily_order_id' => $dailyOrderId,
        'created' => $created,
        'item_count' => $itemCount,
        'added' => $added,
    ];
}
