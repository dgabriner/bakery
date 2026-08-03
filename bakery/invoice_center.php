<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'Invoice Center';

$orderStatuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];
$rangePresets = ['today', 'week', 'month', 'last_month', 'custom'];
$groupOptions = ['none', 'customer', 'date', 'status', 'zone'];
$sortOptions = ['date_desc', 'date_asc', 'customer', 'amount_desc', 'amount_asc', 'status'];
$viewOptions = ['cards', 'table'];
$statusOptions = array_merge(['all', 'open'], $orderStatuses);

$range = (string)($_GET['range'] ?? 'month');
if (!in_array($range, $rangePresets, true)) {
    $range = 'month';
}

$today = new DateTimeImmutable('today');
$startDate = '';
$endDate = '';
$selectedMonth = date('Y-m');

switch ($range) {
    case 'today':
        $startDate = $today->format('Y-m-d');
        $endDate = $startDate;
        $selectedMonth = $today->format('Y-m');
        break;
    case 'week':
        $weekStart = $today->modify('monday this week');
        $weekEnd = $weekStart->modify('+6 days');
        $startDate = $weekStart->format('Y-m-d');
        $endDate = $weekEnd->format('Y-m-d');
        $selectedMonth = $today->format('Y-m');
        break;
    case 'last_month':
        $lastMonth = $today->modify('first day of last month');
        $startDate = $lastMonth->format('Y-m-01');
        $endDate = $lastMonth->modify('last day of this month')->format('Y-m-d');
        $selectedMonth = $lastMonth->format('Y-m');
        break;
    case 'custom':
        $startRaw = (string)($_GET['start_date'] ?? '');
        $endRaw = (string)($_GET['end_date'] ?? '');
        $startObj = DateTimeImmutable::createFromFormat('!Y-m-d', $startRaw);
        $endObj = DateTimeImmutable::createFromFormat('!Y-m-d', $endRaw);
        if (!$startObj || $startObj->format('Y-m-d') !== $startRaw) {
            $startObj = $today->modify('first day of this month');
        }
        if (!$endObj || $endObj->format('Y-m-d') !== $endRaw) {
            $endObj = $today->modify('last day of this month');
        }
        if ($endObj < $startObj) {
            $tmp = $startObj;
            $startObj = $endObj;
            $endObj = $tmp;
        }
        $startDate = $startObj->format('Y-m-d');
        $endDate = $endObj->format('Y-m-d');
        $selectedMonth = $startObj->format('Y-m');
        break;
    case 'month':
    default:
        $range = 'month';
        $selectedMonth = (string)($_GET['month'] ?? date('Y-m'));
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth);
        if (!$monthDate || $monthDate->format('Y-m') !== $selectedMonth) {
            $selectedMonth = date('Y-m');
            $monthDate = new DateTimeImmutable('first day of this month');
        }
        $startDate = $monthDate->format('Y-m-01');
        $endDate = $monthDate->modify('last day of this month')->format('Y-m-d');
        break;
}

$customerId = max(0, (int)($_GET['customer_id'] ?? 0));
$searchQ = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = 'all';
}
$zoneFilter = trim((string)($_GET['zone'] ?? ''));
$driverId = max(0, (int)($_GET['driver_id'] ?? 0));
$productLineId = max(0, (int)($_GET['product_line_id'] ?? 0));
$amountMinRaw = trim((string)($_GET['amount_min'] ?? ''));
$amountMaxRaw = trim((string)($_GET['amount_max'] ?? ''));
$amountMin = $amountMinRaw !== '' && is_numeric($amountMinRaw) ? (float)$amountMinRaw : null;
$amountMax = $amountMaxRaw !== '' && is_numeric($amountMaxRaw) ? (float)$amountMaxRaw : null;
$deliveredOnly = isset($_GET['delivered_only']) && (string)$_GET['delivered_only'] === '1';
$groupBy = (string)($_GET['group'] ?? 'customer');
if (!in_array($groupBy, $groupOptions, true)) {
    $groupBy = 'customer';
}
$sortBy = (string)($_GET['sort'] ?? 'date_desc');
if (!in_array($sortBy, $sortOptions, true)) {
    $sortBy = 'date_desc';
}
$viewMode = (string)($_GET['view'] ?? 'cards');
if (!in_array($viewMode, $viewOptions, true)) {
    $viewMode = 'cards';
}
$selectedInvoiceId = max(0, (int)($_GET['invoice_id'] ?? 0));

