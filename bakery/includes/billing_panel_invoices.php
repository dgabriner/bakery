<?php
/**
 * Invoice reconciliation panel (embedded in Billing Center).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$attentionMeta = bakery_billing_attention_meta();
$attentionOptions = array_merge(['all', 'needs_attention'], array_keys($attentionMeta));
$attentionFilter = (string)($_GET['attention'] ?? 'all');
if (!in_array($attentionFilter, $attentionOptions, true)) {
    $attentionFilter = 'all';
}

$groupOptions = ['attention', 'none', 'customer', 'date', 'status', 'zone', 'driver'];
$sortOptions = ['attention', 'date_desc', 'date_asc', 'customer', 'amount_desc', 'amount_asc', 'status'];
$groupBy = (string)($_GET['group'] ?? 'date');
if (!in_array($groupBy, $groupOptions, true)) {
    $groupBy = 'date';
}
$sortBy = (string)($_GET['sort'] ?? 'date_desc');
if (!in_array($sortBy, $sortOptions, true)) {
    $sortBy = 'attention';
}

$orders = bakery_billing_query_orders($db, [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'customer_id' => $customerId,
    'status' => $statusFilter,
    'zone' => $zoneFilter,
    'driver_id' => $driverId,
    'product_line_id' => $productLineId,
    'amount_min' => $amountMin,
    'amount_max' => $amountMax,
    'delivered_only' => $deliveredOnly,
    'collection' => $collectionFilter,
    'sort' => $sortBy === 'attention' ? 'date_desc' : $sortBy,
]);
$orders = bakery_billing_filter_search($orders, $searchQ);
$orderIds = array_map(static function ($o) {
    return (int)$o['id'];
}, $orders);
$itemsByOrder = bakery_billing_load_items($db, $orderIds);
$orders = bakery_billing_enrich_orders($orders, $itemsByOrder, $attentionMeta);

$hideFixtures = isset($hideFixtures) ? $hideFixtures : true;
$workQueue = isset($workQueue) ? $workQueue : 'to_send';
$queueCounts = ['to_send' => 0, 'waiting' => 0, 'problems' => 0, 'all' => 0];
$kept = [];
foreach ($orders as $order) {
    if ($hideFixtures && !empty($order['is_fixture_noise'])) {
        continue;
    }
    $kept[] = $order;
    $bucket = (string)($order['work_queue'] ?? 'other');
    if (isset($queueCounts[$bucket])) {
        $queueCounts[$bucket]++;
    }
    $queueCounts['all']++;
}
$orders = $kept;

$stats = [
    'count' => 0, 'total' => 0.0, 'billable_total' => 0.0, 'delivered' => 0, 'open' => 0,
    'confirmed' => 0, 'needs_attention' => 0, 'ready' => 0, 'invoiced' => 0, 'sent' => 0,
];
$categoryCounts = array_fill_keys(array_keys($attentionMeta), 0);

foreach ($orders as $order) {
    $stats['count']++;
    $stats['total'] += $order['display_amount'];
    if ($order['amount_is_billable']) {
        $stats['billable_total'] += $order['billable_amount'];
    }
    if (in_array($order['status'], ['delivered', 'invoiced'], true)) {
        $stats['delivered']++;
    } else {
        $stats['open']++;
    }
    if ($order['delivery_confirmed_at']) {
        $stats['confirmed']++;
    }
    if ($order['needs_attention']) {
        $stats['needs_attention']++;
    }
    if ($order['category'] === 'ready') {
        $stats['ready']++;
    }
    if ($order['category'] === 'already_invoiced' || $order['status'] === 'invoiced') {
        $stats['invoiced']++;
    }
    if (!empty($order['invoice_was_sent'])) {
        $stats['sent']++;
    }
    if (isset($categoryCounts[$order['category']])) {
        $categoryCounts[$order['category']]++;
    }
}

if ($workQueue !== 'all') {
    $orders = array_values(array_filter($orders, static function ($order) use ($workQueue) {
        return ($order['work_queue'] ?? '') === $workQueue;
    }));
}

if ($sortBy === 'attention') {
    usort($orders, static function ($a, $b) {
        $pa = (int)($a['attention_priority'] ?? 99);
        $pb = (int)($b['attention_priority'] ?? 99);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcmp((string)$b['order_date'], (string)$a['order_date']);
    });
}

$selectedInvoiceId = max(0, (int)($_GET['invoice_id'] ?? 0));
$orderIds = array_map(static function ($o) {
    return (int)$o['id'];
}, $orders);
if ($selectedInvoiceId > 0 && !in_array($selectedInvoiceId, $orderIds, true)) {
    $selectedInvoiceId = 0;
}
if ($selectedInvoiceId === 0 && $orders) {
    foreach ($orders as $order) {
        if (!empty($order['needs_attention'])) {
            $selectedInvoiceId = (int)$order['id'];
            break;
        }
    }
    if ($selectedInvoiceId === 0) {
        $selectedInvoiceId = (int)$orders[0]['id'];
    }
}

$selectedInvoice = null;
$selectedIndex = -1;
$prevInvoiceId = 0;
$nextInvoiceId = 0;
foreach ($orders as $idx => $order) {
    if ((int)$order['id'] === $selectedInvoiceId) {
        $selectedInvoice = $order;
        $selectedIndex = $idx;
        break;
    }
}
if ($selectedIndex >= 0) {
    if ($selectedIndex > 0) {
        $prevInvoiceId = (int)$orders[$selectedIndex - 1]['id'];
    }
    if ($selectedIndex < count($orders) - 1) {
        $nextInvoiceId = (int)$orders[$selectedIndex + 1]['id'];
    }
}

$baseQueryParams = [
    'panel' => 'invoices',
    'range' => $range,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'month' => $selectedMonth,
    'customer_id' => $customerId,
    'q' => $searchQ,
    'status' => $statusFilter,
    'attention' => $attentionFilter,
    'zone' => $zoneFilter,
    'driver_id' => $driverId,
    'product_line_id' => $productLineId,
    'amount_min' => $amountMinRaw,
    'amount_max' => $amountMaxRaw,
    'group' => $groupBy,
    'sort' => $sortBy,
    'view' => $viewMode,
    'collection' => $collectionFilter,
    'queue' => $workQueue,
];
if (!$deliveredOnly) {
    $baseQueryParams['show_unconfirmed'] = '1';
}
if (!$hideFixtures) {
    $baseQueryParams['show_test_rows'] = '1';
}

$query = function (array $extra = []) use ($baseQueryParams) {
    $merged = array_merge($baseQueryParams, $extra);
    foreach ($merged as $key => $value) {
        if ($value === null) {
            unset($merged[$key]);
            continue;
        }
        if (in_array($key, ['customer_id', 'driver_id', 'product_line_id', 'invoice_id'], true) && (int)$value === 0) {
            unset($merged[$key]);
            continue;
        }
        if (in_array($key, ['q', 'zone', 'amount_min', 'amount_max'], true) && $value === '') {
            unset($merged[$key]);
            continue;
        }
        if ($key === 'attention' && $value === 'all') {
            unset($merged[$key]);
            continue;
        }
        if ($key === 'status' && $value === 'all') {
            unset($merged[$key]);
        }
        if ($key === 'collection' && $value === 'invoice') {
            unset($merged[$key]);
        }
        if ($key === 'queue' && $value === 'to_send') {
            unset($merged[$key]);
        }
    }
    return 'billing_center.php?' . http_build_query($merged);
};

$groupedOrders = [];
if ($groupBy === 'none') {
    $groupedOrders[''] = $orders;
} else {
    foreach ($orders as $order) {
        switch ($groupBy) {
            case 'date': $label = $order['order_date']; break;
            case 'status': $label = $order['status_label']; break;
            case 'zone': $label = $order['zone_label']; break;
            case 'driver': $label = $order['driver_display']; break;
            case 'attention': $label = $order['category']; break;
            default: $label = $order['customer_name'];
        }
        $groupedOrders[$label][] = $order;
    }
    if ($groupBy === 'attention') {
        uksort($groupedOrders, static function ($a, $b) use ($attentionMeta) {
            return ($attentionMeta[$a]['priority'] ?? 99) <=> ($attentionMeta[$b]['priority'] ?? 99);
        });
    }
}

$attentionClassFor = static function ($category) {
    $map = [
        'failed' => 'ic-att--danger', 'incomplete' => 'ic-att--warn', 'missing_invoice' => 'ic-att--warn',
        'pricing_issue' => 'ic-att--warn', 'quantity_variance' => 'ic-att--alert',
        'ready' => 'ic-att--ok', 'already_invoiced' => 'ic-att--muted',
    ];
    return $map[$category] ?? 'ic-att--warn';
};

$formatVariance = static function ($variance) {
    if ($variance === null) {
        return '—';
    }
    $variance = (int)$variance;
    return $variance === 0 ? '0' : (($variance > 0 ? '+' : '') . $variance);
};

$formAction = 'billing_center.php?panel=invoices';
?>
<style>
.ic-att{display:inline-flex;align-items:center;min-height:24px;padding:3px 8px;border-radius:999px;font-size:.68rem;font-weight:850}
.ic-att--danger{background:#fef2f2;color:#b91c1c}.ic-att--warn{background:#fff7ed;color:#c2410c}
.ic-att--alert{background:#fffbeb;color:#b45309}.ic-att--ok{background:#ecfdf5;color:#047857}.ic-att--muted{background:#f1f5f9;color:#475569}
.ic-attention-strip{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.ic-chip{display:inline-flex;align-items:center;gap:7px;min-height:36px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;font-size:.78rem;font-weight:750;text-decoration:none}
.ic-chip.is-active{border-color:#0f766e;background:#0f766e;color:#fff}
.ic-exception-box{padding:12px 14px;margin-bottom:14px;border-radius:12px;background:#fff7ed;border:1px solid #fdba74;font-size:.84rem}
.ic-exception-box.is-ok{background:#ecfdf5;border-color:#a7f3d0}
.ic-exception-box.is-danger{background:#fef2f2;border-color:#fecaca}
.ic-var-neg{color:#b91c1c}.ic-var-pos{color:#047857}
.ic-more-filters{margin-top:4px;font-size:.82rem;color:#475569}
.ic-more-filters summary{cursor:pointer;font-weight:700}
.invoice-center{max-width:none;padding:0;margin:0}
.invoice-center-filters,.invoice-list-panel,.invoice-detail-panel,.invoice-center-stats,.invoice-center-layout{font-family:inherit}
.invoice-center-filters{display:flex;flex-direction:column;gap:12px;padding:14px;margin-bottom:18px;border:1px solid #dbe4ea;border-radius:16px;background:#f8fbfb}
.ic-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ic-btn{display:inline-flex;align-items:center;min-height:40px;padding:8px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font-weight:700;text-decoration:none;cursor:pointer;font-size:.86rem}
.ic-btn-primary{background:#0f766e;border-color:#0f766e;color:#fff}
.invoice-center-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.8fr);gap:18px}
.invoice-list-panel,.invoice-detail-panel{border:1px solid #e2e8f0;border-radius:16px;background:#fff;overflow:hidden}
.invoice-card{display:grid;grid-template-columns:1.2fr 1fr auto;gap:10px;padding:14px 16px;border-bottom:1px solid #edf2f4;text-decoration:none;color:inherit;flex:1}
.invoice-card-row{display:flex;align-items:center;border-bottom:1px solid #edf2f4}
.invoice-card-row .invoice-card{border-bottom:none}
.invoice-card-row .ic-bulk-check{margin:0 0 0 14px;width:18px;height:18px;accent-color:#0f766e;cursor:pointer}
.ic-bulk-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#f8fbfb}
.ic-bulk-select-all{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:700;color:#334155;cursor:pointer}
.invoice-card:hover,.invoice-card.is-selected{background:#f0fdfa}
.invoice-table{width:100%;border-collapse:collapse;font-size:.82rem}
.invoice-table th,.invoice-table td{padding:10px 12px;border-bottom:1px solid #edf2f4}
.invoice-detail{padding:18px}
.invoice-detail-table{width:100%;border-collapse:collapse;font-size:.82rem}
.invoice-detail-table th,.invoice-detail-table td{padding:8px 4px;border-bottom:1px solid #edf2f4}
.invoice-detail-summary{margin-top:12px;padding-top:10px;border-top:2px solid #e2e8f0;font-size:.84rem}
.invoice-detail-footer{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
@media(max-width:980px){.invoice-center-layout{grid-template-columns:1fr}}
</style>

<section class="invoice-center">
    <form class="invoice-center-filters" method="get" action="<?php echo htmlspecialchars($formAction); ?>" id="invoiceCenterFilters">
        <input type="hidden" name="panel" value="invoices">
        <input type="hidden" name="queue" value="<?php echo htmlspecialchars($workQueue); ?>">
        <?php if ($selectedInvoiceId > 0): ?><input type="hidden" name="invoice_id" value="<?php echo $selectedInvoiceId; ?>"><?php endif; ?>
        <div class="ic-filter-grid">
            <label><?php echo htmlspecialchars(bakery_t('billing.filter_customer')); ?><select name="customer_id"><option value="0"><?php echo htmlspecialchars(bakery_t('billing.filter_all_customers')); ?></option><?php foreach ($customers as $c): ?><option value="<?php echo (int)$c['id']; ?>" <?php echo $customerId === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?></select></label>
            <label><?php echo htmlspecialchars(bakery_t('billing.filter_search')); ?><input type="search" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="<?php echo htmlspecialchars(bakery_t('billing.filter_search_ph')); ?>"></label>
            <label><?php echo htmlspecialchars(bakery_t('billing.collection_filter')); ?><select name="collection">
                <option value="invoice" <?php echo $collectionFilter === 'invoice' ? 'selected' : ''; ?>><?php echo htmlspecialchars(bakery_t('billing.collection_invoice')); ?></option>
                <option value="cod" <?php echo $collectionFilter === 'cod' ? 'selected' : ''; ?>><?php echo htmlspecialchars(bakery_t('billing.collection_cod')); ?></option>
                <option value="all" <?php echo $collectionFilter === 'all' ? 'selected' : ''; ?>><?php echo htmlspecialchars(bakery_t('billing.collection_all')); ?></option>
            </select></label>
            <label><?php echo htmlspecialchars(bakery_t('billing.filter_from')); ?><input type="date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>"></label>
            <label><?php echo htmlspecialchars(bakery_t('billing.filter_through')); ?><input type="date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>"></label>
            <input type="hidden" name="range" value="custom">
            <button class="ic-btn ic-btn-primary" type="submit"><?php echo htmlspecialchars(bakery_t('billing.filter_apply')); ?></button>
        </div>
        <details class="ic-more-filters">
            <summary><?php echo htmlspecialchars(bakery_t('billing.more_filters')); ?></summary>
            <div class="ic-filter-grid" style="margin-top:10px">
                <label class="ic-check" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="show_unconfirmed" value="1" <?php echo !$deliveredOnly ? 'checked' : ''; ?>> <?php echo htmlspecialchars(bakery_t('billing.show_unconfirmed')); ?></label>
                <label class="ic-check" style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="show_test_rows" value="1" <?php echo !$hideFixtures ? 'checked' : ''; ?>> <?php echo htmlspecialchars(bakery_t('billing.show_test_rows')); ?></label>
            </div>
        </details>
    </form>

    <nav class="ic-attention-strip" aria-label="<?php echo htmlspecialchars(bakery_t('billing.queue_aria')); ?>">
        <a class="ic-chip <?php echo $workQueue === 'to_send' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($query(['queue' => 'to_send', 'invoice_id' => null])); ?>"><?php echo htmlspecialchars(bakery_t('billing.queue_to_send')); ?> <strong><?php echo (int)$queueCounts['to_send']; ?></strong></a>
        <a class="ic-chip <?php echo $workQueue === 'waiting' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($query(['queue' => 'waiting', 'invoice_id' => null])); ?>"><?php echo htmlspecialchars(bakery_t('billing.queue_waiting')); ?> <strong><?php echo (int)$queueCounts['waiting']; ?></strong></a>
        <a class="ic-chip <?php echo $workQueue === 'problems' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($query(['queue' => 'problems', 'invoice_id' => null])); ?>"><?php echo htmlspecialchars(bakery_t('billing.queue_problems')); ?> <strong><?php echo (int)$queueCounts['problems']; ?></strong></a>
        <a class="ic-chip <?php echo $workQueue === 'all' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($query(['queue' => 'all', 'invoice_id' => null])); ?>"><?php echo htmlspecialchars(bakery_t('billing.queue_all')); ?> <strong><?php echo (int)$queueCounts['all']; ?></strong></a>
    </nav>

    <div class="invoice-center-layout">
        <section class="invoice-list-panel">
            <?php if (!$orders): ?>
                <div style="padding:24px;color:#64748b"><?php echo htmlspecialchars(bakery_t('billing.empty_queue')); ?></div>
            <?php else: ?>
                <?php
                $confirmedCount = 0;
                foreach ($orders as $order) {
                    if (!empty($order['delivery_confirmed_at'])) {
                        $confirmedCount++;
                    }
                }
                $emailReady = isset($emailReady) ? $emailReady : bakery_billing_email_ready();
                ?>
                <form method="post" action="billing_api.php" id="bulkInvoiceForm">
                    <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($query(['invoice_id' => null])); ?>">
                    <?php if ($confirmedCount > 0): ?>
                        <div class="ic-bulk-bar">
                            <label class="ic-bulk-select-all"><input type="checkbox" id="bulkSelectAll"> <?php echo htmlspecialchars(bakery_t('billing.select_confirmed')); ?> (<strong><?php echo $confirmedCount; ?></strong>)</label>
                            <div style="display:flex;flex-wrap:wrap;gap:8px">
                                <button class="ic-btn" type="submit" name="action" value="bulk_mark_invoiced" id="bulkInvoiceSubmit" disabled><?php echo htmlspecialchars(bakery_t('billing.mark_invoiced')); ?> (<span id="bulkInvoiceCount">0</span>)</button>
                            </div>
                        </div>
                        <?php if (!$emailReady): ?>
                            <p class="ic-note" style="margin:8px 16px"><?php echo htmlspecialchars(bakery_t('billing.os_email_is_log')); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if (!empty($_GET['bulk_msg'])): ?>
                        <div class="ic-exception-box is-ok" style="margin:10px 16px"><?php echo htmlspecialchars((string)$_GET['bulk_msg']); ?></div>
                    <?php endif; ?>
                <?php foreach ($groupedOrders as $groupLabel => $groupOrders): ?>
                    <?php if ($groupBy !== 'none' && $groupLabel !== ''): ?>
                        <div style="padding:10px 16px;background:#f8fbfb;font-size:.75rem;font-weight:800;color:#0f766e"><?php
                        if ($groupBy === 'attention') {
                            echo htmlspecialchars($attentionMeta[$groupLabel]['label'] ?? $groupLabel);
                        } elseif ($groupBy === 'date') {
                            echo date('l, M j, Y', strtotime($groupLabel));
                        } else {
                            echo htmlspecialchars($groupLabel);
                        }
                        ?></div>
                    <?php endif; ?>
                    <?php foreach ($groupOrders as $order): ?>
                        <?php $orderConfirmed = !empty($order['delivery_confirmed_at']); ?>
                        <div class="invoice-card-row<?php echo !empty($order['needs_attention']) ? ' ops-attention-row' : ''; ?>" id="invoice-<?php echo (int)$order['id']; ?>">
                            <?php if ($orderConfirmed): ?>
                                <input type="checkbox" class="ic-bulk-check" name="order_ids[]" value="<?php echo (int)$order['id']; ?>" aria-label="Select <?php echo htmlspecialchars($order['customer_name']); ?>">
                            <?php endif; ?>
                            <a class="invoice-card <?php echo $selectedInvoiceId === (int)$order['id'] ? 'is-selected' : ''; ?>" href="<?php echo htmlspecialchars($query(['invoice_id' => $order['id']])); ?>">
                                <div>
                                    <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                                    <div style="font-size:.75rem;color:#64748b"><?php echo htmlspecialchars($order['invoice_number']); ?> · <?php echo date('M j', strtotime($order['order_date'])); ?></div>
                                    <?php
                                    $queueLabel = bakery_t('billing.queue_' . (string)($order['work_queue'] ?? 'other'));
                                    if (($order['work_queue'] ?? '') === 'other') {
                                        $queueLabel = (string)($order['category_meta']['short'] ?? '');
                                    }
                                    ?>
                                    <div style="font-size:.7rem;margin-top:2px;color:#334155"><?php echo htmlspecialchars($queueLabel); ?></div>
                                </div>
                                <div><span class="ic-att <?php echo $attentionClassFor($order['category']); ?>"><?php echo htmlspecialchars($order['category_meta']['short']); ?></span></div>
                                <div style="text-align:right"><strong style="color:#0f766e">$<?php echo number_format($order['display_amount'], 2); ?></strong></div>
                            </a>
                            <?php
                            $invFlags = [];
                            if (($order['status'] ?? '') === 'delivered') {
                                $invFlags['uninvoiced'] = true;
                            }
                            if (($order['category'] ?? '') === 'quantity_variance') {
                                $invFlags['qty_variance'] = true;
                            }
                            if (($order['category'] ?? '') === 'incomplete' || empty($order['delivery_confirmed_at'])) {
                                $invFlags['unconfirmed'] = true;
                            }
                            echo bakery_ops_render_row_chips($pageExceptions ?? [], [
                                'customer_id' => (int)($order['customer_id'] ?? 0),
                                'daily_order_id' => (int)$order['id'],
                                'driver_id' => (int)($order['driver_id'] ?? 0),
                                'flags' => $invFlags,
                            ], [
                                'date' => (string)($order['order_date'] ?? $returnDate ?? ''),
                                'return' => (string)($pageReturnKey ?? ''),
                                'daily_order_id' => (int)$order['id'],
                                'link_only' => true,
                            ]);
                            ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </form>
                <script>
                (function () {
                    var form = document.getElementById('bulkInvoiceForm');
                    if (!form) { return; }
                    var checks = form.querySelectorAll('.ic-bulk-check');
                    var countEl = document.getElementById('bulkInvoiceCount');
                    var submitBtn = document.getElementById('bulkInvoiceSubmit');
                    var selectAll = document.getElementById('bulkSelectAll');
                    function refresh() {
                        var n = 0;
                        checks.forEach(function (c) { if (c.checked) { n++; } });
                        if (countEl) { countEl.textContent = n; }
                        if (submitBtn) { submitBtn.disabled = n === 0; }
                    }
                    checks.forEach(function (c) { c.addEventListener('change', refresh); });
                    if (selectAll) {
                        selectAll.addEventListener('change', function () {
                            checks.forEach(function (c) { c.checked = selectAll.checked; });
                            refresh();
                        });
                    }
                    form.addEventListener('submit', function (e) {
                        var msg = <?php echo json_encode(bakery_t('billing.mark_invoiced_confirm')); ?>;
                        if (!window.confirm(msg)) {
                            e.preventDefault();
                        }
                    });
                })();
                </script>
            <?php endif; ?>
        </section>

        <aside class="invoice-detail-panel">
            <?php if ($selectedInvoice): ?>
                <article class="invoice-detail">
                    <div class="<?php echo in_array($selectedInvoice['category'], ['ready', 'already_invoiced'], true) ? 'ic-exception-box is-ok' : 'ic-exception-box'; ?>">
                        <strong><?php echo htmlspecialchars($selectedInvoice['category_meta']['label']); ?></strong>
                        — <?php echo htmlspecialchars($selectedInvoice['category_meta']['help']); ?>
                    </div>
                    <p style="margin:0 0 12px;font-size:.84rem"><strong><?php echo htmlspecialchars($selectedInvoice['invoice_number']); ?></strong> · <?php echo date('F j, Y', strtotime($selectedInvoice['order_date'])); ?></p>

                    <table class="invoice-detail-table">
                        <thead><tr><th>Item</th><th>Ord</th><th>Del</th><th>Price</th><th>Total</th></tr></thead>
                        <tbody>
                        <?php foreach ($selectedInvoice['items'] as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo (int)$item['quantity']; ?></td>
                                <td><?php echo $item['delivered_quantity'] !== null ? (int)$item['delivered_quantity'] : '—'; ?></td>
                                <td><?php echo $item['has_price'] ? '$' . number_format($item['unit_price'], 2) : '—'; ?></td>
                                <td><?php echo $item['has_price'] ? '$' . number_format($item['line_total'], 2) : '—'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="invoice-detail-summary">
                        <?php if ($selectedInvoice['pricing_issue']): ?>
                            <div style="color:#b45309"><strong>Pricing issue — amount may not be trustworthy</strong></div>
                        <?php endif; ?>
                        <div><span><?php echo htmlspecialchars(bakery_t('billing.collection_filter')); ?></span><strong><?php echo htmlspecialchars(!empty($selectedInvoice['is_cod']) ? bakery_t('billing.collection_cod') : bakery_t('billing.collection_invoice')); ?></strong></div>
                        <div><span>Payment / AR</span><strong title="<?php echo htmlspecialchars($selectedInvoice['payment_status']['detail']); ?>"><?php echo htmlspecialchars($selectedInvoice['payment_status']['label']); ?></strong></div>
                        <?php if (!empty($selectedInvoice['square_status'])): ?>
                            <div><span><?php echo htmlspecialchars(bakery_t('billing.square_status')); ?></span><strong><?php echo htmlspecialchars(strtoupper((string)$selectedInvoice['square_status'])); ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($selectedInvoice['square_public_url'])): ?>
                            <div><span><?php echo htmlspecialchars(bakery_t('billing.square_pay_link')); ?></span><strong><a href="<?php echo htmlspecialchars((string)$selectedInvoice['square_public_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(bakery_t('billing.square_open_pay')); ?></a></strong></div>
                        <?php endif; ?>
                        <div><span>Billable total</span><strong>$<?php echo number_format($selectedInvoice['amount_is_billable'] ? $selectedInvoice['billable_amount'] : $selectedInvoice['display_amount'], 2); ?></strong></div>
                    </div>

                    <div class="invoice-detail-footer">
                        <?php
                        $squareConfigured = function_exists('square_is_configured') ? square_is_configured() : false;
                        if (!function_exists('square_is_configured') && is_readable(__DIR__ . '/square_config.php')) {
                            require_once __DIR__ . '/square_config.php';
                            $squareConfigured = square_is_configured();
                        }
                        $canSquare = !empty($selectedInvoice['delivery_confirmed_at'])
                            && empty($selectedInvoice['is_cod'])
                            && in_array((string)$selectedInvoice['category'], ['ready', 'already_invoiced'], true);
                        ?>
                        <?php if ($canSquare): ?>
                            <form method="post" action="billing_api.php" style="display:flex;flex-direction:column;gap:8px;width:100%;padding:12px;border:1px solid #d1fae5;border-radius:12px;background:#f0fdfa">
                                <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                                <input type="hidden" name="action" value="send_square_invoice">
                                <input type="hidden" name="daily_order_id" value="<?php echo (int)$selectedInvoice['id']; ?>">
                                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($query(['invoice_id' => $selectedInvoice['id']])); ?>">
                                <strong><?php echo htmlspecialchars(!empty($selectedInvoice['square_invoice_id']) ? bakery_t('billing.square_send_again') : bakery_t('billing.square_send')); ?></strong>
                                <p class="ic-note" style="margin:0"><?php echo htmlspecialchars(bakery_t('billing.square_send_help')); ?></p>
                                <label style="font-size:.8rem"><?php echo htmlspecialchars(bakery_t('billing.square_test_recipient')); ?>
                                    <input type="email" name="test_recipient" value="" placeholder="danny@sourflour.org" style="width:100%;margin-top:4px">
                                </label>
                                <button class="ic-btn ic-btn-primary" type="submit" <?php echo $squareConfigured ? '' : 'disabled'; ?>><?php echo htmlspecialchars(!empty($selectedInvoice['square_invoice_id']) ? bakery_t('billing.square_send_again') : bakery_t('billing.square_send')); ?></button>
                            </form>
                            <?php if (!empty($selectedInvoice['square_invoice_id'])): ?>
                                <form method="post" action="billing_api.php" style="display:inline">
                                    <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                                    <input type="hidden" name="action" value="refresh_square_invoice">
                                    <input type="hidden" name="daily_order_id" value="<?php echo (int)$selectedInvoice['id']; ?>">
                                    <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($query(['invoice_id' => $selectedInvoice['id']])); ?>">
                                    <button class="ic-btn" type="submit"><?php echo htmlspecialchars(bakery_t('billing.square_refresh')); ?></button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$squareConfigured): ?>
                                <p class="ic-note"><?php echo htmlspecialchars(bakery_t('billing.square_not_configured')); ?></p>
                            <?php endif; ?>
                        <?php elseif (!empty($selectedInvoice['is_cod']) && !empty($selectedInvoice['delivery_confirmed_at'])): ?>
                            <p class="ic-note"><?php echo htmlspecialchars(bakery_t('billing.square_cod_blocked')); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($selectedInvoice['delivery_confirmed_at'])): ?>
                            <a class="ic-btn ic-btn-primary" href="customer_invoice.php?daily_order_id=<?php echo (int)$selectedInvoice['id']; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars(bakery_t('billing.view_invoice')); ?></a>
                        <?php endif; ?>
                        <a class="ic-btn" href="customer_record.php?customer_id=<?php echo (int)$selectedInvoice['customer_id']; ?>&amp;date=<?php echo urlencode($selectedInvoice['order_date']); ?>">Customer hub</a>
                        <details style="width:100%;margin-top:8px">
                            <summary style="cursor:pointer;font-size:.82rem;font-weight:700;color:#475569"><?php echo htmlspecialchars(bakery_t('billing.more_actions')); ?></summary>
                            <div class="invoice-detail-footer" style="margin-top:8px">
                        <?php if ($selectedInvoice['delivery_confirmed_at'] && $selectedInvoice['status'] !== 'invoiced'): ?>
                            <form method="post" action="billing_api.php" style="display:inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(bakery_t('billing.mark_invoiced_confirm_one')), ENT_QUOTES, 'UTF-8'); ?>);">
                                <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                                <input type="hidden" name="action" value="mark_invoiced">
                                <input type="hidden" name="daily_order_id" value="<?php echo (int)$selectedInvoice['id']; ?>">
                                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($query(['invoice_id' => $selectedInvoice['id']])); ?>">
                                <button class="ic-btn" type="submit"><?php echo htmlspecialchars(bakery_t('billing.mark_invoiced')); ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if (!empty($selectedInvoice['delivery_confirmed_at'])): ?>
                            <form method="post" action="billing_api.php" style="display:inline" onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(bakery_t('billing.send_confirm_one')), ENT_QUOTES, 'UTF-8'); ?>);">
                                <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                                <input type="hidden" name="action" value="send_invoice">
                                <input type="hidden" name="daily_order_id" value="<?php echo (int)$selectedInvoice['id']; ?>">
                                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($query(['invoice_id' => $selectedInvoice['id']])); ?>">
                                <button class="ic-btn" type="submit"><?php echo htmlspecialchars(!empty($selectedInvoice['invoice_was_sent']) ? bakery_t('billing.resend_invoice') : bakery_t('billing.send_invoice')); ?></button>
                            </form>
                        <?php endif; ?>
                        <a class="ic-btn" href="daily_orders.php?date=<?php echo urlencode($selectedInvoice['order_date']); ?>">Daily order</a>
                        <button class="ic-btn" type="button" onclick="window.print()">Print</button>
                            </div>
                        </details>
                    </div>
                    <?php if (!empty($selectedInvoice['invoice_was_sent'])): ?>
                        <p class="ic-note"><?php
                            echo htmlspecialchars(bakery_t('billing.os_send_note'));
                            echo ' · ' . htmlspecialchars((string)$selectedInvoice['invoice_sent_at']);
                        ?></p>
                    <?php endif; ?>
                    <p class="ic-note"><?php echo htmlspecialchars(bakery_t('billing.snapshot_note')); ?></p>
                </article>
            <?php else: ?>
                <div style="padding:24px;color:#64748b">Select a delivery to review billing details.</div>
            <?php endif; ?>
        </aside>
    </div>
</section>
