<?php
/**
 * Production → order assignment.
 *
 * After a manager records how much of a product they have (planned target,
 * or on-hand if there is no plan), they assign those units to customers.
 * Default write is standing — the weekly template — plus the selected day's
 * dated line so today matches. Same-weekday dated lines later in the demand
 * horizon follow when they still match the old standing quantity (unedited
 * template copies). One-off daily adjustments write dated quantities only.
 *
 * Dated still beats standing per customer. Standing never rewrites past
 * dated orders. Van / delivered / invoiced orders are skipped, not forced.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/customer_notifications.php';

if (!defined('BAKERY_OP_PRODUCTION_ASSIGNED')) {
    define('BAKERY_OP_PRODUCTION_ASSIGNED', 'production_assigned');
}

if (!defined('BAKERY_OP_PRODUCTION_CUT')) {
    define('BAKERY_OP_PRODUCTION_CUT', 'production_cut');
}

/**
 * Orders the van already has, or that are done, cannot be reassigned.
 */
function bakery_production_assign_order_is_locked(?string $orderStatus, ?string $assignmentStatus = null): bool
{
    $orderDone = ['out_for_delivery', 'delivered', 'invoiced'];
    $assignDone = ['in_transit', 'delivered', 'failed', 'cancelled'];
    if ($orderStatus !== null && $orderStatus !== '' && in_array($orderStatus, $orderDone, true)) {
        return true;
    }
    if ($assignmentStatus !== null && $assignmentStatus !== '' && in_array($assignmentStatus, $assignDone, true)) {
        return true;
    }
    return false;
}

/**
 * Split $pool across current customer quantities with largest-remainder rounding.
 *
 * @param list<array{quantity?:int}> $customers
 * @return list<array<string,mixed>>
 */
function bakery_production_assign_recommend(array $customers, int $pool): array
{
    $pool = max(0, $pool);
    $n = count($customers);
    if ($n === 0) {
        return [];
    }

    $total = 0;
    foreach ($customers as $row) {
        $total += max(0, (int)($row['quantity'] ?? 0));
    }

    $shares = array_fill(0, $n, 0);
    if ($total <= 0 || $pool <= 0) {
        foreach ($customers as $i => $row) {
            $customers[$i]['recommended'] = 0;
        }
        return $customers;
    }

    $used = 0;
    $remainders = [];
    foreach ($customers as $i => $row) {
        $qty = max(0, (int)($row['quantity'] ?? 0));
        $raw = ($qty / $total) * $pool;
        $floor = (int)floor($raw);
        $shares[$i] = $floor;
        $used += $floor;
        $remainders[$i] = $raw - $floor;
    }
    arsort($remainders);
    $left = $pool - $used;
    foreach (array_keys($remainders) as $i) {
        if ($left <= 0) {
            break;
        }
        $shares[$i]++;
        $left--;
    }

    foreach ($customers as $i => $row) {
        $customers[$i]['recommended'] = $shares[$i];
    }
    return $customers;
}

/**
 * Who is going on this delivery: assigned driver first, else standing route.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function bakery_production_assign_attach_route_context(PDO $db, string $date, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    $byCustomer = [];
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare(
            "SELECT o.customer_id, doa.driver_id,
                    COALESCE(d.name, CONCAT('Driver #', doa.driver_id)) AS driver_name
             FROM daily_order_assignments doa
             JOIN daily_orders o ON o.id = doa.daily_order_id
             LEFT JOIN drivers d ON d.id = doa.driver_id
             WHERE doa.delivery_date = ?
               AND doa.delivery_status <> 'cancelled'"
        );
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['customer_id'];
            if ($cid > 0 && !isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'driver_id' => (int)$row['driver_id'],
                    'driver_name' => (string)$row['driver_name'],
                ];
            }
        }
    }
    if (table_exists($db, 'standing_routes')) {
        $weekday = bakery_standing_day_from_date($date);
        $dayClause = bakery_standing_day_in_clause($weekday);
        $sql = "SELECT sr.customer_id, sr.driver_id,
                       COALESCE(d.name, CONCAT('Driver #', sr.driver_id)) AS driver_name
                FROM standing_routes sr
                LEFT JOIN drivers d ON d.id = sr.driver_id
                WHERE sr.day_of_week {$dayClause['sql']}";
        $stmt = $db->prepare($sql);
        $stmt->execute($dayClause['values']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['customer_id'];
            if ($cid > 0 && !isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'driver_id' => (int)$row['driver_id'],
                    'driver_name' => (string)$row['driver_name'],
                ];
            }
        }
    }
    foreach ($rows as &$row) {
        $cid = (int)($row['id'] ?? 0);
        $info = $byCustomer[$cid] ?? null;
        $row['driver_id'] = $info ? (int)$info['driver_id'] : 0;
        $row['driver_name'] = $info ? (string)$info['driver_name'] : '';
    }
    unset($row);
    return $rows;
}

/**
 * Split a short bake across a focus set. Locked stores and stores outside
 * the focus keep their current quantity; those units leave the pool first.
 * Unlocked stores in focus share what remains and are never raised.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<int>|null $focusIds null = every unlocked store
 * @return list<array<string,mixed>>
 */
