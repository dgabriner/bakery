<?php
/**
 * Closeout Radar — read-only close gates and silent MRP holes for one delivery date.
 *
 * Bake day is not sell/delivery day. Callers must label the date as a delivery date.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @return array<string, mixed>
 */
function bakery_closeout_radar_build(PDO $db, $deliveryDate) {
    $deliveryDate = bakery_dashboard_resolve_date($deliveryDate);
    $weekday = bakery_standing_day_from_date($deliveryDate);
    $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    $snapshot = bakery_dashboard_ops_snapshot($db, $deliveryDate);
    $hasDaily = $snapshot['daily_order_count'] > 0;
    $hasStanding = $snapshot['standing_order_lines'] > 0;
    $hasDemand = $hasDaily || $hasStanding;

    $gates = [
        bakery_closeout_radar_demand_gate($snapshot, $deliveryDate),
        bakery_closeout_radar_unassigned_gate($snapshot, $deliveryDate),
        bakery_closeout_radar_production_plan_gate($db, $deliveryDate, $hasDemand),
        bakery_closeout_radar_pickup_load_gate($db, $deliveryDate),
        bakery_closeout_radar_route_closeout_gate($db, $deliveryDate),
        bakery_closeout_radar_pod_gate($db, $deliveryDate),
    ];

    $blocked = null;
    foreach ($gates as $gate) {
        if ($gate['status'] === 'blocked') {
            $blocked = $gate;
            break;
        }
    }

    return [
        'delivery_date' => $deliveryDate,
        'weekday' => $weekday,
        'weekday_label' => $dayNames[$weekday] ?? date('l', strtotime($deliveryDate)),
        'can_close' => $blocked === null,
        'verdict' => $blocked === null ? 'yes' : 'not_yet',
        'blocking_reason' => $blocked === null
            ? 'Close gates are clear for this delivery date.'
            : ($blocked['label'] . ' — ' . $blocked['detail']),
        'gates' => $gates,
        'mrp_holes' => [
            'missing_weights' => bakery_closeout_radar_missing_weights($db, $deliveryDate, $hasDaily),
            'empty_formulas' => bakery_closeout_radar_empty_formulas($db, $deliveryDate, $hasDaily),
        ],
        'snapshot' => $snapshot,
    ];
}

/**
 * @param array<string, mixed> $radar
 * @return array<string, mixed>|null
 */
function bakery_closeout_radar_gate(array $radar, $id) {
    foreach ($radar['gates'] ?? [] as $gate) {
        if (($gate['id'] ?? '') === $id) {
            return $gate;
        }
    }
    return null;
}

function bakery_closeout_radar_week_start($date) {
    $parsed = DateTime::createFromFormat('!Y-m-d', (string)$date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        $parsed = new DateTime('monday this week');
    }
    $parsed->modify('monday this week');
    return $parsed->format('Y-m-d');
}

function bakery_closeout_radar_make_gate($id, $label, $status, $count, $detail, $href) {
    return [
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'severity' => $status === 'blocked' ? 'blocking' : 'ok',
        'count' => (int)$count,
        'detail' => $detail,
        'href' => $href,
    ];
}

function bakery_closeout_radar_page_href($script, $deliveryDate, array $query = []) {
    $query = array_merge(['date' => $deliveryDate], $query);
    return $script . '?' . http_build_query($query);
}

/**
 * @param array<string, mixed> $snapshot
 */
