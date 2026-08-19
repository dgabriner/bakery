<?php
/**
 * Canonical billing helpers — delivery-backed invoices from daily_orders.
 *
 * Operational truth: ordered → delivered → billable snapshot on daily_orders /
 * daily_order_items. No separate invoices table; invoice numbers are derived.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/customer_record.php';
require_once __DIR__ . '/sfb_origin.php';

/**
 * Per-delivery invoice identifier used by Invoice/Billing Center (canonical).
 */
function bakery_billing_invoice_number($orderId, $orderDate) {
    return 'INV-' . date('Ymd', strtotime((string)$orderDate))
        . '-' . str_pad((string)(int)$orderId, 5, '0', STR_PAD_LEFT);
}

/**
 * Legacy period/customer invoice number from printable generators.
 */
function bakery_billing_legacy_period_invoice_number($customerId, $endDate) {
    return 'INV-' . date('Y') . '-'
        . str_pad((string)(int)$customerId, 4, '0', STR_PAD_LEFT) . '-'
        . date('md', strtotime((string)$endDate));
}

/**
 * Attention / exception categories for manager billing work.
 *
 * @return array<string, array{label:string,short:string,help:string,tone:string,priority:int}>
 */
function bakery_billing_attention_meta() {
    return [
        'failed' => [
            'label' => 'Failed / not delivered',
            'short' => 'Failed',
            'help' => 'Assignment marked failed or cancelled.',
            'tone' => 'danger',
            'priority' => 10,
        ],
        'incomplete' => [
            'label' => 'Delivery incomplete',
            'short' => 'Incomplete',
            'help' => 'Still open on the route — not delivered and not confirmed.',
            'tone' => 'warn',
            'priority' => 20,
        ],
        'missing_invoice' => [
            'label' => 'Missing delivery invoice',
            'short' => 'Missing invoice',
            'help' => 'Marked delivered without a confirmed delivery invoice snapshot.',
            'tone' => 'warn',
            'priority' => 30,
        ],
        'pricing_issue' => [
            'label' => 'Pricing issue',
            'short' => 'Pricing',
            'help' => 'Line prices or delivery pricing snapshot needed to trust the billable amount are missing.',
            'tone' => 'warn',
            'priority' => 40,
        ],
        'quantity_variance' => [
            'label' => 'Quantity variance',
            'short' => 'Variance',
            'help' => 'Ordered vs delivered differs at item or header level, or credits were taken back.',
            'tone' => 'alert',
            'priority' => 50,
        ],
        'ready' => [
            'label' => 'Ready / clean',
            'short' => 'Ready',
            'help' => 'Delivery confirmed, priced from snapshot, no quantity exceptions.',
            'tone' => 'ok',
            'priority' => 60,
        ],
        'already_invoiced' => [
            'label' => 'Already invoiced',
            'short' => 'Invoiced',
            'help' => 'Order status set to invoiced.',
            'tone' => 'muted',
            'priority' => 70,
        ],
    ];
}

/**
 * Classify a delivery for manager billing / reconciliation.
 *
 * @param array<int, array> $items daily_order_items rows with product_name
 * @return array<string, mixed>
 */