function bakery_production_cut_share(array $rows, int $pool, ?array $focusIds = null): array
{
    $pool = max(0, $pool);
    $focusSet = null;
    if ($focusIds !== null) {
        $focusSet = [];
        foreach ($focusIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $focusSet[$id] = true;
            }
        }
    }

    $reserved = 0;
    $share = [];
    $shareIdx = [];
    foreach ($rows as $i => $row) {
        $qty = max(0, (int)($row['quantity'] ?? 0));
        $locked = !empty($row['locked']);
        $inFocus = !$locked && ($focusSet === null || isset($focusSet[(int)($row['id'] ?? 0)]));
        $rows[$i]['in_focus'] = $inFocus;
        if ($locked || !$inFocus) {
            $rows[$i]['recommended'] = $qty;
            $reserved += $qty;
            continue;
        }
        $share[] = $row;
        $shareIdx[] = $i;
    }

    $shareTotal = 0;
    foreach ($share as $row) {
        $shareTotal += max(0, (int)($row['quantity'] ?? 0));
    }
    $effectivePool = min(max(0, $pool - $reserved), $shareTotal);
    $recommended = bakery_production_assign_recommend($share, $effectivePool);
    foreach ($shareIdx as $j => $i) {
        $rows[$i]['recommended'] = (int)($recommended[$j]['recommended'] ?? 0);
    }
    return $rows;
}

/**
 * Units the manager is assigning from: planned target if saved/typed, else on-hand.
 *
 * @param array{hasPlan?:bool,planned?:int,onHand?:int,confirmed?:int} $row
 */
