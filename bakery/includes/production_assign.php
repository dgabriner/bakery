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
function bakery_production_cut_preview(PDO $db, string $date, int $productId, int $pool): array
{
    $rows = bakery_production_assign_preview($db, $date, $productId, 0);
    $lockedUnits = 0;
    $unlocked = [];
    $unlockedIdx = [];
    foreach ($rows as $i => $row) {
        if (!empty($row['locked'])) {
            $lockedUnits += max(0, (int)$row['quantity']);
            continue;
        }
        $unlocked[] = $row;
        $unlockedIdx[] = $i;
    }
    $unlockedTotal = 0;
    foreach ($unlocked as $row) {
        $unlockedTotal += max(0, (int)$row['quantity']);
    }
    $effectivePool = min(max(0, (int)$pool - $lockedUnits), $unlockedTotal);
    $recommended = bakery_production_assign_recommend($unlocked, $effectivePool);
    foreach ($unlockedIdx as $j => $i) {
        $rows[$i]['recommended'] = (int)($recommended[$j]['recommended'] ?? 0);
    }
    return $rows;
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

    $clause = bakery_standing_day_in_clause($dayOfWeek);
    if ($quantity > 0) {
        $stmt = $db->prepare(
            'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = ?'
        );
        $stmt->execute([$customerId, $productId, $dayOfWeek, $quantity, $quantity]);
        $db->prepare(
            'DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $clause['sql']
            . ' AND day_of_week <> ?'
        )->execute(array_merge([$customerId, $productId], $clause['values'], [$dayOfWeek]));
    } else {
        $stmt = $db->prepare(
            'DELETE FROM standing_orders WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $clause['sql']
        );
        $stmt->execute(array_merge([$customerId, $productId], $clause['values']));
    }

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