function bakery_billing_classify_order(array $order, array $items, ?array $attentionMeta = null) {
    $attentionMeta = $attentionMeta ?? bakery_billing_attention_meta();

    $orderedPieces = 0;
    $deliveredItemPieces = 0;
    $itemsWithDelivered = 0;
    $itemVarianceCount = 0;
    $itemVarianceTotal = 0;
    $missingLinePrice = false;
    $lineTotalSum = 0.0;
    $enrichedItems = [];

    foreach ($items as $item) {
        $ordered = (int)$item['quantity'];
        $deliveredRaw = $item['delivered_quantity'] ?? null;
        $delivered = $deliveredRaw !== null && $deliveredRaw !== '' ? (int)$deliveredRaw : null;
        $unitPrice = round((float)($item['unit_price'] ?? 0), 2);
        $lineTotal = round((float)($item['line_total'] ?? 0), 2);
        if ($delivered !== null) {
            $lineTotal = round($unitPrice * $delivered, 2);
        }
        $orderedPieces += $ordered;
        $lineTotalSum += $lineTotal;

        $variance = null;
        if ($delivered !== null) {
            $itemsWithDelivered++;
            $deliveredItemPieces += $delivered;
            $variance = $delivered - $ordered;
            if ($variance !== 0) {
                $itemVarianceCount++;
                $itemVarianceTotal += $variance;
            }
        }

        if ($ordered > 0 && $unitPrice <= 0) {
            $missingLinePrice = true;
        }

        $enrichedItems[] = [
            'item_id' => (int)($item['item_id'] ?? $item['id'] ?? 0),
            'product_id' => (int)($item['product_id'] ?? 0),
            'product_name' => (string)($item['product_name'] ?? ''),
            'product_line_name' => (string)($item['product_line_name'] ?? ''),
            'quantity' => $ordered,
            'delivered_quantity' => $delivered,
            'variance' => $variance,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
            'has_price' => $unitPrice > 0,
            'is_match' => $variance === null || $variance === 0,
        ];
    }

    $confirmed = !empty($order['delivery_confirmed_at']);
    $status = (string)($order['status'] ?? '');
    $assignmentStatus = (string)($order['assignment_delivery_status'] ?? '');
    $deliveredPieces = isset($order['delivered_pieces']) && $order['delivered_pieces'] !== null && $order['delivered_pieces'] !== ''
        ? (int)$order['delivered_pieces']
        : null;
    $credits = (int)($order['credits_taken_back'] ?? 0);
    $deliveryOrderTotal = isset($order['delivery_order_total']) && $order['delivery_order_total'] !== null
        ? (float)$order['delivery_order_total']
        : null;
    $pricingLabel = trim((string)($order['delivery_pricing_label'] ?? ''));

    $headerVariance = false;
    $headerVarianceAmount = 0;
    if ($confirmed && $deliveredPieces !== null) {
        $headerVarianceAmount = $deliveredPieces - $orderedPieces;
        if ($headerVarianceAmount !== 0) {
            $headerVariance = true;
        }
    }

    $hasItemVariance = $itemVarianceCount > 0;
    $hasCredits = $credits > 0;
    $hasQuantityVariance = $hasItemVariance || $headerVariance || $hasCredits;

    $markedDelivered = in_array($status, ['delivered', 'invoiced'], true)
        || $assignmentStatus === 'delivered'
        || $confirmed;

    $pricingIssue = false;
    $pricingNotes = [];
    if ($missingLinePrice) {
        $pricingIssue = true;
        $pricingNotes[] = 'One or more ordered lines have no usable unit price snapshot.';
    }
    if ($confirmed && $deliveryOrderTotal === null && $lineTotalSum <= 0 && $orderedPieces > 0) {
        $pricingIssue = true;
        $pricingNotes[] = 'Delivery confirmed but no delivery pricing snapshot or line totals are available.';
    }
    if ($confirmed && $pricingLabel === '' && $deliveryOrderTotal === null) {
        $pricingNotes[] = 'No delivery pricing label was stored; amount relies on line totals only.';
    }

    $flags = [];
    if (in_array($assignmentStatus, ['failed', 'cancelled'], true)) {
        $flags[] = 'failed';
    }
    if (!$confirmed && !$markedDelivered) {
        $flags[] = 'incomplete';
    }
    if ($markedDelivered && !$confirmed && !in_array($assignmentStatus, ['failed', 'cancelled'], true)) {
        $flags[] = 'missing_invoice';
    }
    if ($pricingIssue) {
        $flags[] = 'pricing_issue';
    }
    if ($hasQuantityVariance) {
        $flags[] = 'quantity_variance';
    }
    if ($status === 'invoiced') {
        $flags[] = 'already_invoiced';
    }

    if (in_array($assignmentStatus, ['failed', 'cancelled'], true)) {
        $category = 'failed';
    } elseif (!$confirmed && !$markedDelivered) {
        $category = 'incomplete';
    } elseif ($markedDelivered && !$confirmed) {
        $category = 'missing_invoice';
    } elseif ($pricingIssue) {
        $category = 'pricing_issue';
    } elseif ($hasQuantityVariance) {
        $category = 'quantity_variance';
    } elseif ($status === 'invoiced') {
        $category = 'already_invoiced';
    } elseif ($confirmed) {
        $category = 'ready';
    } else {
        $category = 'incomplete';
    }

    if ($category === 'ready' && !in_array('ready', $flags, true)) {
        $flags[] = 'ready';
    }

    $needsAttention = !in_array($category, ['ready', 'already_invoiced'], true);

    $billableAmount = (float)($order['total_amount'] ?? 0);
    $displayAmount = ($confirmed && $billableAmount > 0)
        ? $billableAmount
        : ($deliveryOrderTotal !== null ? $deliveryOrderTotal : $billableAmount);
    $amountIsBillable = $confirmed && !$pricingIssue && $billableAmount > 0;

    if ($amountIsBillable && $billableAmount <= 0 && ($deliveredPieces ?? 0) > 0 && !$pricingIssue) {
        $amountIsBillable = false;
        $pricingIssue = true;
        $pricingNotes[] = 'Billable amount is zero despite delivered pieces — review pricing snapshot.';
        if (!in_array('pricing_issue', $flags, true)) {
            $flags[] = 'pricing_issue';
        }
        if ($category === 'ready') {
            $category = 'pricing_issue';
            $needsAttention = true;
        }
    }

    $varianceSummary = [];
    if ($hasItemVariance) {
        $varianceSummary[] = $itemVarianceCount . ' item' . ($itemVarianceCount === 1 ? '' : 's') . ' differ';
    }
    if ($headerVariance) {
        $sign = $headerVarianceAmount > 0 ? '+' : '';
        $varianceSummary[] = 'pieces ' . $sign . $headerVarianceAmount;
    }
    if ($hasCredits) {
        $varianceSummary[] = $credits . ' credit' . ($credits === 1 ? '' : 's');
    }

    return [
        'items' => $enrichedItems,
        'ordered_pieces' => $orderedPieces,
        'delivered_item_pieces' => $itemsWithDelivered > 0 ? $deliveredItemPieces : null,
        'item_variance_count' => $itemVarianceCount,
        'item_variance_total' => $itemVarianceTotal,
        'header_variance' => $headerVariance,
        'header_variance_amount' => $headerVarianceAmount,
        'has_credits' => $hasCredits,
        'has_quantity_variance' => $hasQuantityVariance,
        'pricing_issue' => $pricingIssue,
        'pricing_notes' => $pricingNotes,
        'category' => $category,
        'flags' => $flags,
        'needs_attention' => $needsAttention,
        'display_amount' => $displayAmount,
        'billable_amount' => $billableAmount,
        'amount_is_billable' => $amountIsBillable,
        'variance_summary' => $varianceSummary,
        'category_meta' => $attentionMeta[$category] ?? $attentionMeta['incomplete'],
    ];
}

