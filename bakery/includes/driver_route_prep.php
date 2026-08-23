<?php
/**
 * Driver day-before route prep: search candidates for adding a stop.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/driver_assignments.php';

/**
 * @return array{
 *     query:string,
 *     unassigned:list<array<string,mixed>>,
 *     usual:list<array<string,mixed>>,
 *     matches:list<array<string,mixed>>,
 *     other_routes:list<array{driver_id:int,driver_name:string,stops:list<array<string,mixed>>}>,
 *     other_route_count:int,
 *     take_approval:array{required:bool,mode:string,approver_role:string}
 * }
 */
function bakery_driver_plan_search(PDO $db, int $driverId, string $deliveryDate, string $query): array
{
    $deliveryDate = bakery_driver_assert_route_plan_edit($db, $driverId, $deliveryDate);
    $query = trim(preg_replace('/[%_]+/', ' ', $query) ?? '');
    $query = trim(preg_replace('/\s+/', ' ', $query) ?? '');
    $origin = bakery_sfb_ops_origin_clause('c', $db);
    $pieceSelect = '(SELECT COALESCE(SUM(doi.quantity), 0) FROM daily_order_items doi WHERE doi.daily_order_id = do.id)';
    $takeApproval = bakery_driver_plan_take_policy($db);

    $unassigned = [];
    $usual = [];
    $matches = [];
    $otherRoutes = [];

    if ($query === '') {
        $unassignedStmt = $db->prepare(
            "SELECT
                c.id AS customer_id,
                c.name AS customer_name,
                c.address AS customer_address,
                c.zone,
                do.id AS daily_order_id,
                0 AS assigned_driver_id,
                '' AS assigned_driver_name,
                {$pieceSelect} AS pieces
             FROM daily_orders do
             JOIN customers c ON c.id = do.customer_id
             {$origin}
             WHERE do.order_date = ?
               AND c.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM daily_order_assignments doa
                   WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
               )
             ORDER BY c.name
             LIMIT 40"
        );
        $unassignedStmt->execute([$deliveryDate, $deliveryDate]);
        foreach ($unassignedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $unassigned[] = bakery_driver_plan_format_candidate($row, $driverId);
        }

        if (function_exists('table_exists') && table_exists($db, 'standing_routes')) {
            $dayOfWeek = bakery_standing_day_from_date($deliveryDate);
            $usualStmt = $db->prepare(
                "SELECT
                    c.id AS customer_id,
                    c.name AS customer_name,
                    c.address AS customer_address,
                    c.zone,
                    do.id AS daily_order_id,
                    doa.driver_id AS assigned_driver_id,
                    d.name AS assigned_driver_name,
                    {$pieceSelect} AS pieces
                 FROM standing_routes sr
                 JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
                 {$origin}
                 LEFT JOIN daily_orders do ON do.customer_id = c.id AND do.order_date = ?
                 LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id AND doa.delivery_date = ?
                 LEFT JOIN drivers d ON d.id = doa.driver_id
                 WHERE sr.driver_id = ?
                   AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
                   AND (doa.driver_id IS NULL OR doa.driver_id <> ?)
                 ORDER BY COALESCE(sr.route_order, 2147483647), c.name
                 LIMIT 40"
            );
            $usualStmt->execute([$deliveryDate, $deliveryDate, $driverId, $dayOfWeek, $driverId]);
            $seenUnassigned = [];
            foreach ($unassigned as $row) {
                $seenUnassigned[(int)$row['customer_id']] = true;
            }
            foreach ($usualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $formatted = bakery_driver_plan_format_candidate($row, $driverId);
                if (isset($seenUnassigned[$formatted['customer_id']])) {
                    continue;
                }
                $usual[] = $formatted;
            }
        }

        $otherRoutes = bakery_driver_plan_other_routes($db, $driverId, $deliveryDate, $origin, $pieceSelect);
    } else {
        $like = '%' . $query . '%';
        $matchStmt = $db->prepare(
            "SELECT
                c.id AS customer_id,
                c.name AS customer_name,
                c.address AS customer_address,
                c.zone,
                do.id AS daily_order_id,
                doa.driver_id AS assigned_driver_id,
                d.name AS assigned_driver_name,
                {$pieceSelect} AS pieces
             FROM customers c
             LEFT JOIN daily_orders do ON do.customer_id = c.id AND do.order_date = ?
             LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id AND doa.delivery_date = ?
             LEFT JOIN drivers d ON d.id = doa.driver_id
             WHERE c.is_active = 1
               {$origin}
               AND (
                   c.name LIKE ?
                   OR IFNULL(c.address, '') LIKE ?
                   OR IFNULL(c.zone, '') LIKE ?
               )
             ORDER BY c.name
             LIMIT 40"
        );
        $matchStmt->execute([$deliveryDate, $deliveryDate, $like, $like, $like]);
        foreach ($matchStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matches[] = bakery_driver_plan_format_candidate($row, $driverId);
        }
    }

    $otherCount = 0;
    foreach ($otherRoutes as $group) {
        $otherCount += count($group['stops']);
    }

    return [
        'query' => $query,
        'unassigned' => $unassigned,
        'usual' => $usual,
        'matches' => $matches,
        'other_routes' => $otherRoutes,
        'other_route_count' => $otherCount,
        'take_approval' => $takeApproval,
    ];
}

