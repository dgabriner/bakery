<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_record.php';
require_once __DIR__ . '/includes/operational_timeline.php';
require_once __DIR__ . '/includes/operational_exceptions.php';

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$page_title = bakery_t('page.customer_record');

$customerId = max(0, (int)($_GET['customer_id'] ?? 0));
$selectedDate = trim((string)($_GET['date'] ?? ''));
if ($selectedDate === '') {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}
$dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
    $dateObj = new DateTimeImmutable($selectedDate);
}
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$pageReturnKey = $returnTarget['key'] ?? null;

$record = null;
$error = null;

if ($customerId > 0) {
    try {
        $record = bakery_customer_record_build($db, $customerId, $selectedDate);
        $page_title = 'Customer Record — ' . $record['customer']['name'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$customerList = $db->query(
    'SELECT id, name, zone, is_active FROM customers ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$dayShort = bakery_customer_record_day_short_labels();
$dayLong = bakery_customer_record_day_labels();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<style>
.cr-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}
.cr-header {
    background: linear-gradient(135deg, #2c5282 0%, #2b6cb0 100%);
    color: #fff;
    border-radius: 14px;
    padding: 24px 28px;
    margin-bottom: 24px;
    box-shadow: 0 8px 24px rgba(44, 82, 130, 0.25);
}
.cr-header h1 {
    margin: 0 0 6px;
    font-size: 1.8rem;
}
.cr-header .cr-subtitle {
    opacity: 0.92;
    margin: 0;
}
.cr-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
    background: #fff;
    border-radius: 12px;
    padding: 16px 18px;
    margin-bottom: 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}
.cr-toolbar label {
    display: block;
    font-size: 0.78rem;
    font-weight: 600;
    color: #4a5568;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.cr-toolbar select,
.cr-toolbar input[type="date"] {
    min-width: 200px;
    padding: 8px 10px;
    border: 1px solid #cbd5e0;
    border-radius: 8px;
    font-size: 0.95rem;
}
.cr-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    text-decoration: none;
    border: 1px solid transparent;
    cursor: pointer;
    white-space: nowrap;
}
.cr-btn-primary { background: #3182ce; color: #fff; }
.cr-btn-secondary { background: #edf2f7; color: #2d3748; border-color: #cbd5e0; }
.cr-btn-ghost { background: transparent; color: #3182ce; border-color: #bee3f8; }
.cr-summary {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 18px;
    margin-bottom: 20px;
}
@media (max-width: 900px) {
    .cr-summary { grid-template-columns: 1fr; }
}
.cr-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    margin-bottom: 18px;
}
.cr-card h2 {
    margin: 0 0 14px;
    font-size: 1.05rem;
    color: #2d3748;
    border-bottom: 2px solid #edf2f7;
    padding-bottom: 8px;
}
.cr-meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px 16px;
}
.cr-meta-item .label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #718096;
    margin-bottom: 2px;
}
.cr-meta-item .value {
    font-size: 0.95rem;
    color: #1a202c;
    word-break: break-word;
}
.cr-status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
}
.cr-status-active { background: #c6f6d5; color: #22543d; }
.cr-status-inactive { background: #fed7d7; color: #742a2a; }
.cr-hints {
    list-style: none;
    margin: 0;
    padding: 0;
}
.cr-hints li {
    padding: 8px 12px;
    border-radius: 8px;
    margin-bottom: 8px;
    font-size: 0.88rem;
}
.cr-hint-alert { background: #fff5f5; border-left: 4px solid #e53e3e; color: #742a2a; }
.cr-hint-warn { background: #fffaf0; border-left: 4px solid #dd6b20; color: #7b341e; }
.cr-hint-info { background: #ebf8ff; border-left: 4px solid #3182ce; color: #2c5282; }
.cr-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}
.cr-week-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 8px;
}
@media (max-width: 768px) {
    .cr-week-grid { grid-template-columns: repeat(2, 1fr); }
}
.cr-day-col {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 10px;
    background: #f7fafc;
    min-height: 80px;
}
.cr-day-col.is-selected {
    border-color: #3182ce;
    background: #ebf8ff;
    box-shadow: 0 0 0 2px rgba(49, 130, 206, 0.15);
}
.cr-day-col.has-standing { background: #f0fff4; }
.cr-day-col .day-name {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #4a5568;
    margin-bottom: 6px;
}
.cr-day-col .day-units {
    font-size: 1.1rem;
    font-weight: 700;
    color: #2f855a;
}
.cr-day-col .day-driver {
    font-size: 0.75rem;
    color: #4a5568;
    margin-top: 4px;
}
.cr-day-col .day-empty {
    font-size: 0.8rem;
    color: #a0aec0;
    font-style: italic;
}
.cr-compare {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media (max-width: 768px) {
    .cr-compare { grid-template-columns: 1fr; }
}
.cr-compare-panel {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
}
.cr-compare-panel header {
    padding: 10px 14px;
    font-weight: 700;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.cr-panel-standing header { background: #e6fffa; color: #234e52; }
.cr-panel-daily header { background: #ebf8ff; color: #2c5282; }
.cr-compare-panel table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.cr-compare-panel th,
.cr-compare-panel td {
    padding: 8px 12px;
    border-top: 1px solid #edf2f7;
    text-align: left;
}
.cr-compare-panel th { background: #f7fafc; font-size: 0.75rem; color: #718096; }
.cr-compare-panel .num { text-align: right; font-variant-numeric: tabular-nums; }
.cr-state-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 0.8rem;
    font-weight: 700;
    margin-bottom: 12px;
}
.state-ok { background: #c6f6d5; color: #22543d; }
.state-warn { background: #feebc8; color: #7b341e; }
.state-alert { background: #fed7d7; color: #742a2a; }
.state-info { background: #bee3f8; color: #2c5282; }
.state-muted { background: #edf2f7; color: #4a5568; }
.cr-diff-table td.diff { color: #c05621; font-weight: 600; }
.cr-route-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
@media (max-width: 700px) {
    .cr-route-row { grid-template-columns: 1fr; }
}
.cr-route-box {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 12px 14px;
    background: #f7fafc;
}
.cr-route-box h3 {
    margin: 0 0 8px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #718096;
}
.cr-history-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}
.cr-history-table th,
.cr-history-table td {
    padding: 8px 10px;
    border-bottom: 1px solid #edf2f7;
    text-align: left;
}
.cr-history-table th {
    font-size: 0.75rem;
    text-transform: uppercase;
    color: #718096;
    background: #f7fafc;
}
.cr-history-table .num { text-align: right; font-variant-numeric: tabular-nums; }
.cr-variance-pos { color: #2f855a; }
.cr-variance-neg { color: #c53030; }
.cr-empty {
    color: #718096;
    font-style: italic;
    padding: 12px 0;
}
.cr-error {
    background: #fff5f5;
    border: 1px solid #feb2b2;
    color: #742a2a;
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 18px;
}
.cr-success {
    background: #f0fff4;
    border: 1px solid #9ae6b4;
    color: #22543d;
    padding: 12px 16px;
    border-radius: 10px;
    margin-bottom: 18px;
}
.cr-picker {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cr-picker input[type="search"] {
    min-width: 220px;
    padding: 8px 10px;
    border: 1px solid #cbd5e0;
    border-radius: 8px;
    font-size: 0.95rem;
}
.cr-jump {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 20px;
    padding: 14px 16px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
}
.cr-jump-label {
    width: 100%;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #718096;
    font-weight: 700;
    margin-bottom: 2px;
}
.cr-back {
    margin-bottom: 12px;
}
.customer-hub-link {
    color: inherit;
    text-decoration: none;
    font-weight: 600;
}
.customer-hub-link:hover {
    color: #2b6cb0;
    text-decoration: underline;
}
</style>

<div class="cr-container">
    <?php echo bakery_ops_render_return_banner($returnTarget, ''); ?>
    <div class="cr-back">
        <a class="cr-btn cr-btn-ghost" href="customers.php<?php echo $customerId > 0 ? '?highlight=' . (int)$customerId : ''; ?>">← Customers list</a>
    </div>
    <div class="cr-header">
        <h1><?php echo $customerId > 0 && $record ? htmlspecialchars($record['customer']['name']) : 'Customer Hub'; ?></h1>
        <p class="cr-subtitle">Who they are, what they normally get, what’s scheduled, and where to jump next.</p>
    </div>

    <?php if (!empty($_GET['created'])): ?>
    <div class="cr-success">Customer created. Review the record below, then use the jump links for standing orders, pricing, or schedule.</div>
    <?php endif; ?>

    <form class="cr-toolbar" method="get" action="customer_record.php" id="crToolbar">
        <div class="cr-picker">
            <label for="customer_filter">Find customer</label>
            <input type="search" id="customer_filter" placeholder="Type a name or zone…" autocomplete="off" aria-controls="customer_id">
            <label for="customer_id" class="cr-visually-hidden" style="position:absolute;left:-9999px;">Customer</label>
            <select name="customer_id" id="customer_id" required>
                <option value="">Select a customer…</option>
                <?php foreach ($customerList as $c): ?>
                <option value="<?php echo (int)$c['id']; ?>"
                    data-search="<?php echo htmlspecialchars(strtolower(trim($c['name'] . ' ' . ($c['zone'] ?? '') . (!(bool)$c['is_active'] ? ' inactive' : ''))), ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo (int)$c['id'] === $customerId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($c['name']); ?>
                    <?php if (!(bool)$c['is_active']): ?> (inactive)<?php endif; ?>
                    <?php if (!empty($c['zone'])): ?> — <?php echo htmlspecialchars($c['zone']); ?><?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="date">Delivery date</label>
            <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($selectedDate); ?>"
                   onchange="if (document.getElementById('customer_id').value) this.form.submit();">
        </div>
        <?php if ($customerId > 0): ?>
        <button type="submit" class="cr-btn cr-btn-primary">Refresh</button>
        <?php endif; ?>
    </form>

    <?php if ($error): ?>
    <div class="cr-error"><?php echo htmlspecialchars($error); ?></div>
    <?php elseif ($customerId <= 0): ?>
    <div class="cr-card">
        <p class="cr-empty">Select a customer above to view their operational record.</p>
    </div>
    <?php elseif ($record): ?>
    <?php
        $cust = $record['customer'];
        $ctx = $record['date_context'];
        $zoneLabel = $record['zone_label'];
        $isActive = (bool)($cust['is_active'] ?? 1);
        $deliverWindow = '';
        if (!empty($cust['deliver_by']) || !empty($cust['deliver_after'])) {
            $parts = [];
            if (!empty($cust['deliver_after'])) {
                $parts[] = 'after ' . date('g:i A', strtotime($cust['deliver_after']));
            }
            if (!empty($cust['deliver_by'])) {
                $parts[] = 'by ' . date('g:i A', strtotime($cust['deliver_by']));
            }
            $deliverWindow = implode(', ', $parts);
        }
        $customerNameEnc = rawurlencode($cust['name']);
        $zoneEnc = rawurlencode($cust['zone'] ?? '');
        $recordUrl = bakery_customer_record_url($customerId, $selectedDate);
    ?>

    <div class="cr-summary">
        <div class="cr-card" style="margin-bottom:0;">
            <h2><?php echo htmlspecialchars($cust['name']); ?></h2>
            <div class="cr-meta-grid">
                <div class="cr-meta-item">
                    <span class="label">Status</span>
                    <span class="value">
                        <span class="cr-status-badge <?php echo $isActive ? 'cr-status-active' : 'cr-status-inactive'; ?>">
                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                        </span>
                    </span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Zone</span>
                    <span class="value"><?php echo htmlspecialchars($zoneLabel); ?></span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Phone</span>
                    <span class="value"><?php echo $cust['phone'] ? htmlspecialchars($cust['phone']) : '—'; ?></span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Email</span>
                    <span class="value"><?php echo $cust['email'] ? htmlspecialchars($cust['email']) : '—'; ?></span>
                </div>
                <div class="cr-meta-item" style="grid-column: span 2;">
                    <span class="label">Address</span>
                    <span class="value"><?php echo $cust['address'] ? htmlspecialchars($cust['address']) : '—'; ?></span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Delivery pattern</span>
                    <span class="value">
                        <?php if ($record['delivery_days']): ?>
                            <?php echo htmlspecialchars(implode(', ', $record['delivery_days'])); ?>
                        <?php else: ?>
                            No standing schedule
                        <?php endif; ?>
                    </span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Delivery window</span>
                    <span class="value"><?php echo $deliverWindow !== '' ? htmlspecialchars($deliverWindow) : '—'; ?></span>
                </div>
                <div class="cr-meta-item">
                    <span class="label">Pricing tier</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($cust['pricing_tier'] ?? 'retail')); ?></span>
                </div>
            </div>
            <div class="cr-actions">
                <a class="cr-btn cr-btn-secondary" href="customers.php?q=<?php echo $customerNameEnc; ?>&amp;highlight=<?php echo $customerId; ?>">Edit contact details</a>
                <a class="cr-btn cr-btn-secondary" href="standing_orders_manager.php?customer_id=<?php echo $customerId; ?>">Standing orders</a>
                <a class="cr-btn cr-btn-secondary" href="customer_schedule.php?customer_id=<?php echo $customerId; ?>">Delivery schedule</a>
                <a class="cr-btn cr-btn-secondary" href="customer_pricing.php?customer_id=<?php echo $customerId; ?>">Custom pricing</a>
                <a class="cr-btn cr-btn-secondary" href="billing_center.php?panel=customer&amp;customer_id=<?php echo $customerId; ?>">Billing &amp; statements</a>
                <a class="cr-btn cr-btn-secondary" href="service_issues.php?status=all&amp;customer_id=<?php echo $customerId; ?>">Service issues</a>
                <a class="cr-btn cr-btn-ghost" href="customer_overview.php">Zone overview</a>
                <a class="cr-btn cr-btn-ghost" href="map.php">Map / coordinates</a>
            </div>
        </div>

        <?php if ($record['hints']): ?>
        <div class="cr-card" style="margin-bottom:0;">
            <h2>Operational notes</h2>
            <ul class="cr-hints">
                <?php foreach ($record['hints'] as $hint): ?>
                <li class="cr-hint-<?php echo htmlspecialchars($hint['level']); ?>">
                    <?php echo htmlspecialchars($hint['message']); ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <nav class="cr-jump" aria-label="Customer workflows">
        <div class="cr-jump-label">Jump to workflow</div>
        <a class="cr-btn cr-btn-primary" href="daily_orders.php?date=<?php echo htmlspecialchars($selectedDate); ?>&amp;customer=<?php echo $customerNameEnc; ?>&amp;view=edit">Today’s / selected orders</a>
        <a class="cr-btn cr-btn-secondary" href="standing_orders_manager.php?customer_id=<?php echo $customerId; ?>">Standing pattern</a>
        <a class="cr-btn cr-btn-secondary" href="customer_schedule.php?customer_id=<?php echo $customerId; ?>">Route schedule</a>
        <a class="cr-btn cr-btn-secondary" href="customer_pricing.php?customer_id=<?php echo $customerId; ?>">Pricing</a>
        <a class="cr-btn cr-btn-secondary" href="billing_center.php?panel=invoices&amp;customer_id=<?php echo $customerId; ?>&amp;range=month">Invoices</a>
        <a class="cr-btn cr-btn-secondary" href="billing_center.php?panel=customer&amp;customer_id=<?php echo $customerId; ?>">Account / statement</a>
        <a class="cr-btn cr-btn-secondary" href="service_issues.php?status=all&amp;customer_id=<?php echo $customerId; ?>">Service issues</a>
        <a class="cr-btn cr-btn-ghost" href="operational_timeline.php?context=customer&amp;customer_id=<?php echo $customerId; ?>">Timeline</a>
    </nav>

    <div class="cr-card">
        <h2>Normal schedule — standing orders by weekday</h2>
        <p style="margin:0 0 14px;color:#718096;font-size:0.88rem;">
            Recurring expectation from <code>standing_orders</code> and recurring route from <code>standing_routes</code>.
            This is not the dated delivery for <?php echo date('M j, Y', strtotime($selectedDate)); ?>.
        </p>
        <div class="cr-week-grid">
            <?php foreach ($dayShort as $dayNum => $dayLabel): ?>
            <?php
                $dayInfo = $record['standing_by_day'][$dayNum] ?? ['items' => [], 'total_units' => 0];
                $hasStanding = ($dayInfo['total_units'] ?? 0) > 0;
                $isSelectedDay = ($dayNum === (int)$record['day_of_week']);
                $colClass = 'cr-day-col';
                if ($isSelectedDay) {
                    $colClass .= ' is-selected';
                }
                if ($hasStanding) {
                    $colClass .= ' has-standing';
                }
            ?>
            <div class="<?php echo $colClass; ?>">
                <div class="day-name"><?php echo htmlspecialchars($dayLabel); ?></div>
                <?php if ($hasStanding): ?>
                    <div class="day-units"><?php echo (int)$dayInfo['total_units']; ?> units</div>
                    <div class="day-driver">
                        <?php if (!empty($dayInfo['driver_name'])): ?>
                            🚚 <?php echo htmlspecialchars($dayInfo['driver_name']); ?>
                            <?php if (!empty($dayInfo['route_order'])): ?>
                                · stop #<?php echo (int)$dayInfo['route_order']; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            No recurring driver
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="day-empty">No standing order</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cr-actions">
            <a class="cr-btn cr-btn-ghost" href="standing_orders_manager.php?customer_id=<?php echo $customerId; ?>">Open standing orders editor</a>
            <a class="cr-btn cr-btn-ghost" href="customer_routes.php">View customer routes</a>
            <a class="cr-btn cr-btn-ghost" href="standing_routes.php">Edit standing routes</a>
            <a class="cr-btn cr-btn-ghost" href="customer_schedule.php?customer_id=<?php echo $customerId; ?>">Delivery schedule</a>
        </div>
    </div>

    <?php
    $relatedSituations = [];
    try {
        $relatedSituations = bakery_ops_customer_related_situations($db, $customerId, $selectedDate, $pageReturnKey);
    } catch (Throwable $e) {
        error_log('customer_record situations: ' . $e->getMessage());
    }
    echo bakery_ops_render_customer_related_strip($relatedSituations, $selectedDate);
    ?>

    <div class="cr-card">
        <h2>Selected date — <?php echo date('l, M j, Y', strtotime($selectedDate)); ?></h2>
        <span class="cr-state-badge <?php echo bakery_customer_record_state_class($ctx['state']); ?>">
            <?php echo htmlspecialchars(bakery_customer_record_state_label($ctx['state'])); ?>
        </span>
        <?php if (!empty($ctx['paused'])): ?>
        <span class="cr-state-badge state-muted">Paused this week</span>
        <?php endif; ?>

        <div class="cr-compare">
            <div class="cr-compare-panel cr-panel-standing">
                <header>Normal for <?php echo htmlspecialchars($record['day_name']); ?> (standing)</header>
                <?php if ($ctx['standing_lines']): ?>
                <table>
                    <thead><tr><th>Product</th><th class="num">Qty</th></tr></thead>
                    <tbody>
                    <?php foreach ($ctx['standing_lines'] as $line): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($line['product_name']); ?></td>
                            <td class="num"><?php echo (int)$line['standing_qty']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th>Total units</th><th class="num"><?php echo (int)$ctx['standing_units']; ?></th></tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <p class="cr-empty" style="padding:12px;">No standing demand for this weekday.</p>
                <?php endif; ?>
            </div>

            <div class="cr-compare-panel cr-panel-daily">
                <header>Dated daily order</header>
                <?php if ($ctx['daily_order_id']): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="num">Ordered</th>
                            <th class="num">Delivered</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ctx['daily_lines'] as $line): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($line['product_name']); ?></td>
                            <td class="num"><?php echo (int)$line['daily_qty']; ?></td>
                            <td class="num">
                                <?php
                                if ($line['delivered_quantity'] !== null && $line['delivered_quantity'] !== '') {
                                    echo (int)$line['delivered_quantity'];
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total / status</th>
                            <td class="num" colspan="2">
                                <?php echo (int)$ctx['daily_units']; ?> units
                                · <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$ctx['status']))); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php if (!empty($ctx['order_notes'])): ?>
                <p style="padding:8px 12px;margin:0;font-size:0.85rem;color:#4a5568;">
                    <strong>Order notes:</strong> <?php echo htmlspecialchars($ctx['order_notes']); ?>
                </p>
                <?php endif; ?>
                <?php else: ?>
                <p class="cr-empty" style="padding:12px;">No daily order for this date.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($ctx['diff_lines']): ?>
        <h3 style="margin:16px 0 8px;font-size:0.92rem;color:#4a5568;">Differences from normal</h3>
        <table class="cr-history-table cr-diff-table">
            <thead>
                <tr><th>Product</th><th class="num">Standing</th><th class="num">Daily</th><th>Change</th></tr>
            </thead>
            <tbody>
            <?php foreach ($ctx['diff_lines'] as $diff): ?>
                <tr>
                    <td><?php echo htmlspecialchars($diff['product_name']); ?></td>
                    <td class="num"><?php echo $diff['standing_qty'] === null ? '—' : (int)$diff['standing_qty']; ?></td>
                    <td class="num"><?php echo $diff['daily_qty'] === null ? '—' : (int)$diff['daily_qty']; ?></td>
                    <td class="diff">
                        <?php
                        if ($diff['kind'] === 'missing_on_daily') {
                            echo 'Expected on standing, missing on daily';
                        } elseif ($diff['kind'] === 'daily_only') {
                            echo 'Daily only (not on standing)';
                        } else {
                            echo 'Quantity changed';
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="cr-route-row" style="margin-top:16px;">
            <div class="cr-route-box">
                <h3>Recurring route (standing)</h3>
                <?php if (!empty($ctx['standing_route']['driver_name'])): ?>
                    <div><strong><?php echo htmlspecialchars($ctx['standing_route']['driver_name']); ?></strong></div>
                    <?php if (!empty($ctx['standing_route']['route_order'])): ?>
                    <div style="font-size:0.85rem;color:#4a5568;">Stop #<?php echo (int)$ctx['standing_route']['route_order']; ?> on <?php echo htmlspecialchars($record['day_name']); ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cr-empty" style="padding:0;">No recurring driver for this weekday.</div>
                <?php endif; ?>
            </div>
            <div class="cr-route-box">
                <h3>Dated assignment (<?php echo date('M j', strtotime($selectedDate)); ?>)</h3>
                <?php if (!empty($ctx['dated_route']['driver_name'])): ?>
                    <div><strong><?php echo htmlspecialchars($ctx['dated_route']['driver_name']); ?></strong></div>
                    <?php if (!empty($ctx['dated_route']['route_order'])): ?>
                    <div style="font-size:0.85rem;color:#4a5568;">Stop #<?php echo (int)$ctx['dated_route']['route_order']; ?></div>
                    <?php endif; ?>
                    <?php if (!empty($ctx['dated_route']['assignment_status'])): ?>
                    <div style="font-size:0.85rem;color:#4a5568;">Status: <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ctx['dated_route']['assignment_status']))); ?></div>
                    <?php endif; ?>
                    <?php
                    $standingDriver = $ctx['standing_route']['driver_id'] ?? null;
                    $datedDriver = $ctx['dated_route']['driver_id'] ?? null;
                    if ($standingDriver && $datedDriver && (int)$standingDriver !== (int)$datedDriver):
                    ?>
                    <div style="font-size:0.82rem;color:#c05621;margin-top:4px;">Differs from recurring route</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="cr-empty" style="padding:0;">No driver assigned for this date.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cr-actions">
            <a class="cr-btn cr-btn-primary"
               href="daily_orders.php?date=<?php echo htmlspecialchars($selectedDate); ?>&amp;customer=<?php echo $customerNameEnc; ?>&amp;view=edit">
                View in Daily Orders
            </a>
            <?php if ($ctx['daily_order_id']): ?>
            <a class="cr-btn cr-btn-secondary"
               href="billing_center.php?panel=invoices&amp;customer_id=<?php echo $customerId; ?>&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($selectedDate); ?>&amp;end_date=<?php echo htmlspecialchars($selectedDate); ?>">
                View in Billing Center
            </a>
            <a class="cr-btn cr-btn-secondary"
               href="customer_invoice.php?daily_order_id=<?php echo (int)$ctx['daily_order_id']; ?>"
               target="_blank" rel="noopener">
                <?php echo htmlspecialchars(bakery_t('billing.view_invoice')); ?>
            </a>
            <?php endif; ?>
            <a class="cr-btn cr-btn-ghost"
               href="driver_assignment.php?date=<?php echo htmlspecialchars($selectedDate); ?>">
                Driver assignment
            </a>
            <?php if (!empty($cust['zone'])): ?>
            <a class="cr-btn cr-btn-ghost"
               href="daily_orders.php?date=<?php echo htmlspecialchars($selectedDate); ?>&amp;zone=<?php echo $zoneEnc; ?>">
                Daily orders in zone
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($customerId > 0 && bakery_operational_events_ready($db)): ?>
    <?php
        $timelinePreview = bakery_operational_timeline_fetch($db, [
            'customer_id' => $customerId,
            'since' => date('Y-m-d H:i:s', strtotime('-60 days')),
            'limit' => 8,
        ]);
    ?>
    <div class="cr-card">
        <h2>Operational timeline</h2>
        <?php if ($timelinePreview): ?>
        <ul style="list-style:none;margin:0;padding:0;">
            <?php foreach ($timelinePreview as $tlEntry): ?>
            <li style="padding:10px 0;border-bottom:1px solid #e2e8f0;">
                <strong><?= htmlspecialchars(bakery_operational_format_time($tlEntry['occurred_at'])) ?></strong>
                <?= htmlspecialchars(date('M j', strtotime($tlEntry['occurred_at']))) ?>
                — <?= htmlspecialchars($tlEntry['summary']) ?>
                <?php if (!empty($tlEntry['detail_lines'])): ?>
                <div style="font-size:0.85rem;color:#718096;margin-top:4px;">
                    <?= htmlspecialchars(implode(' · ', $tlEntry['detail_lines'])) ?>
                </div>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="cr-empty">No timeline events recorded for this customer yet.</p>
        <?php endif; ?>
        <div class="cr-actions" style="margin-top:12px;">
            <a class="cr-btn cr-btn-ghost"
               href="operational_timeline.php?context=customer&amp;customer_id=<?= $customerId ?>">
                Full customer timeline
            </a>
        </div>
    </div>
    <?php endif; ?>

    <div class="cr-card">
        <h2>Recent operational history</h2>
        <?php if ($record['recent_orders']): ?>
        <table class="cr-history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="num">Ordered</th>
                    <th class="num">Delivered</th>
                    <th class="num">Amount</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($record['recent_orders'] as $order): ?>
                <tr>
                    <td>
                        <a href="<?php echo htmlspecialchars(bakery_customer_record_url($customerId, $order['order_date'])); ?>">
                            <?php echo date('M j, Y', strtotime($order['order_date'])); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)$order['status']))); ?></td>
                    <td class="num"><?php echo (int)$order['ordered_units']; ?></td>
                    <td class="num">
                        <?php if ($order['delivery_confirmed_at']): ?>
                            <span class="<?php echo $order['variance'] < 0 ? 'cr-variance-neg' : ($order['variance'] > 0 ? 'cr-variance-pos' : ''); ?>">
                                <?php echo (int)$order['delivered_units']; ?>
                                <?php if ($order['variance'] !== 0): ?>
                                    (<?php echo $order['variance'] > 0 ? '+' : ''; ?><?php echo (int)$order['variance']; ?>)
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td class="num">$<?php echo number_format($order['display_amount'], 2); ?></td>
                    <td>
                        <a class="cr-btn cr-btn-ghost" style="padding:4px 8px;font-size:0.78rem;"
                           href="daily_orders.php?date=<?php echo htmlspecialchars($order['order_date']); ?>&amp;customer=<?php echo $customerNameEnc; ?>">
                            Daily order
                        </a>
                        <?php if ($order['delivery_confirmed_at']): ?>
                        <a class="cr-btn cr-btn-ghost" style="padding:4px 8px;font-size:0.78rem;"
                           href="billing_center.php?panel=invoices&amp;customer_id=<?php echo $customerId; ?>&amp;range=custom&amp;start_date=<?php echo htmlspecialchars($order['order_date']); ?>&amp;end_date=<?php echo htmlspecialchars($order['order_date']); ?>">
                            Billing
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="cr-actions">
            <a class="cr-btn cr-btn-ghost"
               href="billing_center.php?panel=customer&amp;customer_id=<?php echo $customerId; ?>&amp;start_date=<?php echo urlencode(date('Y-m-01')); ?>&amp;end_date=<?php echo urlencode(date('Y-m-d')); ?>">
                Customer billing account
            </a>
            <a class="cr-btn cr-btn-ghost"
               href="billing_center.php?panel=invoices&amp;customer_id=<?php echo $customerId; ?>&amp;range=month">
                All invoices this month
            </a>
            <a class="cr-btn cr-btn-ghost" href="orders.php">Orders summary grid</a>
        </div>
        <?php else: ?>
        <p class="cr-empty">No daily orders on record for this customer yet.</p>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<script>
(function () {
    var filter = document.getElementById('customer_filter');
    var select = document.getElementById('customer_id');
    if (!filter || !select) return;

    var options = Array.prototype.slice.call(select.options);

    function applyFilter() {
        var q = filter.value.trim().toLowerCase();
        var firstVisible = null;
        options.forEach(function (opt, index) {
            if (index === 0) {
                opt.hidden = false;
                return;
            }
            var blob = (opt.getAttribute('data-search') || opt.textContent || '').toLowerCase();
            var match = q === '' || blob.indexOf(q) !== -1;
            opt.hidden = !match;
            if (match && !firstVisible) firstVisible = opt;
        });
        if (q !== '' && firstVisible && (select.selectedOptions[0] && select.selectedOptions[0].hidden)) {
            select.value = firstVisible.value;
        }
    }

    filter.addEventListener('input', applyFilter);
    select.addEventListener('change', function () {
        if (select.value) {
            document.getElementById('crToolbar').submit();
        }
    });
    filter.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        applyFilter();
        if (select.value) {
            document.getElementById('crToolbar').submit();
        }
    });
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