$customers = $db->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$zones = $db->query("SELECT DISTINCT zone FROM customers WHERE zone IS NOT NULL AND zone <> '' ORDER BY zone")->fetchAll(PDO::FETCH_COLUMN);
$drivers = $db->query('SELECT id, name FROM drivers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$productLines = $db->query('SELECT id, name FROM product_lines ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$where = ['do.order_date BETWEEN ? AND ?'];
$params = [$startDate, $endDate];

if ($customerId > 0) {
    $where[] = 'do.customer_id = ?';
    $params[] = $customerId;
}
if ($statusFilter === 'open') {
    $where[] = "do.status NOT IN ('delivered', 'invoiced')";
} elseif ($statusFilter !== 'all') {
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
if ($deliveredOnly) {
    $where[] = 'do.delivery_confirmed_at IS NOT NULL';
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
            c.id AS customer_id, c.name AS customer_name, c.address AS customer_address,
            c.zone AS customer_zone
     FROM daily_orders do
     JOIN customers c ON c.id = do.customer_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY ' . $orderSql
);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($searchQ !== '') {
    $searchLower = mb_strtolower($searchQ);
    $orders = array_values(array_filter($orders, function ($order) use ($searchLower) {
        $invoiceNumber = 'INV-' . date('Ymd', strtotime($order['order_date'])) . '-' . str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT);
        $haystacks = [
            mb_strtolower((string)$order['customer_name']),
            mb_strtolower($invoiceNumber),
            (string)$order['id'],
            mb_strtolower((string)($order['customer_zone'] ?? '')),
        ];
        foreach ($haystacks as $hay) {
            if ($hay !== '' && strpos($hay, $searchLower) !== false) {
                return true;
            }
        }
        return false;
    }));
}

$stats = ['count' => 0, 'total' => 0.0, 'delivered' => 0, 'open' => 0, 'confirmed' => 0];
foreach ($orders as &$order) {
    $order['total_amount'] = (float)$order['total_amount'];
    $order['delivery_order_total'] = $order['delivery_order_total'] !== null ? (float)$order['delivery_order_total'] : null;
    $order['display_amount'] = $order['delivery_order_total'] !== null ? $order['delivery_order_total'] : $order['total_amount'];
    $order['amount_is_billable'] = $order['delivery_confirmed_at'] !== null && $order['delivery_order_total'] !== null;
    $order['status_label'] = ucwords(str_replace('_', ' ', (string)$order['status']));
    $order['invoice_number'] = 'INV-' . date('Ymd', strtotime($order['order_date'])) . '-' . str_pad((string)$order['id'], 5, '0', STR_PAD_LEFT);
    $order['customer_zone'] = trim((string)($order['customer_zone'] ?? ''));
    $order['zone_label'] = $order['customer_zone'] !== '' ? $order['customer_zone'] : 'No zone';

    $stats['count']++;
    $stats['total'] += $order['display_amount'];
    if (in_array($order['status'], ['delivered', 'invoiced'], true)) {
        $stats['delivered']++;
    } else {
        $stats['open']++;
    }
    if ($order['delivery_confirmed_at']) {
        $stats['confirmed']++;
    }
}
unset($order);

$orderIds = array_map(function ($o) { return (int)$o['id']; }, $orders);
if ($selectedInvoiceId > 0 && !in_array($selectedInvoiceId, $orderIds, true)) {
    $selectedInvoiceId = 0;
}
if ($selectedInvoiceId === 0 && $orders) {
    $selectedInvoiceId = (int)$orders[0]['id'];
}

$selectedInvoice = null;
$selectedItems = [];
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
if ($selectedInvoice) {
    $itemStmt = $db->prepare(
        'SELECT doi.quantity, doi.delivered_quantity, doi.unit_price, doi.line_total,
                p.name AS product_name, pl.name AS product_line_name
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ?
         ORDER BY pl.name, p.name'
    );
    $itemStmt->execute([$selectedInvoiceId]);
    $selectedItems = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
}

$baseQueryParams = [
    'range' => $range,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'month' => $selectedMonth,
    'customer_id' => $customerId,
    'q' => $searchQ,
    'status' => $statusFilter,
    'zone' => $zoneFilter,
    'driver_id' => $driverId,
    'product_line_id' => $productLineId,
    'amount_min' => $amountMinRaw,
    'amount_max' => $amountMaxRaw,
    'group' => $groupBy,
    'sort' => $sortBy,
    'view' => $viewMode,
];
if ($deliveredOnly) {
    $baseQueryParams['delivered_only'] = '1';
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
        }
    }
    return http_build_query($merged);
};

