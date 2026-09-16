<?php
/**
 * Store-by-store standing route analysis: history vs standing vs leftover.
 * Writes stay on standing_routes.php — dated orders still beat standing per customer.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sf_baker.php';

/**
 * Last 56 completed days (yesterday back). Future generated assignments are excluded.
 *
 * @return array{0:string,1:string}
 */
function bakery_standing_route_history_bounds(): array
{
    $to = new DateTimeImmutable('yesterday');
    $from = $to->modify('-55 days');
    return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

function bakery_standing_zone_key(string $zone): string
{
    $z = strtolower(trim($zone));
    if ($z === '' || $z === 'no zone') {
        return 'unzoned';
    }
    if (strpos($z, 'centro') !== false) {
        return 'centro';
    }
    if (strpos($z, 'mission') !== false) {
        return 'mission';
    }
    if (strpos($z, 'daly') !== false) {
        return 'daly';
    }
    if (strpos($z, 'east') !== false) {
        return 'east';
    }
    if (strpos($z, 'north') !== false) {
        return 'north';
    }
    if (strpos($z, 'sour') !== false) {
        return 'sour';
    }
    return 'other';
}

function bakery_standing_zone_is_fuzzy(int $dow, string $zoneKey): bool
{
    return ($dow === 2 || $dow === 6) && $zoneKey === 'mission';
}

/**
 * Geography fallback when history is thin. Null = do not guess (fuzzy / unknown).
 */
function bakery_standing_zone_fallback_name(int $dow, string $zoneKey): ?string
{
    switch ($dow) {
        case 1:
            return 'marcos';
        case 2:
            if ($zoneKey === 'centro') {
                return 'marcos';
            }
            if (in_array($zoneKey, ['daly', 'east', 'north'], true)) {
                return 'sergio';
            }
            return null;
        case 3:
            if ($zoneKey === 'centro') {
                return 'marcos';
            }
            if (in_array($zoneKey, ['daly', 'east', 'north', 'sour', 'mission'], true)) {
                return 'sergio';
            }
            return null;
        case 4:
            if ($zoneKey === 'centro') {
                return 'marcos';
            }
            if (in_array($zoneKey, ['daly', 'east', 'north', 'mission'], true)) {
                return 'sergio';
            }
            return null;
        case 5:
            if ($zoneKey === 'centro') {
                return 'marcos';
            }
            if (in_array($zoneKey, ['daly', 'east', 'mission'], true)) {
                return 'sergio';
            }
            return null;
        case 6:
            if ($zoneKey === 'centro') {
                return 'marcos';
            }
            if (in_array($zoneKey, ['daly', 'east', 'north'], true)) {
                return 'sergio';
            }
            if ($zoneKey === 'sour') {
                return 'marisol';
            }
            return null;
        case 7:
            if (in_array($zoneKey, ['mission', 'sour'], true)) {
                return 'marisol';
            }
            if ($zoneKey === 'centro') {
                return 'laura';
            }
            return null;
        default:
            return null;
    }
}

/**
 * @param array<string,int> $driversByName lowercase name => id
 * @param list<array{driver_id:int,name:string,visits:int}> $mix
 * @return array{
 *   status:string,
 *   suggest_driver_id:?int,
 *   reason_key:string,
 *   reason_params:array<string,string|int>,
 *   applyable:bool,
 *   usual_share:float,
 *   usual_visits:int,
 *   usual_driver_id:?int,
 *   usual_name:?string
 * }
 */
function bakery_standing_route_decide(
    ?int $standingDriverId,
    array $mix,
    int $dow,
    string $zone,
    bool $hasStandingOrders,
    ?int $juanId,
    array $driversByName
): array {
    $zoneKey = bakery_standing_zone_key($zone);
    $total = 0;
    foreach ($mix as $row) {
        $total += (int)$row['visits'];
    }
    usort($mix, static function ($a, $b) {
        return ((int)$b['visits'] <=> (int)$a['visits']);
    });
    $top = $mix[0] ?? null;
    $share = ($top && $total > 0) ? ((int)$top['visits'] / $total) : 0.0;
    $usualId = null;
    $usualName = null;
    $usualVisits = 0;
    if ($top && $total >= 2 && $share >= 0.70) {
        $usualId = (int)$top['driver_id'];
        $usualName = (string)$top['name'];
        $usualVisits = (int)$top['visits'];
    }

    $fallbackName = bakery_standing_zone_fallback_name($dow, $zoneKey);
    $fallbackId = ($fallbackName !== null && isset($driversByName[$fallbackName]))
        ? (int)$driversByName[$fallbackName]
        : null;
    $fuzzy = bakery_standing_zone_is_fuzzy($dow, $zoneKey);

    $base = [
        'usual_share' => $share,
        'usual_visits' => $usualVisits,
        'usual_driver_id' => $usualId,
        'usual_name' => $usualName,
    ];

    if ($juanId !== null && $usualId !== null && $usualId === $juanId) {
        return array_merge($base, [
            'status' => 'fill',
            'suggest_driver_id' => null,
            'reason_key' => 'standing_routes.reason_fill',
            'reason_params' => [],
            'applyable' => false,
        ]);
    }

    $suggestId = null;
    $reasonKey = 'standing_routes.reason_empty';
    $reasonParams = [];

    if ($usualId !== null && $usualId !== $juanId) {
        $suggestId = $usualId;
        $reasonKey = 'standing_routes.reason_usual';
        $reasonParams = [
            'driver' => (string)$usualName,
            'visits' => $usualVisits,
            'total' => $total,
        ];
    } elseif ($fuzzy) {
        $reasonKey = 'standing_routes.reason_fuzzy';
    } elseif ($fallbackId !== null && $fallbackId !== $juanId) {
        $suggestId = $fallbackId;
        $reasonKey = 'standing_routes.reason_zone';
        $reasonParams = ['driver' => $fallbackName !== null ? ucfirst($fallbackName) : ''];
    } elseif ($total > 0 && $share < 0.70) {
        $reasonKey = 'standing_routes.reason_split';
    } elseif ($hasStandingOrders && $standingDriverId === null) {
        $reasonKey = 'standing_routes.reason_orders';
    }

    if ($suggestId === null) {
        $status = 'empty';
        if ($standingDriverId !== null) {
            $status = 'keep';
            $reasonKey = 'standing_routes.reason_keep';
        } elseif ($fuzzy || $reasonKey === 'standing_routes.reason_split' || $reasonKey === 'standing_routes.reason_fuzzy') {
            $status = 'ask';
        } elseif ($hasStandingOrders) {
            $status = 'orders';
        }
        return array_merge($base, [
            'status' => $status,
            'suggest_driver_id' => null,
            'reason_key' => $reasonKey,
            'reason_params' => $reasonParams,
            'applyable' => false,
        ]);
    }

    if ($standingDriverId === $suggestId) {
        return array_merge($base, [
            'status' => 'match',
            'suggest_driver_id' => $suggestId,
            'reason_key' => $reasonKey,
            'reason_params' => $reasonParams,
            'applyable' => false,
        ]);
    }
    if ($standingDriverId === null) {
        return array_merge($base, [
            'status' => 'add',
            'suggest_driver_id' => $suggestId,
            'reason_key' => $reasonKey,
            'reason_params' => $reasonParams,
            'applyable' => true,
        ]);
    }
    return array_merge($base, [
        'status' => 'change',
        'suggest_driver_id' => $suggestId,
        'reason_key' => $reasonKey,
        'reason_params' => $reasonParams,
        'applyable' => true,
    ]);
}

function bakery_standing_route_save(PDO $db, int $customerId, int $driverId, int $dayOfWeek): void
{
    bakery_require_role(['administrator', 'manager']);
    $dayOfWeek = bakery_normalize_standing_day($dayOfWeek);
    if ($customerId < 1 || $dayOfWeek < 1 || $dayOfWeek > 7) {
        throw new InvalidArgumentException('Invalid standing route');
    }
    if (!bakery_sfb_ops_customer_allowed($db, $customerId)) {
        throw new RuntimeException('Synthetic SF Bakers cannot be added to standing routes');
    }
    if ($driverId > 0) {
        $driver = bakery_get_driver_by_id($db, $driverId);
        if (!$driver) {
            throw new RuntimeException('Driver not found');
        }
        if ((int)($driver['archived'] ?? 0) === 1) {
            throw new RuntimeException('Cannot assign standing routes to an archived driver');
        }
    }

    $dayClause = $dayOfWeek === 7 ? 'IN (0, 7)' : '= ?';
    $stmt = $db->prepare("DELETE FROM standing_routes WHERE customer_id = ? AND day_of_week $dayClause");
    $stmt->execute($dayOfWeek === 7 ? [$customerId] : [$customerId, $dayOfWeek]);

    if ($driverId > 0) {
        $stmt = $db->prepare(
            'INSERT INTO standing_routes (driver_id, customer_id, day_of_week) VALUES (?, ?, ?)'
        );
        $stmt->execute([$driverId, $customerId, $dayOfWeek]);
    }
}

/**
 * @return array{
 *   bounds: array{0:string,1:string},
 *   stores: list<array<string,mixed>>,
 *   summary: array<string,int>
 * }
 */
function bakery_standing_route_store_analysis(PDO $db): array
{
    [$from, $to] = bakery_standing_route_history_bounds();
    $origin = bakery_sfb_ops_origin_clause('c', $db);

    $drivers = bakery_get_drivers($db);
    $driversById = [];
    $driversByName = [];
    $juanId = null;
    foreach ($drivers as $driver) {
        $id = (int)$driver['id'];
        $name = (string)$driver['name'];
        $driversById[$id] = $name;
        $driversByName[strtolower($name)] = $id;
        if (strtolower($name) === 'juan') {
            $juanId = $id;
        }
    }

    $customers = $db->query("
        SELECT c.id, c.name, COALESCE(c.zone, '') AS zone
        FROM customers c
        WHERE c.is_active = 1 $origin
        ORDER BY
            CASE WHEN c.zone IS NULL OR c.zone = '' THEN 'ZZZ' ELSE c.zone END,
            c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $standing = [];
    $standingRows = $db->query("
        SELECT sr.customer_id, sr.driver_id, sr.day_of_week
        FROM standing_routes sr
        JOIN customers c ON c.id = sr.customer_id
        WHERE 1=1 $origin
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($standingRows as $row) {
        $day = bakery_normalize_standing_day((int)$row['day_of_week']);
        $standing[(int)$row['customer_id']][$day] = (int)$row['driver_id'];
    }

    $orderDays = [];
    $orderUnits = [];
    if (table_exists($db, 'standing_orders')) {
        $orderRows = $db->query("
            SELECT so.customer_id, so.day_of_week, SUM(so.quantity) AS units
            FROM standing_orders so
            JOIN customers c ON c.id = so.customer_id
            WHERE so.quantity > 0 $origin
            GROUP BY so.customer_id, so.day_of_week
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orderRows as $row) {
            $day = bakery_normalize_standing_day((int)$row['day_of_week']);
            $cid = (int)$row['customer_id'];
            $orderDays[$cid][$day] = true;
            $orderUnits[$cid][$day] = (int)$row['units'];
        }
    }

    $history = [];
    $histStmt = $db->prepare("
        SELECT WEEKDAY(doa.delivery_date) + 1 AS dow,
               ord.customer_id,
               doa.driver_id,
               COUNT(*) AS visits
        FROM daily_order_assignments doa
        JOIN daily_orders ord ON ord.id = doa.daily_order_id
        JOIN customers c ON c.id = ord.customer_id
        WHERE doa.delivery_date BETWEEN ? AND ?
          AND COALESCE(doa.delivery_status, 'pending') <> 'cancelled'
          $origin
        GROUP BY WEEKDAY(doa.delivery_date) + 1, ord.customer_id, doa.driver_id
    ");
    $histStmt->execute([$from, $to]);
    foreach ($histStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)$row['customer_id'];
        $dow = (int)$row['dow'];
        $did = (int)$row['driver_id'];
        $history[$cid][$dow][] = [
            'driver_id' => $did,
            'name' => $driversById[$did] ?? ('#' . $did),
            'visits' => (int)$row['visits'],
        ];
    }

    $summary = [
        'stores' => 0,
        'match' => 0,
        'add' => 0,
        'change' => 0,
        'ask' => 0,
        'orders' => 0,
        'fill' => 0,
    ];
    $stores = [];
    foreach ($customers as $customer) {
        $cid = (int)$customer['id'];
        $hasAny = isset($standing[$cid]) || isset($orderDays[$cid]) || isset($history[$cid]);
        if (!$hasAny) {
            continue;
        }
        $days = [];
        $storeAdd = 0;
        for ($dow = 1; $dow <= 7; $dow++) {
            $standingId = $standing[$cid][$dow] ?? null;
            $mix = $history[$cid][$dow] ?? [];
            $hasOrders = !empty($orderDays[$cid][$dow]);
            $cell = bakery_standing_route_decide(
                $standingId,
                $mix,
                $dow,
                (string)$customer['zone'],
                $hasOrders,
                $juanId,
                $driversByName
            );
            $cell['standing_driver_id'] = $standingId;
            $cell['standing_name'] = $standingId ? ($driversById[$standingId] ?? null) : null;
            $cell['has_orders'] = $hasOrders;
            $cell['order_units'] = $orderUnits[$cid][$dow] ?? 0;
            $cell['mix'] = $mix;
            $days[$dow] = $cell;
            $st = $cell['status'];
            if (isset($summary[$st])) {
                $summary[$st]++;
            }
            if ($cell['applyable']) {
                $storeAdd++;
            }
        }
        $summary['stores']++;
        $stores[] = [
            'id' => $cid,
            'name' => (string)$customer['name'],
            'zone' => (string)$customer['zone'],
            'days' => $days,
            'applyable_count' => $storeAdd,
        ];
    }

    return [
        'bounds' => [$from, $to],
        'stores' => $stores,
        'summary' => $summary,
        'drivers' => $drivers,
    ];
}

function bakery_standing_route_apply_suggestions(PDO $db, int $customerId, ?int $onlyDay = null): int
{
    bakery_require_role(['administrator', 'manager']);
    $analysis = bakery_standing_route_store_analysis($db);
    $applied = 0;
    foreach ($analysis['stores'] as $store) {
        if ((int)$store['id'] !== $customerId) {
            continue;
        }
        foreach ($store['days'] as $dow => $cell) {
            if ($onlyDay !== null && (int)$dow !== bakery_normalize_standing_day($onlyDay)) {
                continue;
            }
            if (!$cell['applyable'] || empty($cell['suggest_driver_id'])) {
                continue;
            }
            bakery_standing_route_save($db, $customerId, (int)$cell['suggest_driver_id'], (int)$dow);
            $applied++;
        }
    }
    return $applied;
}