function bakery_production_assign_pool_from_row(array $row): array
{
    $planned = max(0, (int)($row['planned'] ?? 0));
    $onHand = max(0, (int)($row['onHand'] ?? 0));
    $confirmed = max(0, (int)($row['confirmed'] ?? 0));
    $hasPlan = !empty($row['hasPlan']);
    if ($hasPlan) {
        return ['pool' => $planned, 'source' => 'planned'];
    }
    if ($onHand > 0) {
        return ['pool' => $onHand, 'source' => 'on_hand'];
    }
    if ($confirmed > 0) {
        return ['pool' => $confirmed, 'source' => 'confirmed'];
    }
    return ['pool' => $planned, 'source' => 'planned'];
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_production_assign_preview(PDO $db, string $date, int $productId, int $pool): array
{
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($productId <= 0) {
        return [];
    }

    $weekday = bakery_standing_day_from_date($date);
    $customers = bakery_operating_demand_customers_for_product($db, $date, $productId);
    $rows = [];
    foreach ($customers as $line) {
        $customerId = (int)($line['id'] ?? 0);
        if ($customerId <= 0) {
            continue;
        }
        $state = bakery_customer_delivery_state($db, $customerId, $date);
        $daily = bakery_customer_daily_order_row($db, $customerId, $date);
        $locked = bakery_production_assign_order_is_locked(
            $state['status'] ?? null,
            $state['assignment_status'] ?? null
        );
        $reason = '';
        if (!empty($state['skipped'])) {
            $locked = true;
            $reason = 'skipped';
        } elseif (!empty($state['paused'])) {
            $locked = true;
            $reason = 'paused';
        } elseif ($locked) {
            $reason = 'locked';
        }
        $standingQty = bakery_customer_standing_qty($db, $customerId, $productId, $weekday);
        $dailyQty = null;
        if ($daily) {
            foreach (bakery_customer_daily_items($db, (int)$daily['id']) as $item) {
                if ((int)$item['product_id'] === $productId) {
                    $dailyQty = (int)$item['quantity'];
                    break;
                }
            }
        }
        $rows[] = [
            'id' => $customerId,
            'name' => (string)($line['name'] ?? ''),
            'zone' => (string)($line['zone'] ?? ''),
            'quantity' => (int)($line['quantity'] ?? 0),
            'source' => (string)($line['source'] ?? 'standing'),
            'standing_qty' => $standingQty,
            'daily_qty' => $dailyQty,
            'locked' => $locked,
            'locked_reason' => $reason,
            'order_status' => $state['status'] ?? null,
        ];
    }

    $rows = bakery_production_assign_attach_route_context($db, $date, $rows);

    $unlocked = [];
    $unlockedIdx = [];
    foreach ($rows as $i => $row) {
        if (empty($row['locked'])) {
            $unlocked[] = $row;
            $unlockedIdx[] = $i;
        } else {
            $rows[$i]['recommended'] = (int)$row['quantity'];
        }
    }
    $recommended = bakery_production_assign_recommend($unlocked, $pool);
    foreach ($unlockedIdx as $j => $i) {
        $rows[$i]['recommended'] = (int)($recommended[$j]['recommended'] ?? $rows[$i]['quantity']);
    }
    return $rows;
}

/**
 * Cut recommendations for a short bake: the pool cannot cover demand, so
 * dated orders shrink to what exists. Locked customers keep their full
 * quantity and their units leave the pool first; unlocked customers share
 * what remains proportional to their current quantities and are never
 * raised above them.
 *
 * @return list<array<string,mixed>>
 */
function bakery_production_cut_preview(PDO $db, string $date, int $productId, int $pool, ?array $focusIds = null): array
{
    $rows = bakery_production_assign_preview($db, $date, $productId, 0);
    return bakery_production_cut_share($rows, $pool, $focusIds);
}

/**
 * Apply cuts to dated order lines only. Standing orders never change and
 * a cut can only reduce a customer below their current quantity — locked
 * orders are skipped, not forced. Cutting to 0 removes the dated line,
 * so a standing-only customer falls back to their standing amount for
 * that day (canonical empty_daily semantics).
 *
 * @param list<array{customer_id:int,quantity:int}> $cuts
 * @return array{updated:int,skipped:int,cut_units:int,skipped_names:list<string>}
 */
function bakery_production_cut_apply(
    PDO $db,
    string $date,
    int $productId,
    array $cuts,
    ?int $userId = null
): array {
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot cut past deliveries');
    }
    if ($productId <= 0) {
        throw new InvalidArgumentException('Unknown product');
    }
    $product = bakery_customer_product_row($db, $productId);
    if (!$product) {
        throw new InvalidArgumentException('Unknown product');
    }

    $allowed = [];
    foreach (bakery_production_cut_preview($db, $date, $productId, 0) as $row) {
        $allowed[(int)$row['id']] = $row;
    }

    $result = [
        'updated' => 0,
        'skipped' => 0,
        'cut_units' => 0,
        'skipped_names' => [],
    ];

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        foreach ($cuts as $item) {
            $customerId = (int)($item['customer_id'] ?? 0);
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if ($customerId <= 0 || !isset($allowed[$customerId])) {
                throw new InvalidArgumentException('A cut is not a customer with demand for this product.');
            }
            $preview = $allowed[$customerId];
            if (!empty($preview['locked'])) {
                $result['skipped']++;
                $result['skipped_names'][] = (string)$preview['name'];
                continue;
            }
            $current = max(0, (int)$preview['quantity']);
            if ($quantity > $current) {
                throw new InvalidArgumentException(
                    'A cut would raise ' . $preview['name'] . ' above their current order. Cuts only reduce.'
                );
            }
            $customer = bakery_production_assign_customer_row($db, $customerId);
            if (!$customer) {
                $result['skipped']++;
                continue;
            }
            bakery_production_assign_save_daily_line($db, $customer, $product, $date, $quantity, $userId, true);
            $result['updated']++;
            $result['cut_units'] += $current - $quantity;
        }

        if ($result['updated'] > 0 && function_exists('bakery_record_operational_event')) {
            bakery_record_operational_event(
                $db,
                BAKERY_OP_PRODUCTION_CUT,
                'Cut ' . $product['name'] . ' orders for ' . $date . ' by ' . $result['cut_units'],
                [
                    'operational_date' => $date,
                    'product_id' => $productId,
                    'actor_user_id' => $userId,
                    'metadata' => [
                        'scope' => 'daily',
                        'updated' => $result['updated'],
                        'skipped' => $result['skipped'],
                        'cut_units' => $result['cut_units'],
                    ],
                ]
            );
        }

        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $result;
}

/**
 * Products whose saved plan is below operating demand for the day.
 * Pool is the planned target — the same bake the per-product cut panel uses.
 *
 * @param array<int,bool> $allowedProductIds
 * @return list<array{product_id:int,pool:int,demand:int}>
 */
