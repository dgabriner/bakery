<?php
/**
 * Operational Timeline — unified view of what happened across the bakery day.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/operational_timeline.php';

bakery_ensure_operational_events_schema($db);

$page_title = 'Operational Timeline';

$context = trim((string)($_GET['context'] ?? 'day'));
if (!in_array($context, ['day', 'customer', 'order'], true)) {
    $context = 'day';
}

$selectedDate = trim((string)($_GET['date'] ?? ''));
if ($selectedDate === '') {
    $selectedDate = date('Y-m-d');
}
$dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $selectedDate) {
    $selectedDate = date('Y-m-d');
}

$customerId = max(0, (int)($_GET['customer_id'] ?? 0));
$dailyOrderId = max(0, (int)($_GET['daily_order_id'] ?? 0));
$driverId = max(0, (int)($_GET['driver_id'] ?? 0));
$actorUserId = max(0, (int)($_GET['user_id'] ?? 0));
$category = trim((string)($_GET['category'] ?? ''));

$filters = ['limit' => 150];
if ($context === 'day' || $context === 'order') {
    $filters['operational_date'] = $selectedDate;
}
if ($context === 'customer' && $customerId > 0) {
    $filters['customer_id'] = $customerId;
    unset($filters['operational_date']);
    $filters['since'] = date('Y-m-d H:i:s', strtotime('-90 days'));
} elseif ($customerId > 0) {
    $filters['customer_id'] = $customerId;
}
if ($context === 'order' && $dailyOrderId > 0) {
    $filters['daily_order_id'] = $dailyOrderId;
    unset($filters['operational_date']);
}
if ($driverId > 0) {
    $filters['driver_id'] = $driverId;
}
if ($actorUserId > 0) {
    $filters['actor_user_id'] = $actorUserId;
}
if ($category !== '') {
    $filters['category'] = $category;
}

$entries = bakery_operational_timeline_fetch($db, $filters);

$contextTitle = 'Daily timeline';
$contextSubtitle = date('l, F j, Y', strtotime($selectedDate));
if ($context === 'customer' && $customerId > 0) {
    $custStmt = $db->prepare('SELECT name FROM customers WHERE id = ?');
    $custStmt->execute([$customerId]);
    $custName = (string)($custStmt->fetchColumn() ?: 'Customer');
    $contextTitle = 'Customer timeline';
    $contextSubtitle = $custName . ' — recent activity';
} elseif ($context === 'order' && $dailyOrderId > 0) {
    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    if ($ctx) {
        $contextTitle = 'Order / delivery timeline';
        $contextSubtitle = $ctx['customer_name'] . ' · ' . date('M j, Y', strtotime($ctx['order_date']));
        if ($customerId <= 0) {
            $customerId = (int)$ctx['customer_id'];
        }
    }
}

$drivers = $db->query('SELECT id, name FROM drivers WHERE archived = 0 OR archived IS NULL ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query(
    'SELECT u.id, u.display_name, r.slug AS role_slug
     FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 ORDER BY u.display_name'
)->fetchAll(PDO::FETCH_ASSOC);
$customers = $db->query('SELECT id, name FROM customers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$categories = bakery_operational_event_categories();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>

<style>
.ot-wrap { max-width: 960px; margin: 0 auto; padding: 20px; }
.ot-header {
    background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%);
    color: #fff; border-radius: 14px; padding: 22px 26px; margin-bottom: 20px;
}
.ot-header h1 { margin: 0 0 6px; font-size: 1.65rem; }
.ot-header p { margin: 0; opacity: 0.92; }
.ot-filters {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 12px; background: #fff; border-radius: 12px; padding: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 20px;
}
.ot-filters label { display: block; font-size: 0.78rem; font-weight: 600; color: #555; margin-bottom: 4px; }
.ot-filters select, .ot-filters input[type=date] {
    width: 100%; padding: 8px 10px; border: 1px solid #cbd5e0; border-radius: 8px;
}
.ot-context-tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.ot-context-tabs a {
    padding: 8px 14px; border-radius: 999px; text-decoration: none; font-size: 0.9rem;
    background: #edf2f7; color: #2d3748;
}
.ot-context-tabs a.is-active { background: #2b6cb0; color: #fff; }
.ot-timeline { list-style: none; margin: 0; padding: 0; }
.ot-entry {
    display: grid; grid-template-columns: 72px 1fr; gap: 16px; padding: 16px 0;
    border-bottom: 1px solid #e2e8f0;
}
.ot-entry:last-child { border-bottom: none; }
.ot-time { font-size: 0.85rem; color: #718096; font-weight: 600; padding-top: 2px; }
.ot-summary { font-size: 1rem; color: #1a202c; margin: 0 0 6px; line-height: 1.4; }
.ot-meta { font-size: 0.82rem; color: #718096; margin: 0 0 4px; }
.ot-details { margin: 8px 0 0; padding: 0; list-style: none; }
.ot-details li {
    font-size: 0.88rem; color: #4a5568; padding: 2px 0 2px 14px;
    position: relative;
}
.ot-details li::before {
    content: '·'; position: absolute; left: 0; color: #a0aec0;
}
.ot-links { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; }
.ot-links a {
    font-size: 0.78rem; padding: 3px 10px; border-radius: 999px;
    background: #ebf8ff; color: #2b6cb0; text-decoration: none;
}
.ot-badge {
    display: inline-block; font-size: 0.7rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: 0.03em; padding: 2px 8px; border-radius: 4px; margin-left: 8px;
    vertical-align: middle;
}
.ot-badge--delivery { background: #c6f6d5; color: #22543d; }
.ot-badge--demand { background: #bee3f8; color: #2a4365; }
.ot-badge--production { background: #feebc8; color: #7b341e; }
.ot-badge--inventory { background: #e9d8fd; color: #44337a; }
.ot-badge--billing { background: #fed7d7; color: #742a2a; }
.ot-badge--closeout { background: #e2e8f0; color: #2d3748; }
.ot-empty { text-align: center; padding: 48px 20px; color: #718096; background: #fff; border-radius: 12px; }
.ot-panel { background: #fff; border-radius: 12px; padding: 8px 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
</style>

<div class="ot-wrap">
    <div class="ot-header">
        <h1><?= htmlspecialchars($contextTitle) ?></h1>
        <p><?= htmlspecialchars($contextSubtitle) ?></p>
    </div>

    <div class="ot-context-tabs">
        <a href="?context=day&date=<?= urlencode($selectedDate) ?>" class="<?= $context === 'day' ? 'is-active' : '' ?>">Daily</a>
        <a href="?context=customer&customer_id=<?= $customerId ?: '' ?>" class="<?= $context === 'customer' ? 'is-active' : '' ?>">Customer</a>
        <?php if ($dailyOrderId > 0): ?>
        <a href="?context=order&daily_order_id=<?= $dailyOrderId ?>&date=<?= urlencode($selectedDate) ?>" class="<?= $context === 'order' ? 'is-active' : '' ?>">This order</a>
        <?php endif; ?>
    </div>

    <form method="get" class="ot-filters">
        <input type="hidden" name="context" value="<?= htmlspecialchars($context) ?>">
        <?php if ($dailyOrderId > 0): ?>
        <input type="hidden" name="daily_order_id" value="<?= $dailyOrderId ?>">
        <?php endif; ?>
        <?php if ($context !== 'customer'): ?>
        <div>
            <label for="ot-date">Date</label>
            <input type="date" id="ot-date" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
        </div>
        <?php endif; ?>
        <?php if ($context === 'customer' || $context === 'day'): ?>
        <div>
            <label for="ot-customer">Customer</label>
            <select id="ot-customer" name="customer_id">
                <option value="">All</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="ot-driver">Driver</label>
            <select id="ot-driver" name="driver_id">
                <option value="">All</option>
                <?php foreach ($drivers as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $driverId === (int)$d['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="ot-user">User</label>
            <select id="ot-user" name="user_id">
                <option value="">All</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $actorUserId === (int)$u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['role_slug']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="ot-category">Category</label>
            <select id="ot-category" name="category">
                <option value="">All</option>
                <?php foreach ($categories as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $category === $key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="align-self: end;">
            <button type="submit" class="btn btn-primary" style="width:100%;padding:9px 12px;">Apply filters</button>
        </div>
    </form>

    <div class="ot-panel">
        <?php if (empty($entries)): ?>
        <div class="ot-empty">No operational events match these filters yet.</div>
        <?php else: ?>
        <ul class="ot-timeline">
            <?php foreach ($entries as $entry): ?>
            <?php
                $cat = htmlspecialchars($entry['category'] ?? 'demand');
                $badgeClass = 'ot-badge--' . preg_replace('/[^a-z]/', '', $cat);
            ?>
            <li class="ot-entry">
                <div class="ot-time"><?= htmlspecialchars(bakery_operational_format_time($entry['occurred_at'])) ?></div>
                <div class="ot-body">
                    <p class="ot-summary">
                        <?= htmlspecialchars($entry['summary']) ?>
                        <span class="ot-badge <?= $badgeClass ?>"><?= htmlspecialchars($cat) ?></span>
                    </p>
                    <p class="ot-meta">
                        <?= htmlspecialchars(bakery_operational_format_datetime($entry['occurred_at'])) ?>
                        · <?= htmlspecialchars(bakery_operational_actor_label($entry)) ?>
                        <?php if (!empty($entry['driver_name']) && !empty($entry['actor_name']) && $entry['driver_name'] !== $entry['actor_name']): ?>
                        · Driver: <?= htmlspecialchars($entry['driver_name']) ?>
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($entry['detail_lines'])): ?>
                    <ul class="ot-details">
                        <?php foreach ($entry['detail_lines'] as $line): ?>
                        <li><?= htmlspecialchars($line) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                    <?php if (!empty($entry['links'])): ?>
                    <div class="ot-links">
                        <?php if (!empty($entry['links']['customer'])): ?>
                        <a href="<?= htmlspecialchars($entry['links']['customer']) ?>">Customer</a>
                        <?php endif; ?>
                        <?php if (!empty($entry['links']['order'])): ?>
                        <a href="<?= htmlspecialchars($entry['links']['order']) ?>">This order</a>
                        <?php endif; ?>
                        <?php if (!empty($entry['links']['daily_orders'])): ?>
                        <a href="<?= htmlspecialchars($entry['links']['daily_orders']) ?>">Daily orders</a>
                        <?php endif; ?>
                        <?php if (!empty($entry['links']['production'])): ?>
                        <a href="<?= htmlspecialchars($entry['links']['production']) ?>">Production</a>
                        <?php endif; ?>
                        <?php if (!empty($entry['links']['invoice'])): ?>
                        <a href="<?= htmlspecialchars($entry['links']['invoice']) ?>">Invoice center</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
