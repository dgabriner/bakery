<?php
/**
 * Money visibility phase 1 — computed read-first customer balances + AR aging.
 *
 * Pure-read helpers over the confirmed-delivery snapshots on daily_orders
 * (delivery_order_total / amount_collected / square_status). No writes, no new
 * tables, no invented amounts — Billing Center marks and sends; it doesn't
 * invent amounts. COD "collected" keeps the route_manager_cash.php meaning:
 * recorded cash on the order is authoritative once delivery is confirmed.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * Cent tolerance: remainders at or below this are treated as settled.
 */
function bakery_billing_outstanding_tolerance() {
    return 0.005;
}

/**
 * Per-delivery outstanding remainder from the confirmed snapshot:
 * delivery_order_total - COD collected - Square-settled total.
 *
 * @param array<string, mixed> $order daily_orders row (square_status optional)
 * @param bool|null $squarePaid explicit settlement override for callers that
 *                              already resolved Square state
 */
function bakery_billing_order_outstanding(array $order, $squarePaid = null) {
    $total = isset($order['delivery_order_total']) && $order['delivery_order_total'] !== null && $order['delivery_order_total'] !== ''
        ? (float)$order['delivery_order_total']
        : 0.0;
    $collected = isset($order['amount_collected']) && $order['amount_collected'] !== null && $order['amount_collected'] !== ''
        ? (float)$order['amount_collected']
        : 0.0;
    if ($squarePaid === null) {
        $squarePaid = strtoupper(trim((string)($order['square_status'] ?? ''))) === 'PAID';
    }
    return $total - $collected - ($squarePaid ? $total : 0.0);
}

/**
 * Days since a confirmed-at timestamp (whole days; negative for future dates).
 */
function bakery_billing_oldest_days($confirmedAt) {
    if ($confirmedAt === null || trim((string)$confirmedAt) === '') {
        return 0;
    }
    $ts = strtotime((string)$confirmedAt);
    if ($ts === false) {
        return 0;
    }
    return (int)floor((time() - $ts) / 86400);
}

/**
 * Whether the confirmed-delivery snapshot columns this module reads exist.
 * Old installs may lack pieces of migration 014 — degrade to empty, never fatal.
 */
function bakery_billing_aging_snapshot_ready(PDO $db) {
    return table_exists($db, 'daily_orders')
        && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        && column_exists($db, 'daily_orders', 'delivery_order_total')
        && column_exists($db, 'daily_orders', 'status')
        && table_exists($db, 'customers');
}

/**
 * Shared aggregate SQL: one row per customer over confirmed deliveries in
 * statuses delivered/invoiced (invoiced-but-unpaid is exactly AR).
 * Square settlement test: UPPER(square_status) = 'PAID'; when the column does
 * not exist Square is treated as unsettled (never fatal).
 *
 * @return array{sql:string, params:int[]}
 */