function bakery_production_cut_short_products(PDO $db, string $date, array $allowedProductIds): array
{
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if (!function_exists('table_exists') || !table_exists($db, 'production_plan_items')) {
        return [];
    }

    $demand = bakery_operating_demand_by_product($db, $date);
    $byProduct = $demand['by_product'] ?? [];
    if ($byProduct === []) {
        return [];
    }

    $plans = [];
    $stmt = $db->prepare(
        'SELECT product_id, planned_quantity FROM production_plan_items WHERE delivery_date = ?'
    );
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $plans[(int)$row['product_id']] = (int)$row['planned_quantity'];
    }

    $out = [];
    foreach ($byProduct as $pid => $qty) {
        $pid = (int)$pid;
        if ($pid <= 0 || empty($allowedProductIds[$pid]) || !array_key_exists($pid, $plans)) {
            continue;
        }
        $demandQty = max(0, (int)$qty);
        $planned = max(0, (int)$plans[$pid]);
        if ($planned < $demandQty) {
            $out[] = [
                'product_id' => $pid,
                'pool' => $planned,
                'demand' => $demandQty,
            ];
        }
    }
    return $out;
}

/**
 * Apply recommended dated cuts for one product (all unlocked stores).
 *
 * @return array{updated:int,skipped:int,cut_units:int,skipped_names:list<string>}
 */
function bakery_production_cut_apply_recommended(
    PDO $db,
    string $date,
    int $productId,
    int $pool,
    ?int $userId = null
): array {
    $preview = bakery_production_cut_preview($db, $date, $productId, $pool, null);
    $cuts = [];
    foreach ($preview as $row) {
        if (!empty($row['locked']) || empty($row['in_focus'])) {
            continue;
        }
        $current = max(0, (int)($row['quantity'] ?? 0));
        $recommended = max(0, (int)($row['recommended'] ?? $current));
        if ($recommended < $current) {
            $cuts[] = [
                'customer_id' => (int)$row['id'],
                'quantity' => $recommended,
            ];
        }
    }
    if ($cuts === []) {
        return [
            'updated' => 0,
            'skipped' => 0,
            'cut_units' => 0,
            'skipped_names' => [],
        ];
    }
    return bakery_production_cut_apply($db, $date, $productId, $cuts, $userId);
}

/**
 * Apply recommended cuts for every plan-below product on a delivery day.
 *
 * @param array<int,bool> $allowedProductIds
 * @return array{updated:int,skipped:int,cut_units:int,products:int,skipped_names:list<string>}
 */
function bakery_production_cut_apply_all_recommended(
    PDO $db,
    string $date,
    array $allowedProductIds,
    ?int $userId = null
): array {
    $products = bakery_production_cut_short_products($db, $date, $allowedProductIds);
    $totals = [
        'updated' => 0,
        'skipped' => 0,
        'cut_units' => 0,
        'products' => 0,
        'skipped_names' => [],
    ];
    if ($products === []) {
        return $totals;
    }

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        foreach ($products as $item) {
            $result = bakery_production_cut_apply_recommended(
                $db,
                $date,
                (int)$item['product_id'],
                (int)$item['pool'],
                $userId
            );
            $totals['updated'] += (int)$result['updated'];
            $totals['skipped'] += (int)$result['skipped'];
            $totals['cut_units'] += (int)$result['cut_units'];
            if ((int)$result['updated'] > 0) {
                $totals['products']++;
            }
            foreach ($result['skipped_names'] as $name) {
                $totals['skipped_names'][] = (string)$name;
            }
        }
        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $totals;
}

/**
 * @param list<array{customer_id:int,quantity:int}> $assignments
 * @param 'standing'|'daily' $scope
 * @return array{updated:int,skipped:int,standing:int,daily:int,follow_on:int,skipped_names:list<string>}
 */