/**
 * Payment / AR label — explicit about what Sour Flour OS does and does not know.
 *
 * @return array{key:string,label:string,detail:string}
 */
function bakery_billing_payment_status(array $order, array $customer = []) {
    $paymentCollection = (string)($customer['payment_collection'] ?? 'signature');
    $status = (string)($order['status'] ?? '');
    $confirmed = !empty($order['delivery_confirmed_at']);
    $amountCollected = isset($order['amount_collected']) && $order['amount_collected'] !== null
        ? (float)$order['amount_collected']
        : null;

    if ($paymentCollection === 'cod' && $confirmed) {
        if ($amountCollected !== null && $amountCollected > 0) {
            return [
                'key' => 'cod_collected',
                'label' => 'COD collected at delivery',
                'detail' => 'Driver recorded $' . number_format($amountCollected, 2) . ' at confirm. External AR may still differ.',
            ];
        }
        return [
            'key' => 'cod_expected',
            'label' => 'COD — collection not recorded',
            'detail' => 'Customer is COD; no amount_collected stored on this delivery.',
        ];
    }

    if ($status === 'invoiced') {
        return [
            'key' => 'billing_complete',
            'label' => 'Billing complete (invoiced)',
            'detail' => 'Marked invoiced in Sour Flour OS. Payment status in QuickBooks or elsewhere is not tracked here.',
        ];
    }

    if ($confirmed) {
        return [
            'key' => 'payment_unknown',
            'label' => 'Payment status unknown externally',
            'detail' => 'Delivery confirmed and billable. Invoice/payment in accounting system is not tracked here.',
        ];
    }

    return [
        'key' => 'not_billable',
        'label' => 'Not yet billable',
        'detail' => 'Delivery invoice not confirmed — no external payment state applies.',
    ];
}

/**
 * Whether billing audit/export/statement tables exist.
 */