function bakery_billing_balances_sql(PDO $db, $scopedCustomerId = 0) {
    $collectedExpr = column_exists($db, 'daily_orders', 'amount_collected')
        ? 'COALESCE(do.amount_collected, 0)'
        : '0';
    $squareSettledExpr = column_exists($db, 'daily_orders', 'square_status')
        ? "CASE WHEN UPPER(TRIM(COALESCE(do.square_status, ''))) = 'PAID' THEN COALESCE(do.delivery_order_total, 0) ELSE 0 END"
        : '0';

    $whereCustomer = '';
    $params = [];
    if ((int)$scopedCustomerId > 0) {
        $whereCustomer = 'WHERE outq.customer_id = ?';
        $params[] = (int)$scopedCustomerId;
    }

    $originClause = '';
    if (function_exists('bakery_sfb_ops_origin_clause')) {
        $originClause = bakery_sfb_ops_origin_clause('c', $db);
    }

    $sql = "SELECT outq.customer_id,
                   c.name AS customer_name,
                   SUM(CASE WHEN outq.outstanding > 0.005 THEN outq.outstanding ELSE 0 END) AS outstanding_total,
                   SUM(CASE WHEN outq.outstanding > 0.005 THEN 1 ELSE 0 END) AS outstanding_count,
                   SUM(CASE WHEN outq.outstanding > 0.005 THEN 0 ELSE 1 END) AS settled_count,
                   MIN(CASE WHEN outq.outstanding > 0.005 THEN outq.confirmed_at END) AS oldest_outstanding_date
            FROM (
                SELECT do.customer_id,
                       do.delivery_confirmed_at AS confirmed_at,
                       COALESCE(do.delivery_order_total, 0)
                           - {$collectedExpr}
                           - {$squareSettledExpr} AS outstanding
                FROM daily_orders do
                WHERE do.delivery_confirmed_at IS NOT NULL
                  AND do.status IN ('delivered', 'invoiced')
            ) outq
            JOIN customers c ON c.id = outq.customer_id{$originClause}
            {$whereCustomer}
            GROUP BY outq.customer_id, c.name
            HAVING outstanding_count > 0
            ORDER BY outstanding_total DESC, customer_name ASC";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Shape one aggregate row into the public balance array.
 *
 * @param array<string, mixed>|false $row
 */
function bakery_billing_balance_row_shape($row) {
    if (!is_array($row)) {
        return null;
    }
    $oldestDate = !empty($row['oldest_outstanding_date'])
        ? date('Y-m-d', strtotime((string)$row['oldest_outstanding_date']))
        : null;
    return [
        'customer_id' => (int)$row['customer_id'],
        'customer_name' => (string)$row['customer_name'],
        'outstanding_total' => round((float)$row['outstanding_total'], 2),
        'outstanding_count' => (int)$row['outstanding_count'],
        'settled_count' => (int)$row['settled_count'],
        'oldest_outstanding_date' => $oldestDate,
        'oldest_days' => bakery_billing_oldest_days($oldestDate),
    ];
}

/**
 * One row per customer having >=1 outstanding confirmed delivery, whole ledger.
 *
 * Outstanding per order = delivery_order_total - COALESCE(amount_collected, 0)
 *   - (Square PAID ? delivery_order_total : 0); only remainders > 0.005 count.
 * No date limit, no other exclusions.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_billing_customer_balances(PDO $db) {
    if (!bakery_billing_aging_snapshot_ready($db)) {
        return [];
    }
    $built = bakery_billing_balances_sql($db);
    $stmt = $db->prepare($built['sql']);
    $stmt->execute($built['params']);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shaped = bakery_billing_balance_row_shape($row);
        if ($shaped !== null) {
            $rows[(int)$row['customer_id']] = $shaped;
        }
    }
    return $rows;
}

/**
 * Same math scoped to one customer; null-safe zeros when nothing is outstanding.
 *
 * @return array<string, mixed>
 */
function bakery_billing_customer_balance(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    $balance = [
        'customer_id' => $customerId,
        'customer_name' => '',
        'outstanding_total' => 0.0,
        'outstanding_count' => 0,
        'settled_count' => 0,
        'oldest_outstanding_date' => null,
        'oldest_days' => 0,
    ];
    if ($customerId <= 0 || !bakery_billing_aging_snapshot_ready($db)) {
        return $balance;
    }

    $nameStmt = $db->prepare('SELECT name FROM customers WHERE id = ? LIMIT 1');
    $nameStmt->execute([$customerId]);
    $name = $nameStmt->fetchColumn();
    if ($name === false) {
        return $balance;
    }
    $balance['customer_name'] = (string)$name;

    // Reuse the shared aggregation without the HAVING gate so settled-only
    // customers still report their settled_count context.
    $built = bakery_billing_balances_sql($db, $customerId);
    $sql = str_replace('HAVING outstanding_count > 0', '', $built['sql']);
    $stmt = $db->prepare($sql);
    $stmt->execute($built['params']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $shaped = bakery_billing_balance_row_shape($row);
    if ($shaped !== null) {
        return array_merge($balance, $shaped);
    }
    return $balance;
}