function bakery_production_assign_apply(
    PDO $db,
    string $date,
    int $productId,
    array $assignments,
    string $scope,
    ?int $userId = null
): array {
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot assign past deliveries');
    }
    if ($productId <= 0) {
        throw new InvalidArgumentException('Unknown product');
    }
    $scope = $scope === 'daily' ? 'daily' : 'standing';
    $product = bakery_customer_product_row($db, $productId);
    if (!$product) {
        throw new InvalidArgumentException('Unknown product');
    }

    $allowed = [];
    foreach (bakery_production_assign_preview($db, $date, $productId, 0) as $row) {
        $allowed[(int)$row['id']] = $row;
    }

    $result = [
        'updated' => 0,
        'skipped' => 0,
        'standing' => 0,
        'daily' => 0,
        'follow_on' => 0,
        'skipped_names' => [],
    ];

    $ownTx = !$db->inTransaction();
    if ($ownTx) {
        $db->beginTransaction();
    }
    try {
        foreach ($assignments as $item) {
            $customerId = (int)($item['customer_id'] ?? 0);
            $quantity = max(0, (int)($item['quantity'] ?? 0));
            if ($customerId <= 0 || !isset($allowed[$customerId])) {
                throw new InvalidArgumentException('An assignment is not a customer with demand for this product.');
            }
            $preview = $allowed[$customerId];
            if (!empty($preview['locked'])) {
                $result['skipped']++;
                $result['skipped_names'][] = (string)$preview['name'];
                continue;
            }
            $customer = bakery_production_assign_customer_row($db, $customerId);
            if (!$customer) {
                $result['skipped']++;
                continue;
            }

            if ($scope === 'standing') {
                $oldStanding = bakery_customer_standing_qty(
                    $db,
                    $customerId,
                    $productId,
                    bakery_standing_day_from_date($date)
                );
                bakery_production_assign_save_standing_line($db, $customer, $product, $date, $quantity, $userId);
                $result['standing']++;
                bakery_production_assign_save_daily_line($db, $customer, $product, $date, $quantity, $userId, false);
                $result['daily']++;
                $result['follow_on'] += bakery_production_assign_follow_standing_horizon(
                    $db,
                    $customer,
                    $product,
                    $date,
                    $oldStanding,
                    $quantity,
                    $userId
                );
            } else {
                bakery_production_assign_save_daily_line($db, $customer, $product, $date, $quantity, $userId, true);
                $result['daily']++;
            }
            $result['updated']++;
        }

        if (function_exists('bakery_record_operational_event')) {
            bakery_record_operational_event(
                $db,
                BAKERY_OP_PRODUCTION_ASSIGNED,
                'Assigned ' . $product['name'] . ' from production (' . $scope . ') for ' . $date,
                [
                    'operational_date' => $date,
                    'product_id' => $productId,
                    'actor_user_id' => $userId,
                    'metadata' => [
                        'scope' => $scope,
                        'updated' => $result['updated'],
                        'skipped' => $result['skipped'],
                        'standing' => $result['standing'],
                        'daily' => $result['daily'],
                        'follow_on' => $result['follow_on'],
                    ],
                ]
            );
        }

        if ($ownTx) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTx && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $result;
}

/**
 * @return array<string,mixed>|null
 */