$groupedOrders = [];
if ($groupBy === 'none') {
    $groupedOrders[''] = $orders;
} else {
    foreach ($orders as $order) {
        switch ($groupBy) {
            case 'date':
                $label = $order['order_date'];
                break;
            case 'status':
                $label = $order['status_label'];
                break;
            case 'zone':
                $label = $order['zone_label'];
                break;
            case 'customer':
            default:
                $label = $order['customer_name'];
                break;
        }
        $groupedOrders[$label][] = $order;
    }
}

$rangeLabel = $startDate === $endDate
    ? date('M j, Y', strtotime($startDate))
    : date('M j, Y', strtotime($startDate)) . ' – ' . date('M j, Y', strtotime($endDate));

$statusClassFor = function ($status) {
    if ($status === 'invoiced') {
        return 'invoice-status--invoiced';
    }
    if ($status === 'delivered') {
        return '';
    }
    return 'invoice-status--open';
};

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<style>
.invoice-center{max-width:1440px;margin:0 auto;padding:28px 20px 60px;color:#172033}
.invoice-center *{box-sizing:border-box}
.invoice-center-header{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}
.invoice-center-eyebrow{margin:0 0 6px;color:#0f766e;font-size:.72rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.invoice-center h1{margin:0;font-size:clamp(1.8rem,4vw,2.7rem);letter-spacing:-.04em}
.invoice-center-subtitle{margin:8px 0 0;color:#64748b;max-width:640px}
.invoice-center-actions{display:flex;gap:8px;flex-wrap:wrap}
.ic-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font-weight:750;text-decoration:none;cursor:pointer}
.ic-btn:disabled,.ic-btn.is-disabled{opacity:.45;pointer-events:none}
.ic-btn-primary{border-color:#0f766e;background:#0f766e;color:#fff}
.ic-btn-ghost{background:transparent}
.ic-btn-sm{min-height:34px;padding:6px 11px;font-size:.82rem}
.invoice-center-filters{display:flex;flex-direction:column;gap:12px;padding:14px;margin-bottom:18px;border:1px solid #dbe4ea;border-radius:16px;background:#f8fbfb}
.ic-date-strip{display:flex;flex-wrap:wrap;align-items:end;gap:10px}
.ic-date-presets{display:flex;flex-wrap:wrap;gap:6px}
.ic-date-presets .ic-btn{min-height:36px;padding:7px 12px;font-size:.82rem}
.ic-date-presets .ic-btn.is-active{border-color:#0f766e;background:#0f766e;color:#fff}
.ic-date-inputs{display:flex;flex-wrap:wrap;gap:10px;flex:1}
.ic-date-inputs label{min-width:140px;flex:1}
.invoice-center-filters label{display:flex;flex-direction:column;gap:5px;color:#475569;font-size:.75rem;font-weight:800}
.invoice-center-filters input,.invoice-center-filters select{width:100%;min-height:42px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#172033;font:inherit}
.ic-filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ic-filter-actions{display:flex;flex-wrap:wrap;align-items:end;gap:8px}
.ic-filter-actions .ic-check{flex-direction:row;align-items:center;gap:8px;min-height:42px;font-size:.8rem}
.ic-filter-actions .ic-check input{width:auto;min-height:0}
.invoice-center-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px}
.invoice-center-stat{padding:16px;border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 4px 16px #0f172a0a}
.invoice-center-stat strong{display:block;font-size:1.5rem;color:#0f766e}
.invoice-center-stat span{color:#64748b;font-size:.78rem;font-weight:700}
.invoice-center-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(360px,.78fr);gap:18px;align-items:start}
.invoice-list-panel,.invoice-detail-panel{min-width:0;border:1px solid #e2e8f0;border-radius:16px;background:#fff;box-shadow:0 5px 20px #0f172a0d;overflow:hidden}
.invoice-panel-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:15px 17px;border-bottom:1px solid #e2e8f0}
.invoice-panel-heading h2{margin:0;font-size:1.05rem}
.invoice-panel-heading span{color:#64748b;font-size:.78rem}
.invoice-panel-tools{display:flex;align-items:center;gap:8px}
.invoice-group-title{padding:12px 17px 7px;color:#0f766e;font-size:.75rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase;background:#f8fbfb;border-bottom:1px solid #edf2f4}
.invoice-card{display:grid;grid-template-columns:1.25fr .9fr auto;gap:12px;align-items:center;padding:14px 17px;border-bottom:1px solid #edf2f4;text-decoration:none;color:inherit}
.invoice-card:last-child{border-bottom:0}
.invoice-card:hover,.invoice-card.is-selected{background:#f0fdfa}
.invoice-card-customer strong,.invoice-card-total strong{display:block}
.invoice-card-customer span,.invoice-card-meta span{display:block;margin-top:3px;color:#64748b;font-size:.75rem}
.invoice-card-total{text-align:right}
.invoice-card-total strong{font-size:1.05rem;color:#0f766e}
.invoice-card-total .ic-billable{color:#0f766e;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.04em}
.invoice-status{display:inline-flex;align-items:center;min-height:24px;padding:3px 8px;border-radius:999px;background:#ecfdf5;color:#047857;font-size:.68rem;font-weight:850}
.invoice-status--open{background:#fff7ed;color:#c2410c}
.invoice-status--invoiced{background:#eef2ff;color:#4338ca}
.invoice-table{width:100%;border-collapse:collapse;font-size:.82rem}
.invoice-table th{padding:10px 12px;border-bottom:1px solid #cbd5e1;color:#64748b;font-size:.68rem;text-align:left;text-transform:uppercase;background:#f8fbfb}
.invoice-table td{padding:11px 12px;border-bottom:1px solid #edf2f4;vertical-align:middle}
.invoice-table tr{cursor:pointer}
.invoice-table tr:hover,.invoice-table tr.is-selected{background:#f0fdfa}
.invoice-table .num,.invoice-table th.num{text-align:right}
.invoice-detail-panel{position:sticky;top:14px}
.invoice-detail{padding:20px}
.invoice-detail-nav{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}
.invoice-detail-nav-meta{color:#64748b;font-size:.75rem;font-weight:700}
.invoice-detail-brand{display:flex;justify-content:space-between;gap:12px;padding-bottom:16px;border-bottom:3px solid #0f766e}
.invoice-detail-brand strong{display:block;color:#0f766e;font-size:1.12rem}
.invoice-detail-brand span{color:#64748b;font-size:.72rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
.invoice-detail-number{text-align:right;color:#475569;font-size:.74rem}
.invoice-detail-number strong{display:block;color:#172033;font-size:.86rem}
.invoice-bill-to{padding:15px 0}
.invoice-bill-to small{display:block;margin-bottom:4px;color:#64748b;font-size:.7rem;font-weight:850;text-transform:uppercase}
.invoice-bill-to strong{display:block;font-size:1rem}
.invoice-bill-to span{display:block;margin-top:3px;color:#475569;font-size:.82rem}
.invoice-detail-table{width:100%;border-collapse:collapse;font-size:.8rem}
.invoice-detail-table th{padding:8px 4px;border-bottom:1px solid #cbd5e1;color:#64748b;font-size:.68rem;text-align:left;text-transform:uppercase}
.invoice-detail-table td{padding:9px 4px;border-bottom:1px solid #edf2f4;vertical-align:top}
.invoice-detail-table th:not(:first-child),.invoice-detail-table td:not(:first-child){text-align:right}
.invoice-detail-summary{margin-top:14px;padding-top:12px;border-top:2px solid #e2e8f0}
.invoice-detail-summary div{display:flex;justify-content:space-between;gap:12px;padding:4px 0;color:#475569;font-size:.82rem}
.invoice-detail-summary .invoice-grand-total{margin-top:5px;padding-top:10px;border-top:1px solid #cbd5e1;color:#047857;font-size:1.15rem;font-weight:850}
.invoice-detail-footer{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}
.invoice-empty{padding:38px 20px;color:#64748b;text-align:center}
.invoice-kbd-hint{margin:0;color:#94a3b8;font-size:.7rem}
@media(max-width:1100px){.ic-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.invoice-center-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:980px){.invoice-center-layout{grid-template-columns:1fr}.invoice-detail-panel{position:static}}
@media(max-width:720px){
  .invoice-center{padding:20px 12px 40px}
  .invoice-center-header{display:block}
  .invoice-center-actions{margin-top:14px}
  .ic-filter-grid{grid-template-columns:1fr 1fr}
  .invoice-center-stats{grid-template-columns:repeat(2,1fr)}
  .invoice-card{grid-template-columns:1fr auto;gap:7px}
  .invoice-card-meta{grid-column:1/-1;order:3}
  .invoice-card-total{grid-column:2;grid-row:1}
  .invoice-table{display:block;overflow-x:auto}
}
@media print{
  .invoice-center-header,.invoice-center-filters,.invoice-center-stats,.invoice-list-panel,.invoice-detail-footer,.invoice-detail-nav,.auth-bar{display:none!important}
  .invoice-center{padding:0}
  .invoice-center-layout{display:block}
  .invoice-detail-panel{border:0;box-shadow:none}
  .invoice-detail{padding:0}
}
</style>
<main class="invoice-center">
    <header class="invoice-center-header">
        <div>
            <p class="invoice-center-eyebrow">Sales &amp; payments</p>
            <h1>Invoice Center</h1>
            <p class="invoice-center-subtitle">Filter by date, customer, zone, driver, or product line; switch views; and step through invoice details without losing your place.</p>
        </div>
        <div class="invoice-center-actions">
            <?php if ($customerId > 0): ?>
                <a class="ic-btn" href="simple_invoice.php?customer_id=<?php echo $customerId; ?>&amp;start_date=<?php echo htmlspecialchars($startDate); ?>&amp;end_date=<?php echo htmlspecialchars($endDate); ?>" target="_blank" rel="noopener">Open range receipt</a>
            <?php endif; ?>
        </div>
    </header>

    <form class="invoice-center-filters" method="get" id="invoiceCenterFilters">
        <input type="hidden" name="range" id="icRangeInput" value="<?php echo htmlspecialchars($range); ?>">
        <input type="hidden" name="view" id="icViewInput" value="<?php echo htmlspecialchars($viewMode); ?>">
        <?php if ($selectedInvoiceId > 0): ?>
            <input type="hidden" name="invoice_id" value="<?php echo (int)$selectedInvoiceId; ?>">
        <?php endif; ?>

        <div class="ic-date-strip">
            <div>
                <label style="margin-bottom:6px">Date range</label>
                <div class="ic-date-presets" role="group" aria-label="Date presets">
                    <?php
                    $presetLabels = [
                        'today' => 'Today',
                        'week' => 'This week',
                        'month' => 'This month',
                        'last_month' => 'Last month',
                        'custom' => 'Custom',
                    ];
                    foreach ($presetLabels as $presetKey => $presetLabel):
                    ?>
                        <button type="button" class="ic-btn <?php echo $range === $presetKey ? 'is-active' : ''; ?>" data-range="<?php echo htmlspecialchars($presetKey); ?>"><?php echo htmlspecialchars($presetLabel); ?></button>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="ic-date-inputs">
                <?php if ($range === 'month'): ?>
                    <label>Month
                        <input type="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                    </label>
                <?php endif; ?>
                <label>Start
                    <input type="date" name="start_date" id="icStartDate" value="<?php echo htmlspecialchars($startDate); ?>">
                </label>
                <label>End
                    <input type="date" name="end_date" id="icEndDate" value="<?php echo htmlspecialchars($endDate); ?>">
                </label>
            </div>
        </div>

        <div class="ic-filter-grid">
            <label>Customer
                <select name="customer_id">
                    <option value="0">All customers</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo (int)$customer['id']; ?>" <?php echo $customerId === (int)$customer['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($customer['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Search
                <input type="search" name="q" value="<?php echo htmlspecialchars($searchQ); ?>" placeholder="Customer, invoice #, or ID">
            </label>
            <label>Status
                <select name="status">
                    <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All invoices</option>
                    <option value="open" <?php echo $statusFilter === 'open' ? 'selected' : ''; ?>>Open (not delivered)</option>
                    <?php foreach ($orderStatuses as $st): ?>
                        <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $st))); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Zone
                <select name="zone">
                    <option value="">All zones</option>
                    <?php foreach ($zones as $zone): ?>
                        <option value="<?php echo htmlspecialchars($zone); ?>" <?php echo $zoneFilter === $zone ? 'selected' : ''; ?>><?php echo htmlspecialchars($zone); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Driver
                <select name="driver_id">
                    <option value="0">All drivers</option>
                    <?php foreach ($drivers as $driver): ?>
                        <option value="<?php echo (int)$driver['id']; ?>" <?php echo $driverId === (int)$driver['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($driver['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Product line
                <select name="product_line_id">
                    <option value="0">All product lines</option>
                    <?php foreach ($productLines as $line): ?>
                        <option value="<?php echo (int)$line['id']; ?>" <?php echo $productLineId === (int)$line['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($line['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Amount min
                <input type="number" name="amount_min" step="0.01" min="0" value="<?php echo htmlspecialchars($amountMinRaw); ?>" placeholder="0.00">
            </label>
            <label>Amount max
                <input type="number" name="amount_max" step="0.01" min="0" value="<?php echo htmlspecialchars($amountMaxRaw); ?>" placeholder="0.00">
            </label>
            <label>Group by
                <select name="group">
                    <option value="none" <?php echo $groupBy === 'none' ? 'selected' : ''; ?>>No grouping</option>
                    <option value="customer" <?php echo $groupBy === 'customer' ? 'selected' : ''; ?>>Customer</option>
                    <option value="date" <?php echo $groupBy === 'date' ? 'selected' : ''; ?>>Date</option>
                    <option value="status" <?php echo $groupBy === 'status' ? 'selected' : ''; ?>>Status</option>
                    <option value="zone" <?php echo $groupBy === 'zone' ? 'selected' : ''; ?>>Zone</option>
                </select>
            </label>
            <label>Sort
                <select name="sort">
                    <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>Date (newest)</option>
                    <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>Date (oldest)</option>
                    <option value="customer" <?php echo $sortBy === 'customer' ? 'selected' : ''; ?>>Customer</option>
                    <option value="amount_desc" <?php echo $sortBy === 'amount_desc' ? 'selected' : ''; ?>>Amount (high–low)</option>
                    <option value="amount_asc" <?php echo $sortBy === 'amount_asc' ? 'selected' : ''; ?>>Amount (low–high)</option>
                    <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>Status</option>
                </select>
            </label>
            <div class="ic-filter-actions">
                <label class="ic-check">
                    <input type="checkbox" name="delivered_only" value="1" <?php echo $deliveredOnly ? 'checked' : ''; ?>>
                    Delivery confirmed only
                </label>
                <button class="ic-btn ic-btn-primary" type="submit">Apply filters</button>
                <a class="ic-btn" href="invoice_center.php">Clear</a>
            </div>
        </div>
    </form>

    <section class="invoice-center-stats">
        <div class="invoice-center-stat"><strong><?php echo number_format($stats['count']); ?></strong><span>Invoices in view</span></div>
        <div class="invoice-center-stat"><strong>$<?php echo number_format($stats['total'], 2); ?></strong><span>Total sales in view</span></div>
        <div class="invoice-center-stat"><strong><?php echo number_format($stats['delivered']); ?></strong><span>Delivered / invoiced</span></div>
        <div class="invoice-center-stat"><strong><?php echo number_format($stats['open']); ?></strong><span>Open orders</span></div>
        <div class="invoice-center-stat"><strong><?php echo number_format($stats['confirmed']); ?></strong><span>Delivery confirmed</span></div>
    </section>

    <div class="invoice-center-layout">
        <section class="invoice-list-panel">
            <div class="invoice-panel-heading">
                <div>
                    <h2><?php echo htmlspecialchars($rangeLabel); ?></h2>
                    <span><?php echo count($orders); ?> records</span>
                </div>
                <div class="invoice-panel-tools">
                    <a class="ic-btn ic-btn-sm <?php echo $viewMode === 'cards' ? 'ic-btn-primary' : ''; ?>" href="?<?php echo htmlspecialchars($query(['view' => 'cards', 'invoice_id' => $selectedInvoiceId ?: null])); ?>">Cards</a>
                    <a class="ic-btn ic-btn-sm <?php echo $viewMode === 'table' ? 'ic-btn-primary' : ''; ?>" href="?<?php echo htmlspecialchars($query(['view' => 'table', 'invoice_id' => $selectedInvoiceId ?: null])); ?>">Table</a>
                </div>
            </div>

            <?php if (!$orders): ?>
                <div class="invoice-empty">No invoices match these filters.</div>
            <?php elseif ($viewMode === 'table'): ?>
                <div style="overflow-x:auto">
                    <table class="invoice-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice #</th>
                                <th>Customer</th>
                                <th>Zone</th>
                                <th>Status</th>
                                <th class="num">Pcs / credits</th>
                                <th class="num">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupedOrders as $groupLabel => $groupOrders): ?>
                                <?php if ($groupBy !== 'none' && $groupLabel !== ''): ?>
                                    <tr class="invoice-group-row">
                                        <td colspan="7" class="invoice-group-title" style="border-bottom:1px solid #edf2f4">
                                            <?php
                                            if ($groupBy === 'date') {
                                                echo htmlspecialchars(date('l, F j, Y', strtotime($groupLabel)));
                                            } else {
                                                echo htmlspecialchars($groupLabel);
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($groupOrders as $order): ?>
                                    <?php
                                    $statusClass = $statusClassFor($order['status']);
                                    $isSelected = $selectedInvoiceId === (int)$order['id'];
                                    $pcsLabel = $order['delivery_confirmed_at']
                                        ? number_format((int)$order['delivered_pieces']) . ' / ' . number_format((int)$order['credits_taken_back'])
                                        : '—';
                                    ?>
                                    <tr class="<?php echo $isSelected ? 'is-selected' : ''; ?>" data-invoice-link="?<?php echo htmlspecialchars($query(['invoice_id' => $order['id']])); ?>" onclick="window.location.href=this.getAttribute('data-invoice-link')">
                                        <td><?php echo date('M j, Y', strtotime($order['order_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($order['invoice_number']); ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['zone_label']); ?></td>
                                        <td><span class="invoice-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($order['status_label']); ?></span></td>
                                        <td class="num"><?php echo htmlspecialchars($pcsLabel); ?></td>
                                        <td class="num">
                                            <strong>$<?php echo number_format($order['display_amount'], 2); ?></strong>
                                            <?php if ($order['amount_is_billable']): ?><div class="ic-billable">Billable</div><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <?php foreach ($groupedOrders as $groupLabel => $groupOrders): ?>
                    <?php if ($groupBy !== 'none' && $groupLabel !== ''): ?>
                        <div class="invoice-group-title">
                            <?php
                            if ($groupBy === 'date') {
                                echo htmlspecialchars(date('l, F j, Y', strtotime($groupLabel)));
                            } else {
                                echo htmlspecialchars($groupLabel);
                            }
                            ?>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($groupOrders as $order): ?>
                        <?php $statusClass = $statusClassFor($order['status']); ?>
                        <a class="invoice-card <?php echo $selectedInvoiceId === (int)$order['id'] ? 'is-selected' : ''; ?>" href="?<?php echo htmlspecialchars($query(['invoice_id' => $order['id']])); ?>">
                            <div class="invoice-card-customer">
                                <strong><?php echo htmlspecialchars($order['customer_name']); ?></strong>
                                <span><?php echo htmlspecialchars($order['invoice_number']); ?></span>
                                <?php if ($order['customer_zone'] !== ''): ?>
                                    <span><?php echo htmlspecialchars($order['customer_zone']); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="invoice-card-meta">
                                <span><?php echo date('M j, Y', strtotime($order['order_date'])); ?></span>
                                <span class="invoice-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($order['status_label']); ?></span>
                                <?php if ($order['delivery_confirmed_at']): ?>
                                    <span>Confirmed · <?php echo number_format((int)$order['delivered_pieces']); ?> pcs</span>
                                <?php endif; ?>
                            </div>
                            <div class="invoice-card-total">
                                <strong>$<?php echo number_format($order['display_amount'], 2); ?></strong>
                                <?php if ($order['amount_is_billable']): ?>
                                    <span class="ic-billable">Billable</span>
                                <?php else: ?>
                                    <span>View invoice →</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <aside class="invoice-detail-panel">
            <?php if ($selectedInvoice): ?>
                <?php
                $orderedPieces = array_sum(array_map(function ($item) {
                    return (int)$item['quantity'];
                }, $selectedItems));
                $displayTotal = $selectedInvoice['display_amount'];
                ?>
                <article class="invoice-detail">
                    <div class="invoice-detail-nav">
                        <div>
                            <?php if ($prevInvoiceId): ?>
                                <a class="ic-btn ic-btn-sm" id="icPrevLink" href="?<?php echo htmlspecialchars($query(['invoice_id' => $prevInvoiceId])); ?>">← Prev</a>
                            <?php else: ?>
                                <span class="ic-btn ic-btn-sm is-disabled" aria-disabled="true">← Prev</span>
                            <?php endif; ?>
                            <?php if ($nextInvoiceId): ?>
                                <a class="ic-btn ic-btn-sm" id="icNextLink" href="?<?php echo htmlspecialchars($query(['invoice_id' => $nextInvoiceId])); ?>">Next →</a>
                            <?php else: ?>
                                <span class="ic-btn ic-btn-sm is-disabled" aria-disabled="true">Next →</span>
                            <?php endif; ?>
                        </div>
                        <div class="invoice-detail-nav-meta">
                            <?php echo ($selectedIndex + 1); ?> of <?php echo count($orders); ?>
                            <p class="invoice-kbd-hint">j / k or ← → to navigate</p>
                        </div>
                    </div>

                    <div class="invoice-detail-brand">
                        <div>
                            <span>Sales receipt / invoice</span>
                            <strong>Sour Flour Bakery</strong>
                        </div>
                        <div class="invoice-detail-number">
                            Invoice #<strong><?php echo htmlspecialchars($selectedInvoice['invoice_number']); ?></strong>
                            <span><?php echo date('F j, Y', strtotime($selectedInvoice['order_date'])); ?></span>
                        </div>
                    </div>

                    <div class="invoice-bill-to">
                        <small>Bill to</small>
                        <strong><?php echo htmlspecialchars($selectedInvoice['customer_name']); ?></strong>
                        <span><?php echo htmlspecialchars($selectedInvoice['customer_address'] ?: 'No address on file'); ?></span>
                        <?php if ($selectedInvoice['customer_zone'] !== ''): ?>
                            <span>Zone: <?php echo htmlspecialchars($selectedInvoice['customer_zone']); ?></span>
                        <?php endif; ?>
                        <span>Status: <?php echo htmlspecialchars($selectedInvoice['status_label']); ?></span>
                    </div>

                    <table class="invoice-detail-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($selectedItems): ?>
                                <?php foreach ($selectedItems as $item): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                            <?php if (!empty($item['product_line_name'])): ?>
                                                <br><small><?php echo htmlspecialchars($item['product_line_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int)$item['quantity']; ?></td>
                                        <td>$<?php echo number_format((float)$item['unit_price'], 2); ?></td>
                                        <td>$<?php echo number_format((float)$item['line_total'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4">No item details recorded.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <div class="invoice-detail-summary">
                        <div><span>Ordered pieces</span><strong><?php echo number_format($orderedPieces); ?></strong></div>
                        <?php if ($selectedInvoice['delivered_pieces'] !== null || $selectedInvoice['delivery_confirmed_at']): ?>
                            <div><span>Pieces delivered</span><strong><?php echo number_format((int)$selectedInvoice['delivered_pieces']); ?></strong></div>
                        <?php endif; ?>
                        <?php if ((int)$selectedInvoice['credits_taken_back'] > 0 || $selectedInvoice['delivery_confirmed_at']): ?>
                            <div><span>Credits taken back</span><strong><?php echo number_format((int)$selectedInvoice['credits_taken_back']); ?></strong></div>
                        <?php endif; ?>
                        <?php if (!empty($selectedInvoice['delivery_pricing_label'])): ?>
                            <div><span>Pricing</span><strong><?php echo htmlspecialchars($selectedInvoice['delivery_pricing_label']); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedInvoice['delivery_order_total'] !== null): ?>
                            <div><span>Delivery total</span><strong>$<?php echo number_format($selectedInvoice['delivery_order_total'], 2); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($selectedInvoice['delivery_confirmed_at']): ?>
                            <div><span>Confirmed at</span><strong><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($selectedInvoice['delivery_confirmed_at']))); ?></strong></div>
                        <?php endif; ?>
                        <div class="invoice-grand-total">
                            <span><?php echo $selectedInvoice['amount_is_billable'] ? 'Billable total' : 'Total'; ?></span>
                            <strong>$<?php echo number_format($displayTotal, 2); ?></strong>
                        </div>
                    </div>

                    <div class="invoice-detail-footer">
                        <button class="ic-btn ic-btn-primary" type="button" onclick="window.print()">Print invoice</button>
                        <a class="ic-btn" href="simple_invoice.php?customer_id=<?php echo (int)$selectedInvoice['customer_id']; ?>&amp;start_date=<?php echo htmlspecialchars($selectedInvoice['order_date']); ?>&amp;end_date=<?php echo htmlspecialchars($selectedInvoice['order_date']); ?>" target="_blank" rel="noopener">Open printable receipt</a>
                    </div>
                </article>
            <?php else: ?>
                <div class="invoice-empty">
                    <strong>Select an invoice</strong>
                    <p>Choose any invoice on the left to open its full receipt here.</p>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</main>
<script>
(function () {
    var form = document.getElementById('invoiceCenterFilters');
    var rangeInput = document.getElementById('icRangeInput');
    if (form && rangeInput) {
        form.querySelectorAll('[data-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                rangeInput.value = btn.getAttribute('data-range') || 'month';
                form.querySelectorAll('[data-range]').forEach(function (b) { b.classList.remove('is-active'); });
                btn.classList.add('is-active');
                if (rangeInput.value === 'custom') {
                    // Keep current dates; just mark custom and submit.
                } else if (rangeInput.value !== 'month') {
                    // Clear month so presets own the range.
                    var monthEl = form.querySelector('input[name="month"]');
                    if (monthEl) monthEl.removeAttribute('name');
                }
                form.submit();
            });
        });
        var startEl = document.getElementById('icStartDate');
        var endEl = document.getElementById('icEndDate');
        function markCustom() {
            rangeInput.value = 'custom';
            form.querySelectorAll('[data-range]').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-range') === 'custom');
            });
        }
        if (startEl) startEl.addEventListener('change', markCustom);
        if (endEl) endEl.addEventListener('change', markCustom);
    }

    function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'select' || tag === 'textarea' || el.isContentEditable;
    }

    document.addEventListener('keydown', function (e) {
        if (isTypingTarget(e.target)) return;
        var prev = document.getElementById('icPrevLink');
        var next = document.getElementById('icNextLink');
        if ((e.key === 'j' || e.key === 'ArrowRight') && next) {
            e.preventDefault();
            window.location.href = next.getAttribute('href');
        } else if ((e.key === 'k' || e.key === 'ArrowLeft') && prev) {
            e.preventDefault();
            window.location.href = prev.getAttribute('href');
        }
    });
})();
</script>
<?php require_once 'includes/footer.php'; ?>