function bakery_billing_tables_ready(PDO $db) {
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = table_exists($db, 'billing_exports')
        && table_exists($db, 'billing_statements')
        && table_exists($db, 'audit_log');
    return $ready;
}

/**
 * Load line items for multiple daily orders.
 *
 * @param int[] $orderIds
 * @return array<int, array<int, array>>
 */
function bakery_billing_load_items(PDO $db, array $orderIds) {
    $itemsByOrder = [];
    if (!$orderIds) {
        return $itemsByOrder;
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $db->prepare(
        "SELECT doi.daily_order_id, doi.id AS item_id, doi.product_id, doi.quantity,
                doi.delivered_quantity, doi.unit_price, doi.line_total,
                p.name AS product_name, pl.name AS product_line_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id IN ($placeholders)
         ORDER BY doi.daily_order_id, pl.name, p.name"
    );
    $itemStmt->execute(array_values($orderIds));
    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $itemsByOrder[(int)$item['daily_order_id']][] = $item;
    }
    return $itemsByOrder;
}

/**
 * Enrich raw order rows with classification and canonical fields.
 *
 * @param array<int, array> $orders
 * @return array<int, array>
 */
function bakery_billing_enrich_orders(array $orders, array $itemsByOrder, ?array $attentionMeta = null) {
    $attentionMeta = $attentionMeta ?? bakery_billing_attention_meta();
    $enriched = [];

    foreach ($orders as $order) {
        $oid = (int)$order['id'];
        $items = $itemsByOrder[$oid] ?? [];
        $classified = bakery_billing_classify_order($order, $items, $attentionMeta);

        $order['total_amount'] = (float)($order['total_amount'] ?? 0);
        $order['delivery_order_total'] = isset($order['delivery_order_total']) && $order['delivery_order_total'] !== null
            ? (float)$order['delivery_order_total']
            : null;
        $order['display_amount'] = $classified['display_amount'];
        $order['billable_amount'] = $classified['billable_amount'];
        $order['amount_is_billable'] = $classified['amount_is_billable'];
        $order['status_label'] = ucwords(str_replace('_', ' ', (string)$order['status']));
        $order['invoice_number'] = bakery_billing_invoice_number($oid, $order['order_date']);
        $order['invoice_date'] = !empty($order['delivery_confirmed_at'])
            ? date('Y-m-d', strtotime((string)$order['delivery_confirmed_at']))
            : (string)$order['order_date'];
        $order['customer_zone'] = trim((string)($order['customer_zone'] ?? ''));
        $order['zone_label'] = $order['customer_zone'] !== '' ? $order['customer_zone'] : 'No zone';
        $order['assignment_delivery_status'] = (string)($order['assignment_delivery_status'] ?? '');
        $order['assigned_driver_name'] = trim((string)($order['assigned_driver_name'] ?? ''));
        $isDelivered = in_array($order['status'], ['delivered', 'invoiced'], true)
            || $order['assignment_delivery_status'] === 'delivered'
            || !empty($order['delivery_confirmed_at']);
        $order['driver_display'] = $order['assigned_driver_name'] !== '' ? $order['assigned_driver_name'] : 'Unassigned';
        $order['driver_label'] = $order['assigned_driver_name'] === ''
            ? 'Driver'
            : ($isDelivered ? 'Delivered by' : 'Assigned to');

        foreach ($classified as $key => $value) {
            if (!in_array($key, ['category_meta'], true)) {
                $order[$key] = $value;
            }
        }
        $order['category_meta'] = $classified['category_meta'];
        $order['attention_priority'] = (int)$classified['category_meta']['priority'];
        $order['payment_status'] = bakery_billing_payment_status($order, [
            'payment_collection' => $order['payment_collection'] ?? 'signature',
        ]);

        $enriched[] = $order;
    }

    return $enriched;
}

/**
 * Query deliveries/orders for billing views.
 *
 * @param array<string, mixed> $filters
 * @return array<int, array>
 */