function bakery_production_assign_customer_row(PDO $db, int $customerId): ?array
{
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_production_assign_save_standing_line(
    PDO $db,
    array $customer,
    array $product,
    string $date,
    int $quantity,
    ?int $userId
): void {
    $customerId = (int)$customer['id'];
    $productId = (int)$product['id'];
    $dayOfWeek = bakery_standing_day_from_date($date);
    $quantity = max(0, $quantity);
    $oldQty = bakery_customer_standing_qty($db, $customerId, $productId, $dayOfWeek);
    if ($oldQty === $quantity) {
        return;
    }

    bakery_standing_order_upsert($db, $customerId, $productId, $dayOfWeek, $quantity);

    $fullLabels = bakery_standing_day_full_labels();
    $dayLabel = $fullLabels[$dayOfWeek] ?? ('Day ' . $dayOfWeek);
    bakery_record_operational_event(
        $db,
        BAKERY_OP_PORTAL_STANDING_CHANGED,
        $customer['name'] . ' regular ' . $dayLabel . ' ' . $product['name'] . ': ' . $oldQty . ' → ' . $quantity,
        [
            'actor_user_id' => $userId,
            'actor_role' => 'staff',
            'customer_id' => $customerId,
            'product_id' => $productId,
            'operational_date' => $date,
            'metadata' => [
                'day_of_week' => $dayOfWeek,
                'product_name' => $product['name'],
                'old_quantity' => $oldQty,
                'new_quantity' => $quantity,
                'scope' => 'standing',
                'source' => 'production_assign',
            ],
        ]
    );
    bakery_customer_notify_standing_changed(
        $db,
        $customer,
        $dayLabel,
        $product['name'],
        $oldQty,
        $quantity,
        $dayOfWeek,
        $productId
    );
}

function bakery_production_assign_save_daily_line(
    PDO $db,
    array $customer,
    array $product,
    string $date,
    int $quantity,
    ?int $userId,
    bool $notify
): void {
    $customerId = (int)$customer['id'];
    $productId = (int)$product['id'];
    $quantity = max(0, $quantity);
    $state = bakery_customer_delivery_state($db, $customerId, $date);
    if (!empty($state['skipped']) || !empty($state['paused'])) {
        return;
    }
    if (bakery_production_assign_order_is_locked($state['status'] ?? null, $state['assignment_status'] ?? null)) {
        return;
    }

    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
    $stmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldQty = $existing ? (int)$existing['quantity'] : 0;
    if ($oldQty === $quantity) {
        return;
    }

    if ($quantity > 0) {
        $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
        $lineTotal = round($quantity * $unitPrice, 2);
        if ($existing) {
            $upd = $db->prepare(
                'UPDATE daily_order_items SET quantity = ?, line_total = ? * unit_price WHERE id = ?'
            );
            $upd->execute([$quantity, $quantity, (int)$existing['id']]);
        } else {
            $ins = $db->prepare(
                'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $ins->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
        }
    } elseif ($existing) {
        $del = $db->prepare('DELETE FROM daily_order_items WHERE id = ?');
        $del->execute([(int)$existing['id']]);
    }
    bakery_customer_update_daily_total($db, $dailyOrderId);

    bakery_record_operational_event(
        $db,
        BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
        'Production assigned ' . $product['name'] . ' for ' . $customer['name'] . ': ' . $oldQty . ' → ' . $quantity,
        [
            'operational_date' => $date,
            'customer_id' => $customerId,
            'daily_order_id' => $dailyOrderId,
            'product_id' => $productId,
            'actor_user_id' => $userId,
            'actor_role' => 'staff',
            'metadata' => [
                'product_name' => $product['name'],
                'old_quantity' => $oldQty,
                'new_quantity' => $quantity,
                'source' => 'production_assign',
            ],
        ]
    );
    if ($notify) {
        bakery_customer_notify_daily_changed(
            $db,
            $customer,
            $date,
            $product['name'],
            $oldQty,
            $quantity,
            $dailyOrderId,
            $productId
        );
    }
}

/**
 * Push a standing change onto later same-weekday dated lines that still match
 * the old standing quantity (they were template copies, not exceptions).
 */
function bakery_production_assign_follow_standing_horizon(
    PDO $db,
    array $customer,
    array $product,
    string $fromDate,
    int $oldStandingQty,
    int $newQty,
    ?int $userId
): int {
    $customerId = (int)$customer['id'];
    $productId = (int)$product['id'];
    $weekday = bakery_standing_day_from_date($fromDate);
    $days = function_exists('bakery_demand_horizon_days') ? bakery_demand_horizon_days() : 7;
    $followed = 0;
    $cursor = strtotime($fromDate . ' +1 day');
    $end = strtotime($fromDate . ' +' . $days . ' days');
    while ($cursor !== false && $cursor <= $end) {
        $nextDate = date('Y-m-d', $cursor);
        $cursor = strtotime('+1 day', $cursor);
        if (bakery_standing_day_from_date($nextDate) !== $weekday) {
            continue;
        }
        $state = bakery_customer_delivery_state($db, $customerId, $nextDate);
        if (!empty($state['skipped']) || !empty($state['paused'])) {
            continue;
        }
        if (bakery_production_assign_order_is_locked($state['status'] ?? null, $state['assignment_status'] ?? null)) {
            continue;
        }
        $daily = bakery_customer_daily_order_row($db, $customerId, $nextDate);
        if (!$daily) {
            continue;
        }
        $datedQty = 0;
        $hasLine = false;
        foreach (bakery_customer_daily_items($db, (int)$daily['id']) as $item) {
            if ((int)$item['product_id'] === $productId) {
                $datedQty = (int)$item['quantity'];
                $hasLine = true;
                break;
            }
        }
        if (!$hasLine && $oldStandingQty !== 0) {
            continue;
        }
        if ($hasLine && $datedQty !== $oldStandingQty) {
            continue;
        }
        bakery_production_assign_save_daily_line($db, $customer, $product, $nextDate, $newQty, $userId, false);
        $followed++;
    }
    return $followed;
}

/**
 * Per-route desired (assigned order qty) vs share of a bake pool.
 *
 * @param array<int,int> $bakeByProduct product_id => pieces available from the kitchen note
 * @return list<array<string,mixed>>
 */
function bakery_production_route_desired_vs_bake(PDO $db, string $date, array $bakeByProduct): array
{
    if ($bakeByProduct === []) {
        return [];
    }
    $productIds = array_keys($bakeByProduct);
    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $sql = "
        SELECT doa.driver_id,
               COALESCE(d.name, CONCAT('Driver #', doa.driver_id)) AS driver_name,
               p.id AS product_id,
               p.name AS product_name,
               COALESCE(SUM(doi.quantity), 0) AS desired
        FROM daily_order_assignments doa
        JOIN daily_orders do ON do.id = doa.daily_order_id
        JOIN daily_order_items doi ON doi.daily_order_id = do.id
        JOIN products p ON p.id = doi.product_id
        LEFT JOIN drivers d ON d.id = doa.driver_id
        WHERE doa.delivery_date = ? AND do.order_date = doa.delivery_date
          AND doa.delivery_status <> 'cancelled'
          AND p.id IN ($placeholders)
        GROUP BY doa.driver_id, d.name, p.id, p.name
        ORDER BY driver_name, p.name
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$date], $productIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byProduct = [];
    foreach ($rows as $row) {
        $pid = (int)$row['product_id'];
        $byProduct[$pid][] = $row;
    }

    $out = [];
    foreach ($bakeByProduct as $productId => $pool) {
        $customers = [];
        foreach ($byProduct[$productId] ?? [] as $row) {
            $customers[] = [
                'driver_id' => (int)$row['driver_id'],
                'driver_name' => (string)$row['driver_name'],
                'product_id' => $productId,
                'product_name' => (string)$row['product_name'],
                'quantity' => (int)$row['desired'],
            ];
        }
        $recommended = bakery_production_assign_recommend($customers, (int)$pool);
        $demand = 0;
        foreach ($recommended as $row) {
            $demand += (int)$row['quantity'];
            $avail = (int)$row['recommended'];
            $desired = (int)$row['quantity'];
            $out[] = [
                'driver_id' => (int)$row['driver_id'],
                'driver_name' => (string)$row['driver_name'],
                'product_id' => $productId,
                'product_name' => (string)$row['product_name'],
                'desired' => $desired,
                'available' => $avail,
                'bake_pool' => (int)$pool,
                'gap' => $avail - $desired,
            ];
        }
        if ($recommended === []) {
            $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ?');
            $nameStmt->execute([$productId]);
            $out[] = [
                'driver_id' => 0,
                'driver_name' => '',
                'product_id' => $productId,
                'product_name' => (string)$nameStmt->fetchColumn(),
                'desired' => 0,
                'available' => (int)$pool,
                'bake_pool' => (int)$pool,
                'gap' => (int)$pool,
            ];
        }
    }
    return $out;
}

/**
 * Preview how a saved bake would split to stores (does not write orders).
 *
 * @param array<int,int> $bakeByProduct product_id => planned pieces
 * @return list<array<string,mixed>>
 */
function bakery_production_store_allocation_from_plan(PDO $db, string $date, array $bakeByProduct): array
{
    $out = [];
    foreach ($bakeByProduct as $productId => $pool) {
        $productId = (int)$productId;
        $pool = max(0, (int)$pool);
        if ($productId <= 0 || $pool <= 0) {
            continue;
        }
        $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
        $nameStmt->execute([$productId]);
        $productName = (string)$nameStmt->fetchColumn();
        $rows = bakery_production_assign_preview($db, $date, $productId, $pool);
        if ($rows === []) {
            $out[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'customer_id' => 0,
                'customer_name' => '',
                'desired' => 0,
                'from_bake' => $pool,
                'gap' => $pool,
                'locked' => false,
            ];
            continue;
        }
        foreach ($rows as $row) {
            $desired = (int)($row['quantity'] ?? 0);
            $fromBake = (int)($row['recommended'] ?? 0);
            $out[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'customer_id' => (int)($row['id'] ?? 0),
                'customer_name' => (string)($row['name'] ?? ''),
                'desired' => $desired,
                'from_bake' => $fromBake,
                'gap' => $fromBake - $desired,
                'locked' => !empty($row['locked']),
            ];
        }
    }
    return $out;
}

/**
 * Store demand for one SKU on a delivery day, including route/driver when assigned.
 *
 * @return list<array<string,mixed>>
 */
function bakery_production_store_demand_rows(PDO $db, string $date, int $productId, int $pool = 0): array
{
    $rows = bakery_production_assign_preview($db, $date, $productId, max(0, $pool));
    $drivers = [];
    if ($rows !== [] && table_exists($db, 'daily_order_assignments')) {
        $stmt = $db->prepare(
            "SELECT o.customer_id, COALESCE(d.name, CONCAT('Driver #', doa.driver_id)) AS driver_name
             FROM daily_order_assignments doa
             JOIN daily_orders o ON o.id = doa.daily_order_id
             LEFT JOIN drivers d ON d.id = doa.driver_id
             WHERE doa.delivery_date = ?
               AND doa.delivery_status <> 'cancelled'"
        );
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int)$row['customer_id'];
            if ($cid > 0 && !isset($drivers[$cid])) {
                $drivers[$cid] = (string)$row['driver_name'];
            }
        }
    }
    foreach ($rows as &$row) {
        $cid = (int)($row['id'] ?? 0);
        $row['driver_name'] = $drivers[$cid] ?? '';
        $row['editable'] = empty($row['locked']);
    }
    unset($row);
    return $rows;
}