/**
 * Pending/failed stops currently assigned to other drivers, grouped by driver.
 *
 * @return list<array{driver_id:int,driver_name:string,stops:list<array<string,mixed>>}>
 */
function bakery_driver_plan_other_routes(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    string $origin,
    string $pieceSelect
): array {
    $stmt = $db->prepare(
        "SELECT
            c.id AS customer_id,
            c.name AS customer_name,
            c.address AS customer_address,
            c.zone,
            do.id AS daily_order_id,
            doa.driver_id AS assigned_driver_id,
            d.name AS assigned_driver_name,
            {$pieceSelect} AS pieces
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         JOIN drivers d ON d.id = doa.driver_id
         WHERE doa.delivery_date = ?
           AND doa.driver_id <> ?
           AND doa.delivery_status IN ('pending', 'failed')
           AND c.is_active = 1
         ORDER BY d.name, COALESCE(doa.route_order, 2147483647), c.name
         LIMIT 80"
    );
    $stmt->execute([$deliveryDate, $driverId]);

    $groups = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $formatted = bakery_driver_plan_format_candidate($row, $driverId);
        $assignedId = (int)$formatted['assigned_driver_id'];
        if ($assignedId <= 0) {
            continue;
        }
        if (!isset($groups[$assignedId])) {
            $groups[$assignedId] = [
                'driver_id' => $assignedId,
                'driver_name' => (string)$formatted['assigned_driver_name'],
                'stops' => [],
            ];
        }
        $groups[$assignedId]['stops'][] = $formatted;
    }

    return array_values($groups);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function bakery_driver_plan_format_candidate(array $row, int $myDriverId): array
{
    $assignedId = (int)($row['assigned_driver_id'] ?? 0);
    $dailyOrderId = (int)($row['daily_order_id'] ?? 0);
    if ($assignedId === $myDriverId && $assignedId > 0) {
        $state = 'mine';
    } elseif ($assignedId > 0) {
        $state = 'other';
    } elseif ($dailyOrderId > 0) {
        $state = 'unassigned';
    } else {
        $state = 'new';
    }

    return [
        'customer_id' => (int)$row['customer_id'],
        'customer_name' => (string)($row['customer_name'] ?? ''),
        'customer_address' => (string)($row['customer_address'] ?? ''),
        'zone' => (string)($row['zone'] ?? ''),
        'daily_order_id' => $dailyOrderId,
        'assigned_driver_id' => $assignedId,
        'assigned_driver_name' => (string)($row['assigned_driver_name'] ?? ''),
        'pieces' => (int)($row['pieces'] ?? 0),
        'state' => $state,
    ];
}