function bakery_billing_query_orders(PDO $db, array $filters) {
    $startDate = (string)($filters['start_date'] ?? date('Y-m-d'));
    $endDate = (string)($filters['end_date'] ?? $startDate);
    $customerId = max(0, (int)($filters['customer_id'] ?? 0));
    $statusFilter = (string)($filters['status'] ?? 'all');
    $zoneFilter = trim((string)($filters['zone'] ?? ''));
    $driverId = max(0, (int)($filters['driver_id'] ?? 0));
    $productLineId = max(0, (int)($filters['product_line_id'] ?? 0));
    $amountMin = isset($filters['amount_min']) && $filters['amount_min'] !== null ? (float)$filters['amount_min'] : null;
    $amountMax = isset($filters['amount_max']) && $filters['amount_max'] !== null ? (float)$filters['amount_max'] : null;
    $deliveredOnly = !empty($filters['delivered_only']);
    $sortBy = (string)($filters['sort'] ?? 'date_desc');
    $confirmedOnly = !empty($filters['confirmed_only']);
    $invoicedOnly = !empty($filters['invoiced_only']);

    $orderStatuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];

    $where = ['do.order_date BETWEEN ? AND ?'];
    $params = [$startDate, $endDate];

    if ($customerId > 0) {
        $where[] = 'do.customer_id = ?';
        $params[] = $customerId;
    }
    if ($statusFilter === 'open') {
        $where[] = "do.status NOT IN ('delivered', 'invoiced')";
    } elseif ($statusFilter !== 'all' && in_array($statusFilter, $orderStatuses, true)) {
        $where[] = 'do.status = ?';
        $params[] = $statusFilter;
    }
    if ($zoneFilter !== '') {
        $where[] = 'c.zone = ?';
        $params[] = $zoneFilter;
    }
    if ($driverId > 0) {
        $where[] = '(EXISTS (
            SELECT 1 FROM daily_order_assignments doa
            WHERE doa.daily_order_id = do.id AND doa.driver_id = ?
        ) OR do.driver_id = ?)';
        $params[] = $driverId;
        $params[] = $driverId;
    }
    if ($productLineId > 0) {
        $where[] = 'EXISTS (
            SELECT 1 FROM daily_order_items doi
            JOIN products p ON p.id = doi.product_id
            LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
            WHERE doi.daily_order_id = do.id AND dt.product_line_id = ?
        )';
        $params[] = $productLineId;
    }
    if ($amountMin !== null) {
        $where[] = 'do.total_amount >= ?';
        $params[] = $amountMin;
    }
    if ($amountMax !== null) {
        $where[] = 'do.total_amount <= ?';
        $params[] = $amountMax;
    }
    if ($deliveredOnly || $confirmedOnly) {
        $where[] = 'do.delivery_confirmed_at IS NOT NULL';
    }
    if ($invoicedOnly) {
        $where[] = "do.status = 'invoiced'";
    }

    $orderSql = 'do.order_date DESC, c.name, do.id DESC';
    switch ($sortBy) {
        case 'date_asc':
            $orderSql = 'do.order_date ASC, c.name, do.id ASC';
            break;
        case 'customer':
            $orderSql = 'c.name ASC, do.order_date DESC, do.id DESC';
            break;
        case 'amount_desc':
            $orderSql = 'COALESCE(do.delivery_order_total, do.total_amount) DESC, do.order_date DESC, do.id DESC';
            break;
        case 'amount_asc':
            $orderSql = 'COALESCE(do.delivery_order_total, do.total_amount) ASC, do.order_date DESC, do.id DESC';
            break;
        case 'status':
            $orderSql = 'do.status ASC, do.order_date DESC, c.name, do.id DESC';
            break;
    }

    $stmt = $db->prepare(
        'SELECT do.id, do.order_date, do.status, do.total_amount,
                do.delivery_order_total, do.delivery_pricing_label,
                do.delivered_pieces, do.credits_taken_back, do.delivery_confirmed_at,
                do.amount_collected,
                c.id AS customer_id, c.name AS customer_name, c.address AS customer_address,
                c.email AS customer_email, c.phone AS customer_phone,
                c.zone AS customer_zone, c.payment_collection,
                asn.delivery_status AS assignment_delivery_status,
                asn.driver_id AS assigned_driver_id,
                COALESCE(asn_drv.name, legacy_drv.name) AS assigned_driver_name
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         ' . bakery_sfb_ops_origin_clause('c', $db) . '
         LEFT JOIN (
             SELECT doa1.daily_order_id, doa1.delivery_status, doa1.driver_id
             FROM daily_order_assignments doa1
             INNER JOIN (
                 SELECT daily_order_id, MAX(id) AS max_id
                 FROM daily_order_assignments
                 GROUP BY daily_order_id
             ) latest ON latest.daily_order_id = doa1.daily_order_id AND latest.max_id = doa1.id
         ) asn ON asn.daily_order_id = do.id
         LEFT JOIN drivers asn_drv ON asn_drv.id = asn.driver_id
         LEFT JOIN drivers legacy_drv ON legacy_drv.id = do.driver_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $orderSql
    );
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Filter orders by search query (customer name, invoice #, id).
 *
 * @param array<int, array> $orders
 * @return array<int, array>
 */
function bakery_billing_filter_search(array $orders, $searchQ) {
    $searchQ = trim((string)$searchQ);
    if ($searchQ === '') {
        return $orders;
    }
    $searchLower = mb_strtolower($searchQ);
    return array_values(array_filter($orders, static function ($order) use ($searchLower) {
        $invoiceNumber = bakery_billing_invoice_number((int)$order['id'], $order['order_date']);
        $haystacks = [
            mb_strtolower((string)($order['customer_name'] ?? '')),
            mb_strtolower($invoiceNumber),
            (string)$order['id'],
            mb_strtolower((string)($order['customer_zone'] ?? '')),
            mb_strtolower((string)($order['assigned_driver_name'] ?? '')),
        ];
        foreach ($haystacks as $hay) {
            if ($hay !== '' && strpos($hay, $searchLower) !== false) {
                return true;
            }
        }
        return false;
    }));
}

/**
 * Customer billing account summary for a date range.
 *
 * @return array<string, mixed>
 */
function bakery_billing_customer_account(PDO $db, $customerId, $startDate, $endDate) {
    $customerId = (int)$customerId;
    $customer = bakery_customer_record_load_customer($db, $customerId);
    if (!$customer) {
        throw new RuntimeException('Customer not found');
    }

    $orders = bakery_billing_query_orders($db, [
        'start_date' => $startDate,
        'end_date' => $endDate,
        'customer_id' => $customerId,
        'status' => 'all',
        'sort' => 'date_desc',
    ]);
    $orderIds = array_map(static function ($o) {
        return (int)$o['id'];
    }, $orders);
    $itemsByOrder = bakery_billing_load_items($db, $orderIds);
    $invoices = bakery_billing_enrich_orders($orders, $itemsByOrder);

    $totals = [
        'invoice_count' => count($invoices),
        'billable_total' => 0.0,
        'display_total' => 0.0,
        'needs_attention' => 0,
        'invoiced_count' => 0,
        'cod_collected' => 0.0,
    ];
    foreach ($invoices as $inv) {
        $totals['display_total'] += (float)$inv['display_amount'];
        if ($inv['amount_is_billable']) {
            $totals['billable_total'] += (float)$inv['billable_amount'];
        }
        if ($inv['needs_attention']) {
            $totals['needs_attention']++;
        }
        if (($inv['status'] ?? '') === 'invoiced') {
            $totals['invoiced_count']++;
        }
        if (isset($inv['amount_collected']) && $inv['amount_collected'] !== null) {
            $totals['cod_collected'] += (float)$inv['amount_collected'];
        }
    }

    $statements = [];
    if (bakery_billing_tables_ready($db)) {
        $stmt = $db->prepare(
            'SELECT id, period_start, period_end, statement_date, invoice_count, total_amount,
                    sent_at, sent_to_email, created_at
             FROM billing_statements
             WHERE customer_id = ?
               AND period_end >= ? AND period_start <= ?
             ORDER BY statement_date DESC, id DESC'
        );
        $stmt->execute([$customerId, $startDate, $endDate]);
        $statements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $missingBillingContact = trim((string)($customer['email'] ?? '')) === ''
        && trim((string)($customer['address'] ?? '')) === '';

    return [
        'customer' => $customer,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'invoices' => $invoices,
        'totals' => $totals,
        'statements' => $statements,
        'missing_billing_contact' => $missingBillingContact,
        'payment_tracking_note' => 'Sour Flour OS tracks delivery billing snapshots and invoiced status only. '
            . 'External payment in QuickBooks is not synchronized.',
    ];
}

/**
 * Build statement payload for one customer and period.
 *
 * @return array<string, mixed>
 */
function bakery_billing_statement_data(PDO $db, $customerId, $startDate, $endDate, $statementDate = null) {
    $account = bakery_billing_customer_account($db, $customerId, $startDate, $endDate);
    $invoices = array_values(array_filter($account['invoices'], static function ($inv) {
        return !empty($inv['delivery_confirmed_at']) && empty($inv['pricing_issue']);
    }));

    $total = 0.0;
    $lines = [];
    foreach ($invoices as $inv) {
        $amt = $inv['amount_is_billable'] ? (float)$inv['billable_amount'] : (float)$inv['display_amount'];
        if ($inv['pricing_issue']) {
            continue;
        }
        $total += $amt;
        $lines[] = [
            'daily_order_id' => (int)$inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'order_date' => $inv['order_date'],
            'invoice_date' => $inv['invoice_date'],
            'amount' => $amt,
            'status' => $inv['status'],
            'credits_taken_back' => (int)($inv['credits_taken_back'] ?? 0),
            'payment_status' => $inv['payment_status'],
        ];
    }

    return [
        'customer' => $account['customer'],
        'period_start' => $startDate,
        'period_end' => $endDate,
        'statement_date' => $statementDate ?: date('Y-m-d'),
        'invoices' => $lines,
        'invoice_count' => count($lines),
        'total_amount' => round($total, 2),
        'balance_note' => 'Balance reflects confirmed delivery billings in this period only. '
            . 'Credits/payments in external accounting are not included unless exported separately.',
        'company' => [
            'name' => defined('SITE_NAME') ? SITE_NAME : 'Sour Flour Bakery',
            'tagline' => 'Artisan Breads & Pastries',
        ],
    ];
}

/**
 * Deterministic accounting export rows (one row per line item).
 *
 * @param array<string, mixed> $filters
 * @return array<int, array<string, scalar|null>>
 */
function bakery_billing_export_rows(PDO $db, array $filters) {
    $orders = bakery_billing_query_orders($db, array_merge($filters, [
        'confirmed_only' => !empty($filters['confirmed_only']),
    ]));
    $orderIds = array_map(static function ($o) {
        return (int)$o['id'];
    }, $orders);
    $itemsByOrder = bakery_billing_load_items($db, $orderIds);
    $invoices = bakery_billing_enrich_orders($orders, $itemsByOrder);

    if (!empty($filters['q'])) {
        $invoices = bakery_billing_filter_search($invoices, $filters['q']);
    }
    if (!empty($filters['attention']) && $filters['attention'] !== 'all') {
        $attention = (string)$filters['attention'];
        $invoices = array_values(array_filter($invoices, static function ($inv) use ($attention) {
            if ($attention === 'needs_attention') {
                return !empty($inv['needs_attention']);
            }
            return ($inv['category'] ?? '') === $attention;
        }));
    }

    $rows = [];
    foreach ($invoices as $inv) {
        if (!empty($inv['pricing_issue']) && empty($filters['include_exceptions'])) {
            continue;
        }
        $invoiceNumber = $inv['invoice_number'];
        $invoiceTotal = $inv['amount_is_billable']
            ? round((float)$inv['billable_amount'], 2)
            : round((float)$inv['display_amount'], 2);

        if ($invoiceTotal <= 0 && !empty($inv['delivery_confirmed_at']) && ($inv['ordered_pieces'] ?? 0) > 0) {
            continue;
        }

        foreach ($inv['items'] as $line) {
            $qty = $line['delivered_quantity'] ?? $line['quantity'];
            $unitPrice = (float)$line['unit_price'];
            $lineTotal = $line['delivered_quantity'] !== null
                ? round($unitPrice * (int)$line['delivered_quantity'], 2)
                : (float)$line['line_total'];

            $rows[] = [
                'invoice_id' => $invoiceNumber,
                'daily_order_id' => (int)$inv['id'],
                'customer_id' => (int)$inv['customer_id'],
                'customer_name' => (string)$inv['customer_name'],
                'invoice_date' => $inv['invoice_date'],
                'delivery_date' => (string)$inv['order_date'],
                'product_name' => (string)$line['product_name'],
                'product_id' => (int)$line['product_id'],
                'quantity_ordered' => (int)$line['quantity'],
                'quantity_delivered' => $line['delivered_quantity'] !== null ? (int)$line['delivered_quantity'] : null,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'invoice_total' => $invoiceTotal,
                'credits_taken_back' => (int)($inv['credits_taken_back'] ?? 0),
                'pricing_label' => (string)($inv['delivery_pricing_label'] ?? ''),
                'status' => (string)$inv['status'],
                'memo' => 'Delivery ' . $inv['order_date'] . ' #' . $inv['id'],
            ];
        }
    }

    return $rows;
}

/**
 * Mark a daily order as invoiced.
 */
function bakery_billing_mark_invoiced(PDO $db, $orderId, $userId = null) {
    $orderId = (int)$orderId;
    $stmt = $db->prepare("UPDATE daily_orders SET status = 'invoiced' WHERE id = ? AND delivery_confirmed_at IS NOT NULL");
    $stmt->execute([$orderId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Order not found or delivery not confirmed');
    }
    if (function_exists('log_user_action')) {
        log_user_action($db, 'invoice_marked_invoiced', 'daily_order', $orderId, null, $userId);
    }
    return true;
}

/**
 * Persist statement record.
 */
function bakery_billing_record_statement(PDO $db, array $data, $userId = null) {
    if (!bakery_billing_tables_ready($db)) {
        throw new RuntimeException('Billing tables not migrated');
    }
    $stmt = $db->prepare(
        'INSERT INTO billing_statements
            (customer_id, period_start, period_end, statement_date, invoice_count, total_amount,
             created_by_user_id, sent_at, sent_by_user_id, sent_to_email)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$data['customer_id'],
        $data['period_start'],
        $data['period_end'],
        $data['statement_date'],
        (int)$data['invoice_count'],
        (float)$data['total_amount'],
        $userId,
        $data['sent_at'] ?? null,
        $data['sent_by_user_id'] ?? null,
        $data['sent_to_email'] ?? null,
    ]);
    $id = (int)$db->lastInsertId();
    if (function_exists('bakery_customer_notify_statement_available')) {
        require_once __DIR__ . '/customer_notifications.php';
        bakery_customer_notify_statement_available(
            $db,
            (int)$data['customer_id'],
            $id,
            $data['period_start'],
            $data['period_end']
        );
    }
    if (function_exists('log_user_action')) {
        $details = json_encode([
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'sent_to' => $data['sent_to_email'] ?? null,
        ]);
        log_user_action($db, $data['sent_at'] ? 'statement_sent' : 'statement_generated', 'billing_statement', $id, $details, $userId);
    }
    return $id;
}

/**
 * Record an accounting export batch.
 *
 * @param int[] $orderIds
 */
function bakery_billing_record_export(PDO $db, array $data, array $orderIds, $userId = null) {
    if (!bakery_billing_tables_ready($db)) {
        throw new RuntimeException('Billing tables not migrated');
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'INSERT INTO billing_exports
                (export_key, period_start, period_end, row_count, invoice_count, content_hash,
                 created_by_user_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['export_key'],
            $data['period_start'],
            $data['period_end'],
            (int)$data['row_count'],
            (int)$data['invoice_count'],
            $data['content_hash'],
            $userId,
            $data['notes'] ?? null,
        ]);
        $exportId = (int)$db->lastInsertId();

        if ($orderIds && table_exists($db, 'billing_export_invoices')) {
            $ins = $db->prepare(
                'INSERT IGNORE INTO billing_export_invoices (export_id, daily_order_id) VALUES (?, ?)'
            );
            foreach ($orderIds as $oid) {
                $ins->execute([$exportId, (int)$oid]);
            }
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    if (function_exists('log_user_action')) {
        $details = json_encode([
            'export_key' => $data['export_key'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'row_count' => $data['row_count'],
        ]);
        log_user_action($db, 'accounting_export_created', 'billing_export', $exportId, $details, $userId);
    }

    return $exportId;
}

/**
 * Recent export history.
 *
 * @return array<int, array>
 */
function bakery_billing_recent_exports(PDO $db, $limit = 20) {
    if (!table_exists($db, 'billing_exports')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT e.*, u.display_name AS created_by_name
         FROM billing_exports e
         LEFT JOIN users u ON u.id = e.created_by_user_id
         ORDER BY e.created_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Whether real customer email delivery is configured (not test-only / log driver).
 */
function bakery_billing_email_ready() {
    if (defined('MAIL_DRIVER') && MAIL_DRIVER === 'log') {
        return false;
    }
    return defined('SMTP_HOST') && SMTP_HOST !== '';
}