/**
 * Staff write of one dated daily-order line from Production Center Store Demand.
 * Uses van-lock (not portal in-production lock) and does not SMS the store.
 *
 * @return array{quantity:int,demand_total:int,customers:list<array<string,mixed>>}
 */
function bakery_production_store_demand_save(
    PDO $db,
    string $date,
    int $productId,
    int $customerId,
    int $quantity,
    ?int $userId,
    int $pool = 0
): array {
    $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid delivery date');
    }
    if ($date < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot change past deliveries');
    }
    $quantity = max(0, $quantity);
    if ($productId <= 0 || $customerId <= 0) {
        throw new InvalidArgumentException('Unknown store or product.');
    }

    $customer = bakery_production_assign_customer_row($db, $customerId);
    $product = bakery_customer_product_row($db, $productId);
    if (!$customer || !$product) {
        throw new InvalidArgumentException('Unknown store or product.');
    }

    $state = bakery_customer_delivery_state($db, $customerId, $date);
    if (!empty($state['skipped'])) {
        throw new InvalidArgumentException('This delivery is skipped.');
    }
    if (!empty($state['paused'])) {
        throw new InvalidArgumentException('Deliveries are paused for this date.');
    }
    if (bakery_production_assign_order_is_locked($state['status'] ?? null, $state['assignment_status'] ?? null)) {
        throw new InvalidArgumentException('This stop is already on the van and cannot be edited here.');
    }

    $dailyOrderId = bakery_customer_ensure_daily_order($db, $customer, $date);
    $stmt = $db->prepare(
        'SELECT id, quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $productId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    $oldQty = $existing ? (int)$existing['quantity'] : 0;
    $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
    $lineTotal = round($quantity * $unitPrice, 2);
    if ($existing) {
        $upd = $db->prepare(
            'UPDATE daily_order_items SET quantity = ?, line_total = ? * unit_price WHERE id = ?'
        );
        $upd->execute([$quantity, $quantity, (int)$existing['id']]);
    } else {
        $ins = $db->prepare(
            'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?)'
        );
        $ins->execute([$dailyOrderId, $productId, $quantity, $unitPrice, $lineTotal]);
    }
    bakery_customer_update_daily_total($db, $dailyOrderId);
    if ($oldQty !== $quantity) {
        bakery_record_operational_event(
            $db,
            BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED,
            'Production Center store demand ' . $product['name'] . ' for ' . $customer['name'] . ': ' . $oldQty . ' → ' . $quantity,
            [
                'operational_date' => $date,
                'customer_id' => $customerId,
                'daily_order_id' => $dailyOrderId,
                'product_id' => $productId,
                'actor_user_id' => $userId,
                'actor_role' => 'staff',
                'metadata' => [
                    'product_name' => $product['name'],
                    'old_quantity' => $oldQty,
                    'new_quantity' => $quantity,
                    'source' => 'production_center_store_demand',
                ],
            ]
        );
    }

    $daily = bakery_customer_daily_order_row($db, $customerId, $date);
    $stored = 0;
    if ($daily) {
        foreach (bakery_customer_daily_items($db, (int)$daily['id']) as $item) {
            if ((int)$item['product_id'] === $productId) {
                $stored = (int)$item['quantity'];
                break;
            }
        }
    }
    if ($stored !== $quantity) {
        throw new RuntimeException('Store demand did not persist for this delivery.');
    }

    $customers = bakery_production_store_demand_rows($db, $date, $productId, max(0, $pool));
    $demandTotal = 0;
    foreach ($customers as $row) {
        $demandTotal += (int)($row['quantity'] ?? 0);
    }

    return [
        'quantity' => $stored,
        'demand_total' => $demandTotal,
        'customers' => $customers,
    ];
}
