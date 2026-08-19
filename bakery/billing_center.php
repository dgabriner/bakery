<?php
/**
 * Billing Center — customer accounts, invoice reconciliation, statements, accounting export.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/operational_exceptions.php';

bakery_billing_ensure_invoice_send_schema($db);

$page_title = bakery_t('page.billing_center');

$orderStatuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'delivered', 'invoiced'];
$rangePresets = ['today', 'week', 'month', 'last_month', 'custom'];
$statusOptions = array_merge(['all', 'open'], $orderStatuses);

$panel = (string)($_GET['panel'] ?? 'invoices');
$panels = ['invoices', 'customer', 'export', 'exceptions'];
if (!in_array($panel, $panels, true)) {
    $panel = 'invoices';
}
if ($panel === 'exceptions') {
    $_GET['attention'] = 'needs_attention';
    $panel = 'invoices';
}

$today = new DateTimeImmutable('today');
$defaultStart = $today->modify('first day of this month')->format('Y-m-d');
$defaultEnd = $today->format('Y-m-d');

// Shared invoice-panel filter state
$range = (string)($_GET['range'] ?? 'month');
if (!in_array($range, $rangePresets, true)) {
    $range = 'month';
}
$selectedMonth = date('Y-m');
switch ($range) {
    case 'today':
        $startDate = $today->format('Y-m-d');
        $endDate = $startDate;
        break;
    case 'week':
        $weekStart = $today->modify('monday this week');
        $startDate = $weekStart->format('Y-m-d');
        $endDate = $weekStart->modify('+6 days')->format('Y-m-d');
        break;
    case 'last_month':
        $lastMonth = $today->modify('first day of last month');
        $startDate = $lastMonth->format('Y-m-01');
        $endDate = $lastMonth->modify('last day of this month')->format('Y-m-d');
        break;
    case 'custom':
        $startDate = trim((string)($_GET['start_date'] ?? $defaultStart));
        $endDate = trim((string)($_GET['end_date'] ?? $defaultEnd));
        break;
    default:
        $selectedMonth = (string)($_GET['month'] ?? date('Y-m'));
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $selectedMonth) ?: $today->modify('first day of this month');
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
$viewMode = (string)($_GET['view'] ?? 'cards');
$attentionFilter = (string)($_GET['attention'] ?? '');
$returnDate = $startDate ?? $defaultEnd;
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $returnDate);
$pageReturnKey = $returnTarget['key'] ?? null;
$attentionLabel = $attentionFilter === 'needs_attention' ? 'Showing items requiring attention' : '';
$pageExceptions = [];
try {
    $pageExceptions = bakery_ops_exceptions_for_date($db, $returnDate, $pageReturnKey);
} catch (Throwable $e) {
    error_log('billing_center exceptions: ' . $e->getMessage());
}
$customers = $db->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

$acctStart = trim((string)($_GET['start_date'] ?? $defaultStart));
$acctEnd = trim((string)($_GET['end_date'] ?? $defaultEnd));
$exportStart = trim((string)($_GET['export_start'] ?? $defaultStart));
$exportEnd = trim((string)($_GET['export_end'] ?? $defaultEnd));

$account = null;
$accountError = null;
if ($panel === 'customer' && $customerId > 0) {
    try {
        $account = bakery_billing_customer_account($db, $customerId, $acctStart, $acctEnd);
    } catch (Throwable $e) {
        $accountError = $e->getMessage();
    }
}

$recentExports = bakery_billing_recent_exports($db, 15);
$tablesReady = bakery_billing_tables_ready($db);
$emailReady = bakery_billing_email_ready();

$panelTab = function ($key, $label) use ($panel, $customerId, $acctStart, $acctEnd) {
    $params = ['panel' => $key];
    if ($customerId > 0 && in_array($key, ['customer', 'export'], true)) {
        $params['customer_id'] = $customerId;
    }
    if ($key === 'customer') {
        $params['start_date'] = $acctStart;
        $params['end_date'] = $acctEnd;
    }
    $href = 'billing_center.php?' . http_build_query($params);
    $active = $panel === $key ? ' is-active' : '';
    return '<a class="bc-tab' . $active . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
};

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
.bc-wrap{max-width:1440px;margin:0 auto;padding:24px 20px 60px;color:#172033}
.bc-wrap *{box-sizing:border-box}
.bc-header{margin-bottom:20px}
.bc-eyebrow{margin:0 0 6px;color:#0f766e;font-size:.72rem;font-weight:800;letter-spacing:.11em;text-transform:uppercase}
.bc-header h1{margin:0;font-size:clamp(1.7rem,3.5vw,2.4rem);letter-spacing:-.03em}
.bc-subtitle{margin:8px 0 0;color:#64748b;max-width:820px;font-size:.92rem}
.bc-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 22px;border-bottom:1px solid #e2e8f0;padding-bottom:12px}
.bc-tab{display:inline-flex;align-items:center;min-height:38px;padding:7px 14px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#334155;font-weight:700;font-size:.84rem;text-decoration:none}
.bc-tab.is-active{border-color:#0f766e;background:#0f766e;color:#fff}
.bc-tab--warn{border-color:#fdba74;background:#fff7ed;color:#c2410c}
.bc-panel{border:1px solid #e2e8f0;border-radius:16px;background:#fff;padding:20px;box-shadow:0 4px 16px #0f172a0a}
.bc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0}
.bc-metric{padding:14px;border:1px solid #edf2f4;border-radius:12px;background:#f8fbfb}
.bc-metric strong{display:block;font-size:1.3rem;color:#0f766e}
.bc-metric span{font-size:.72rem;color:#64748b;font-weight:700}
.bc-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end;margin-bottom:16px}
.bc-form label{display:flex;flex-direction:column;gap:4px;font-size:.75rem;font-weight:800;color:#475569}
.bc-form input,.bc-form select{min-height:40px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:9px;font:inherit}
.bc-btn{display:inline-flex;align-items:center;min-height:40px;padding:8px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#334155;font-weight:700;text-decoration:none;cursor:pointer;font-size:.86rem}
.bc-btn-primary{background:#0f766e;border-color:#0f766e;color:#fff}
.bc-table{width:100%;border-collapse:collapse;font-size:.84rem}
.bc-table th,.bc-table td{padding:10px 12px;border-bottom:1px solid #edf2f4;text-align:left}
.bc-table th{font-size:.68rem;text-transform:uppercase;color:#64748b;background:#f8fbfb}
.bc-table .num{text-align:right}
.bc-note{margin-top:14px;padding:12px;border-radius:10px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:.82rem}
.bc-alert{padding:12px 14px;border-radius:10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;margin-bottom:14px;font-size:.86rem}
.bc-ar-label{font-size:.78rem;color:#64748b}
</style>

<div class="bc-wrap">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <header class="bc-header">
        <p class="bc-eyebrow">Orders &amp; Customers</p>
        <h1><?php bakery_te('page.billing_center'); ?></h1>
        <p class="bc-subtitle">
            Find customers and delivery-backed invoices, reconcile billing exceptions, produce statements,
            and export deterministic accounting data. Payment in QuickBooks is <strong>not</strong> tracked here unless noted as COD collected at delivery.
        </p>
    </header>

    <nav class="bc-tabs" aria-label="Billing Center panels">
        <?php echo $panelTab('invoices', 'Invoice reconciliation'); ?>
        <?php echo $panelTab('customer', 'Customer account'); ?>
        <?php echo $panelTab('export', 'Accounting export'); ?>
        <a class="bc-tab bc-tab--warn" href="billing_center.php?panel=exceptions">Billing exceptions</a>
    </nav>

    <?php if ($panel === 'invoices'): ?>
        <?php require __DIR__ . '/includes/billing_panel_invoices.php'; ?>
    <?php elseif ($panel === 'customer'): ?>
        <section class="bc-panel">
            <h2 style="margin:0 0 8px;font-size:1.1rem">Customer billing account</h2>
            <p style="margin:0 0 16px;color:#64748b;font-size:.86rem">Financial-operational history from delivery snapshots — trace any charge back to its delivery.</p>

            <form class="bc-form" method="get">
                <input type="hidden" name="panel" value="customer">
                <label>Customer
                    <select name="customer_id" required>
                        <option value="">Select…</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $customerId === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>From<input type="date" name="start_date" value="<?php echo htmlspecialchars($acctStart); ?>"></label>
                <label>Through<input type="date" name="end_date" value="<?php echo htmlspecialchars($acctEnd); ?>"></label>
                <button class="bc-btn bc-btn-primary" type="submit">View account</button>
            </form>

            <?php if ($accountError): ?>
                <div class="bc-alert"><?php echo htmlspecialchars($accountError); ?></div>
            <?php elseif ($account): ?>
                <?php $cust = $account['customer']; ?>
                <?php if ($account['missing_billing_contact']): ?>
                    <div class="bc-alert">Billing exception: this customer has no email or address on file — statements may be incomplete.</div>
                <?php endif; ?>

                <div class="bc-grid">
                    <div class="bc-metric"><strong><?php echo number_format($account['totals']['invoice_count']); ?></strong><span>Deliveries in period</span></div>
                    <div class="bc-metric"><strong>$<?php echo number_format($account['totals']['billable_total'], 2); ?></strong><span>Confirmed billable</span></div>
                    <div class="bc-metric"><strong><?php echo number_format($account['totals']['invoiced_count']); ?></strong><span>Marked invoiced</span></div>
                    <div class="bc-metric"><strong><?php echo number_format($account['totals']['needs_attention']); ?></strong><span>Need attention</span></div>
                    <?php if ($account['totals']['cod_collected'] > 0): ?>
                    <div class="bc-metric"><strong>$<?php echo number_format($account['totals']['cod_collected'], 2); ?></strong><span>COD recorded at delivery</span></div>
                    <?php endif; ?>
                </div>

                <p class="bc-note"><?php echo htmlspecialchars($account['payment_tracking_note']); ?></p>

                <div style="display:flex;flex-wrap:wrap;gap:8px;margin:16px 0">
                    <a class="bc-btn bc-btn-primary" target="_blank" rel="noopener"
                       href="customer_statement.php?customer_id=<?php echo $customerId; ?>&amp;start_date=<?php echo urlencode($acctStart); ?>&amp;end_date=<?php echo urlencode($acctEnd); ?>&amp;record=1">
                        Generate statement
                    </a>
                    <a class="bc-btn" href="billing_center.php?panel=invoices&amp;customer_id=<?php echo $customerId; ?>&amp;range=custom&amp;start_date=<?php echo urlencode($acctStart); ?>&amp;end_date=<?php echo urlencode($acctEnd); ?>">
                        Reconcile in invoice list
                    </a>
                    <a class="bc-btn" href="customer_record.php?customer_id=<?php echo $customerId; ?>">Operational record</a>
                </div>

                <?php if (!$emailReady): ?>
                    <p class="bc-note"><?php echo htmlspecialchars(bakery_t('billing.email_not_configured')); ?></p>
                <?php endif; ?>

                <table class="bc-table">
                    <thead>
                        <tr>
                            <th>Delivery</th>
                            <th>Invoice #</th>
                            <th>Attention</th>
                            <th class="num">Amount</th>
                            <th>Payment / AR</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($account['invoices'] as $inv): ?>
                        <tr>
                            <td><?php echo date('M j, Y', strtotime($inv['order_date'])); ?></td>
                            <td><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                            <td><?php echo htmlspecialchars($inv['category_meta']['short']); ?></td>
                            <td class="num">
                                <?php if ($inv['pricing_issue'] && !$inv['amount_is_billable']): ?>
                                    <span title="Pricing issue — amount not trusted">—</span>
                                <?php else: ?>
                                    $<?php echo number_format($inv['amount_is_billable'] ? $inv['billable_amount'] : $inv['display_amount'], 2); ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="bc-ar-label" title="<?php echo htmlspecialchars($inv['payment_status']['detail']); ?>"><?php echo htmlspecialchars($inv['payment_status']['label']); ?></span></td>
                            <td>
                                <a class="bc-btn" style="min-height:32px;padding:4px 10px;font-size:.78rem"
                                   href="billing_center.php?panel=invoices&amp;invoice_id=<?php echo (int)$inv['id']; ?>&amp;customer_id=<?php echo $customerId; ?>&amp;range=custom&amp;start_date=<?php echo urlencode($inv['order_date']); ?>&amp;end_date=<?php echo urlencode($inv['order_date']); ?>">Detail</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($account['statements']): ?>
                    <h3 style="margin:24px 0 10px;font-size:.95rem">Statement history</h3>
                    <table class="bc-table">
                        <thead><tr><th>Date</th><th>Period</th><th class="num">Total</th><th>Sent</th></tr></thead>
                        <tbody>
                        <?php foreach ($account['statements'] as $st): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($st['statement_date'])); ?></td>
                                <td><?php echo htmlspecialchars($st['period_start'] . ' – ' . $st['period_end']); ?></td>
                                <td class="num">$<?php echo number_format((float)$st['total_amount'], 2); ?></td>
                                <td><?php echo $st['sent_at'] ? htmlspecialchars(date('M j, Y', strtotime($st['sent_at'])) . ($st['sent_to_email'] ? ' → ' . $st['sent_to_email'] : '')) : 'Generated only'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($tablesReady): ?>
                    <form method="post" action="billing_api.php" style="margin-top:20px;padding-top:16px;border-top:1px solid #edf2f4">
                        <?php if (function_exists('bakery_csrf_field')) {
                            bakery_csrf_field();
                        } ?>
                        <input type="hidden" name="action" value="record_statement">
                        <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                        <input type="hidden" name="start_date" value="<?php echo htmlspecialchars($acctStart); ?>">
                        <input type="hidden" name="end_date" value="<?php echo htmlspecialchars($acctEnd); ?>">
                        <p style="font-size:.84rem;color:#64748b;margin:0 0 10px">Record statement activity (without opening print view):</p>
                        <div class="bc-form">
                            <label>Statement date<input type="date" name="statement_date" value="<?php echo date('Y-m-d'); ?>"></label>
                            <label>Optional sent-to email<input type="email" name="sent_to_email" placeholder="<?php echo htmlspecialchars($cust['email'] ?? ''); ?>"></label>
                            <label style="flex-direction:row;align-items:center;gap:8px;margin-top:22px">
                                <input type="checkbox" name="mark_sent" value="1"> Mark as sent
                            </label>
                            <button class="bc-btn bc-btn-primary" type="submit">Record statement</button>
                        </div>
                    </form>
                <?php endif; ?>
            <?php elseif ($customerId === 0): ?>
                <p class="bc-note">Select a customer to view invoices, delivery dates, and statement history.</p>
            <?php endif; ?>
        </section>

    <?php elseif ($panel === 'export'): ?>
        <section class="bc-panel">
            <h2 style="margin:0 0 8px;font-size:1.1rem">Accounting export</h2>
            <p style="margin:0 0 16px;color:#64748b;font-size:.86rem">
                Deterministic CSV from delivery snapshots (<code>daily_order_items.unit_price</code>).
                Re-exporting the same period produces the same invoice IDs and historical prices.
                <?php if ($tablesReady): ?>Export batches are recorded when you download.<?php endif; ?>
            </p>

            <form class="bc-form" method="get" action="billing_export.php">
                <label>From<input type="date" name="start_date" value="<?php echo htmlspecialchars($exportStart); ?>" required></label>
                <label>Through<input type="date" name="end_date" value="<?php echo htmlspecialchars($exportEnd); ?>" required></label>
                <label>Customer (optional)
                    <select name="customer_id">
                        <option value="0">All customers</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?php echo (int)$c['id']; ?>" <?php echo $customerId === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex-direction:row;align-items:center;gap:8px;margin-top:22px">
                    <input type="checkbox" name="include_unconfirmed" value="1"> Include unconfirmed deliveries
                </label>
                <button class="bc-btn bc-btn-primary" type="submit">Download CSV</button>
            </form>

            <div class="bc-note">
                <strong>CSV columns:</strong>
                invoice_id, daily_order_id, customer_id, customer_name, invoice_date, delivery_date,
                product_id, product_name, quantity_ordered, quantity_delivered, unit_price, line_total,
                invoice_total, credits_taken_back, pricing_label, status, memo.
                See <code>docs/billing_quickbooks_boundary.md</code> for QuickBooks mapping notes.
            </div>

            <?php if ($recentExports): ?>
                <h3 style="margin:24px 0 10px;font-size:.95rem">Recent exports</h3>
                <table class="bc-table">
                    <thead>
                        <tr><th>When</th><th>Key</th><th>Period</th><th class="num">Rows</th><th class="num">Invoices</th><th>By</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentExports as $ex): ?>
                        <tr>
                            <td><?php echo date('M j, Y g:i A', strtotime($ex['created_at'])); ?></td>
                            <td><code><?php echo htmlspecialchars($ex['export_key']); ?></code></td>
                            <td><?php echo htmlspecialchars($ex['period_start'] . ' – ' . $ex['period_end']); ?></td>
                            <td class="num"><?php echo number_format((int)$ex['row_count']); ?></td>
                            <td class="num"><?php echo number_format((int)$ex['invoice_count']); ?></td>
                            <td><?php echo htmlspecialchars($ex['created_by_name'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif (!$tablesReady): ?>
                <p class="bc-note">Run migration <code>022_billing_center</code> to enable export history tracking.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