function bakery_closeout_radar_demand_gate(array $snapshot, $deliveryDate) {
    $standing = (int)$snapshot['standing_order_lines'];
    $daily = (int)$snapshot['daily_order_count'];
    $href = bakery_closeout_radar_page_href('daily_orders.php', $deliveryDate);
    if ($daily > 0) {
        return bakery_closeout_radar_make_gate(
            'demand',
            'Confirm Demand not done',
            'clear',
            $daily,
            $daily . ' daily order' . ($daily === 1 ? '' : 's') . ' generated for this delivery date.',
            $href
        );
    }
    if ($standing > 0) {
        return bakery_closeout_radar_make_gate(
            'demand',
            'Confirm Demand not done',
            'blocked',
            $standing,
            $standing . ' standing order line' . ($standing === 1 ? '' : 's')
                . ' exist for this weekday, but daily orders have not been generated.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'demand',
        'Confirm Demand not done',
        'clear',
        0,
        'No standing demand and no daily orders for this delivery date.',
        $href
    );
}

/**
 * @param array<string, mixed> $snapshot
 */
function bakery_closeout_radar_unassigned_gate(array $snapshot, $deliveryDate) {
    $count = (int)$snapshot['unassigned_orders'];
    $href = bakery_closeout_radar_page_href('driver_assignment.php', $deliveryDate);
    if ($count > 0) {
        return bakery_closeout_radar_make_gate(
            'unassigned',
            'Leftover unassigned orders',
            'blocked',
            $count,
            $count . ' daily order' . ($count === 1 ? '' : 's') . ' have no driver assignment.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'unassigned',
        'Leftover unassigned orders',
        'clear',
        0,
        'Every daily order for this delivery date has a driver, or there are no daily orders.',
        $href
    );
}

function bakery_closeout_radar_production_plan_gate(PDO $db, $deliveryDate, $hasDemand) {
    $href = 'production_center.php?week=' . urlencode(bakery_closeout_radar_week_start($deliveryDate));
    if (!$hasDemand) {
        return bakery_closeout_radar_make_gate(
            'production_plan',
            'Production plan not committed',
            'clear',
            0,
            'No demand for this delivery date, so a production plan is not required.',
            $href
        );
    }
    if (!table_exists($db, 'production_plan_items')) {
        return bakery_closeout_radar_make_gate(
            'production_plan',
            'Production plan not committed',
            'blocked',
            1,
            'Saved production plans are not installed. Open Production Center after migrations.',
            $href
        );
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM production_plan_items WHERE delivery_date = ?');
    $stmt->execute([$deliveryDate]);
    $saved = (int)$stmt->fetchColumn();
    if ($saved === 0) {
        return bakery_closeout_radar_make_gate(
            'production_plan',
            'Production plan not committed',
            'blocked',
            1,
            'No saved batch targets for this delivery date.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'production_plan',
        'Production plan not committed',
        'clear',
        $saved,
        $saved . ' saved production target' . ($saved === 1 ? '' : 's') . ' for this delivery date.',
        $href
    );
}

function bakery_closeout_radar_pickup_load_gate(PDO $db, $deliveryDate) {
    $href = bakery_closeout_radar_page_href('driver_load.php', $deliveryDate);
    if (!table_exists($db, 'daily_order_assignments')) {
        return bakery_closeout_radar_make_gate(
            'pickup_load',
            'Pickup load not saved',
            'clear',
            0,
            'Driver assignments are not available.',
            $href
        );
    }
    $assignedStmt = $db->prepare("
        SELECT COUNT(DISTINCT driver_id)
        FROM daily_order_assignments
        WHERE delivery_date = ? AND delivery_status <> 'cancelled'
    ");
    $assignedStmt->execute([$deliveryDate]);
    $assignedDrivers = (int)$assignedStmt->fetchColumn();
    if ($assignedDrivers === 0) {
        return bakery_closeout_radar_make_gate(
            'pickup_load',
            'Pickup load not saved',
            'clear',
            0,
            'No assigned drivers yet, so pickup loads are not required.',
            $href
        );
    }
    $missing = $assignedDrivers;
    if (table_exists($db, 'driver_loads')) {
        $missingStmt = $db->prepare("
            SELECT COUNT(DISTINCT doa.driver_id)
            FROM daily_order_assignments doa
            WHERE doa.delivery_date = ?
              AND doa.delivery_status <> 'cancelled'
              AND NOT EXISTS (
                  SELECT 1 FROM driver_loads dl
                  WHERE dl.driver_id = doa.driver_id
                    AND dl.delivery_date = doa.delivery_date
              )
        ");
        $missingStmt->execute([$deliveryDate]);
        $missing = (int)$missingStmt->fetchColumn();
    }
    if ($missing > 0) {
        return bakery_closeout_radar_make_gate(
            'pickup_load',
            'Pickup load not saved',
            'blocked',
            $missing,
            $missing . ' assigned driver' . ($missing === 1 ? '' : 's') . ' have no saved pickup load.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'pickup_load',
        'Pickup load not saved',
        'clear',
        $assignedDrivers,
        'Pickup loads are saved for every assigned driver.',
        $href
    );
}

function bakery_closeout_radar_route_closeout_gate(PDO $db, $deliveryDate) {
    $href = bakery_closeout_radar_page_href('route_manager.php', $deliveryDate);
    if (!table_exists($db, 'daily_order_assignments')) {
        return bakery_closeout_radar_make_gate(
            'route_closeout',
            'Route closeout missing',
            'clear',
            0,
            'Driver assignments are not available.',
            $href
        );
    }
    $openStmt = $db->prepare("
        SELECT COUNT(DISTINCT driver_id)
        FROM daily_order_assignments
        WHERE delivery_date = ?
          AND delivery_status IN ('pending', 'in_transit', 'failed')
    ");
    $openStmt->execute([$deliveryDate]);
    $openRoutes = (int)$openStmt->fetchColumn();
    if ($openRoutes > 0) {
        return bakery_closeout_radar_make_gate(
            'route_closeout',
            'Route closeout missing',
            'blocked',
            $openRoutes,
            $openRoutes . ' route' . ($openRoutes === 1 ? '' : 's') . ' still have open stops.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'route_closeout',
        'Route closeout missing',
        'clear',
        0,
        'No assigned routes still have open stops.',
        $href
    );
}

function bakery_closeout_radar_pod_gate(PDO $db, $deliveryDate) {
    $href = bakery_closeout_radar_page_href('route_manager.php', $deliveryDate);
    if (!table_exists($db, 'daily_order_assignments')) {
        return bakery_closeout_radar_make_gate(
            'pod_incomplete',
            'Routes with no POD / incomplete delivery',
            'clear',
            0,
            'Driver assignments are not available.',
            $href
        );
    }
    $incompleteStmt = $db->prepare("
        SELECT COUNT(*)
        FROM daily_order_assignments
        WHERE delivery_date = ?
          AND delivery_status IN ('pending', 'in_transit', 'failed')
    ");
    $incompleteStmt->execute([$deliveryDate]);
    $incomplete = (int)$incompleteStmt->fetchColumn();

    $noPod = 0;
    if (table_exists($db, 'driver_photos') && table_exists($db, 'daily_orders')) {
        $noPodStmt = $db->prepare("
            SELECT COUNT(*)
            FROM daily_order_assignments doa
            JOIN daily_orders do ON do.id = doa.daily_order_id
            WHERE doa.delivery_date = ?
              AND doa.delivery_status = 'delivered'
              AND NOT EXISTS (
                  SELECT 1 FROM driver_photos dp
                  WHERE dp.driver_id = doa.driver_id
                    AND dp.customer_id = do.customer_id
                    AND dp.delivery_date = doa.delivery_date
              )
        ");
        $noPodStmt->execute([$deliveryDate]);
        $noPod = (int)$noPodStmt->fetchColumn();
    }

    $count = $incomplete + $noPod;
    if ($count > 0) {
        return bakery_closeout_radar_make_gate(
            'pod_incomplete',
            'Routes with no POD / incomplete delivery',
            'blocked',
            $count,
            $incomplete . ' incomplete stop' . ($incomplete === 1 ? '' : 's')
                . ', ' . $noPod . ' delivered without proof of delivery.',
            $href
        );
    }
    return bakery_closeout_radar_make_gate(
        'pod_incomplete',
        'Routes with no POD / incomplete delivery',
        'clear',
        0,
        'No incomplete stops and no delivered stops missing proof of delivery.',
        $href
    );
}

/**
 * @return int[]
 */
function bakery_closeout_radar_demand_product_ids(PDO $db, $deliveryDate, $hasDaily) {
    if ($hasDaily) {
        $stmt = $db->prepare("
            SELECT DISTINCT doi.product_id
            FROM daily_order_items doi
            JOIN daily_orders do ON doi.daily_order_id = do.id
            WHERE do.order_date = ? AND doi.quantity > 0
        ");
        $stmt->execute([$deliveryDate]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!table_exists($db, 'standing_orders')) {
        return [];
    }
    $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($deliveryDate));
    $stmt = $db->prepare("
        SELECT DISTINCT product_id
        FROM standing_orders
        WHERE quantity > 0 AND day_of_week {$dayClause['sql']}
    ");
    $stmt->execute($dayClause['values']);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return array<int, array{id:int,name:string,product_line:string,href:string}>
 */
function bakery_closeout_radar_missing_weights(PDO $db, $deliveryDate, $hasDaily) {
    if (!table_exists($db, 'products')) {
        return [];
    }
    $byId = [];
    $demandIds = bakery_closeout_radar_demand_product_ids($db, $deliveryDate, $hasDaily);
    if ($demandIds) {
        $placeholders = implode(',', array_fill(0, count($demandIds), '?'));
        $stmt = $db->prepare("
            SELECT p.id, p.name, pl.name AS product_line_name, p.weight_grams
            FROM products p
            LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
            LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
            WHERE p.id IN ($placeholders)
              AND (p.weight_grams IS NULL OR p.weight_grams <= 0)
        ");
        $stmt->execute($demandIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['id']] = $row;
        }
    }
    if (table_exists($db, 'product_lines')) {
        $stmt = $db->query("
            SELECT p.id, p.name, pl.name AS product_line_name, p.weight_grams
            FROM products p
            LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
            LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
            WHERE pl.name = 'Pan Dulce'
              AND (p.weight_grams IS NULL OR p.weight_grams <= 0)
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byId[(int)$row['id']] = $row;
        }
    }
    $holes = [];
    foreach ($byId as $row) {
        $holes[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'product_line' => (string)($row['product_line_name'] ?? ''),
            'href' => 'products.php',
        ];
    }
    usort($holes, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $holes;
}

/**
 * @return array<int, array{id:int,name:string,href:string}>
 */
function bakery_closeout_radar_empty_formulas(PDO $db, $deliveryDate, $hasDaily) {
    if (!table_exists($db, 'dough_types')) {
        return [];
    }
    $demandIds = bakery_closeout_radar_demand_product_ids($db, $deliveryDate, $hasDaily);
    $sql = "
        SELECT dt.id, dt.name, COALESCE(SUM(fi.percentage), 0) AS total_percentage
        FROM dough_types dt
        INNER JOIN products p ON p.dough_type_id = dt.id
        LEFT JOIN formula_ingredients fi ON fi.dough_type_id = dt.id
    ";
    $params = [];
    if ($demandIds) {
        $placeholders = implode(',', array_fill(0, count($demandIds), '?'));
        $sql .= " WHERE p.id IN ($placeholders)";
        $params = $demandIds;
    }
    $sql .= ' GROUP BY dt.id, dt.name HAVING total_percentage <= 0 ORDER BY dt.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $holes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $holes[] = [
            'id' => $id,
            'name' => (string)$row['name'],
            'href' => 'formulas.php#formula-' . $id,
        ];
    }
    return $holes;
}
