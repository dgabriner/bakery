<?php
/**
 * Operations dashboard — daily command center for bakery staff.
 *
 * Managers/admins: today's ops snapshot, quick links, 7-day order chart.
 * Drivers: reduced view with their assignments for today.
 * Bakers are routed directly to Daily Production and do not use this dashboard.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';

$user = bakery_current_user();
$isDriver = $user && $user['role_slug'] === 'driver';
$isBaker = $user && $user['role_slug'] === 'baker';
if ($isDriver) {
    header('Location: ' . BASE_URL . 'driver.php');
    exit;
}
$page_title = $isBaker ? 'Baker Dashboard' : 'Operations Dashboard';
$today = date('Y-m-d');
$selectedDate = bakery_dashboard_resolve_date();
// Baker workflow targets the next calendar day (Fri → Sat production)
if ($isBaker && !isset($_GET['date'])) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}
$dayNames = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekday = bakery_standing_day_from_date($selectedDate);
$dayLabel = $dayNames[$weekday] ?? date('l', strtotime($selectedDate));
$dateDisplay = date('l, F j, Y', strtotime($selectedDate));
$isToday = ($selectedDate === $today);

$dbError = null;
$snapshot = null;
$chartData = [];
$driverView = null;

try {
    if ($isBaker) {
        // Baker dashboard needs no ops metrics.
    } elseif ($isDriver) {
        $driverId = (int)($user['driver_id'] ?? 0);
        $driverView = bakery_dashboard_driver_view($db, $driverId, $selectedDate);
    } else {
        $snapshot = bakery_dashboard_ops_snapshot($db, $selectedDate);
        $chartData = bakery_dashboard_orders_by_day($db, $selectedDate, 7);
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
    if ($isBaker) {
        // no-op
    } elseif (!$isDriver) {
        $snapshot = [
            'date' => $selectedDate,
            'weekday' => $weekday,
            'daily_order_count' => 0,
            'customers_with_orders' => 0,
            'assignments_pending' => 0,
            'assignments_delivered' => 0,
            'standing_order_lines' => 0,
            'unassigned_orders' => 0,
        ];
        $chartData = bakery_dashboard_orders_by_day($db, $selectedDate, 7);
    } else {
        $driverView = ['assignments' => [], 'pending' => 0, 'delivered' => 0, 'total' => 0];
    }
}

$chartMax = 1;
foreach ($chartData as $bar) {
    if ($bar['count'] > $chartMax) {
        $chartMax = $bar['count'];
    }
}

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(BASE_URL); ?>css/dashboard.css">

<div class="ops-dashboard">
    <header class="ops-header">
        <div>
            <h1><?php echo $isBaker ? 'Baker Dashboard' : ($isDriver ? 'My Deliveries' : 'Operations Dashboard'); ?></h1>
            <p class="ops-date-line">
                <?php echo htmlspecialchars($dateDisplay); ?>
                <?php if (!$isToday): ?>
                    <span>(not today)</span>
                <?php endif; ?>
            </p>
        </div>
        <?php if (!$isBaker): ?>
        <nav class="ops-date-nav" aria-label="Date navigation">
            <a href="?date=<?php echo urlencode(date('Y-m-d', strtotime($selectedDate . ' -1 day'))); ?>">← Prev</a>
            <?php if (!$isToday): ?>
                <a href="?" class="ops-today-link">Today</a>
            <?php endif; ?>
            <a href="?date=<?php echo urlencode(date('Y-m-d', strtotime($selectedDate . ' +1 day'))); ?>">Next →</a>
        </nav>
        <?php endif; ?>
    </header>

    <?php if ($dbError && !$isBaker): ?>
        <div class="ops-alert ops-alert-warning">
            Some dashboard data could not be loaded. Metrics may show zero.
        </div>
    <?php endif; ?>

    <?php if ($isBaker): ?>
        <section class="ops-section">
            <h2>Quick Links</h2>
            <div class="ops-quick-links">
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>production.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon">⚙️</span>
                    <span>
                        <div class="ops-quick-link-title">Production</div>
                        <div class="ops-quick-link-desc"><?php echo htmlspecialchars($dayLabel); ?> bake quantities</div>
                    </span>
                </a>
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>pack_list.php?day=<?php echo (int)$weekday; ?>">
                    <span class="ops-quick-link-icon">📦</span>
                    <span>
                        <div class="ops-quick-link-title">Pack List</div>
                        <div class="ops-quick-link-desc"><?php echo htmlspecialchars($dayLabel); ?> packing</div>
                    </span>
                </a>
            </div>
        </section>

    <?php elseif ($isDriver): ?>
        <?php
        $driverId = (int)($user['driver_id'] ?? 0);
        if ($driverId <= 0):
        ?>
            <div class="ops-alert ops-alert-warning">
                Your account is not linked to a driver profile. Contact a manager to assign deliveries.
            </div>
        <?php else: ?>
            <div class="ops-cards">
                <div class="ops-card">
                    <div class="ops-card-value"><?php echo (int)$driverView['total']; ?></div>
                    <div class="ops-card-label">Stops Today</div>
                </div>
                <div class="ops-card accent-pending">
                    <div class="ops-card-value"><?php echo (int)$driverView['pending']; ?></div>
                    <div class="ops-card-label">Pending</div>
                </div>
                <div class="ops-card accent-delivered">
                    <div class="ops-card-value"><?php echo (int)$driverView['delivered']; ?></div>
                    <div class="ops-card-label">Delivered</div>
                </div>
            </div>

            <section class="ops-section">
                <h2>Quick Links</h2>
                <div class="ops-quick-links">
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>driver.php?driver_id=<?php echo $driverId; ?>&amp;date=<?php echo urlencode($selectedDate); ?>">
                        <span class="ops-quick-link-icon">🚚</span>
                        <span>
                            <div class="ops-quick-link-title">Driver Route</div>
                            <div class="ops-quick-link-desc">Map and delivery details</div>
                        </span>
                    </a>
                    <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>driver_list.php">
                        <span class="ops-quick-link-icon">📋</span>
                        <span>
                            <div class="ops-quick-link-title">Driver List</div>
                            <div class="ops-quick-link-desc">All driver stops</div>
                        </span>
                    </a>
                </div>
            </section>

            <section class="ops-section">
                <h2>Today's Assignments</h2>
                <?php if (empty($driverView['assignments'])): ?>
                    <div class="ops-empty">No deliveries assigned for this date.</div>
                <?php else: ?>
                    <div class="ops-assignments">
                        <?php foreach ($driverView['assignments'] as $assignment): ?>
                            <?php
                            $status = $assignment['delivery_status'];
                            $statusClass = 'ops-status-' . preg_replace('/[^a-z_]/', '', $status);
                            ?>
                            <div class="ops-assignment-row">
                                <span class="ops-status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?>
                                </span>
                                <span class="ops-assignment-customer">
                                    #<?php echo (int)$assignment['route_order']; ?>
                                    <?php echo htmlspecialchars($assignment['customer_name']); ?>
                                </span>
                                <span class="ops-assignment-meta">
                                    <?php echo htmlspecialchars($assignment['zone'] ?: 'No Zone'); ?>
                                    <?php if (!empty($assignment['scheduled_delivery_time'])): ?>
                                        · <?php echo htmlspecialchars(substr($assignment['scheduled_delivery_time'], 0, 5)); ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    <?php else: ?>
        <div class="ops-cards">
            <div class="ops-card">
                <div class="ops-card-value"><?php echo number_format($snapshot['daily_order_count']); ?></div>
                <div class="ops-card-label">Daily Orders</div>
                <div class="ops-card-sub"><?php echo htmlspecialchars($dayLabel); ?> schedule</div>
            </div>
            <div class="ops-card accent-pending">
                <div class="ops-card-value"><?php echo number_format($snapshot['assignments_pending']); ?></div>
                <div class="ops-card-label">Pending Deliveries</div>
                <?php if ($snapshot['unassigned_orders'] > 0): ?>
                    <div class="ops-card-sub"><?php echo (int)$snapshot['unassigned_orders']; ?> unassigned</div>
                <?php endif; ?>
            </div>
            <div class="ops-card accent-delivered">
                <div class="ops-card-value"><?php echo number_format($snapshot['assignments_delivered']); ?></div>
                <div class="ops-card-label">Delivered</div>
            </div>
            <div class="ops-card accent-standing">
                <div class="ops-card-value"><?php echo number_format($snapshot['standing_order_lines']); ?></div>
                <div class="ops-card-label">Standing Lines</div>
                <div class="ops-card-sub">For <?php echo htmlspecialchars($dayLabel); ?></div>
            </div>
            <div class="ops-card accent-customers">
                <div class="ops-card-value"><?php echo number_format($snapshot['customers_with_orders']); ?></div>
                <div class="ops-card-label">Customers</div>
                <div class="ops-card-sub">With orders today</div>
            </div>
        </div>

        <section class="ops-section">
            <h2>Quick Links</h2>
            <div class="ops-quick-links">
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_orders.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon">📝</span>
                    <span>
                        <div class="ops-quick-link-title">Daily Orders</div>
                        <div class="ops-quick-link-desc">Generate and edit orders</div>
                    </span>
                </a>
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>pack_list.php?day=<?php echo (int)$weekday; ?>">
                    <span class="ops-quick-link-icon">📦</span>
                    <span>
                        <div class="ops-quick-link-title">Pack List</div>
                        <div class="ops-quick-link-desc"><?php echo htmlspecialchars($dayLabel); ?> packing</div>
                    </span>
                </a>
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>driver_assignment.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon">🚚</span>
                    <span>
                        <div class="ops-quick-link-title">Driver Assignment</div>
                        <div class="ops-quick-link-desc">Assign routes for today</div>
                    </span>
                </a>
                <a class="ops-quick-link" href="<?php echo htmlspecialchars(BASE_URL); ?>production.php?date=<?php echo urlencode($selectedDate); ?>">
                    <span class="ops-quick-link-icon">⚙️</span>
                    <span>
                        <div class="ops-quick-link-title">Production</div>
                        <div class="ops-quick-link-desc">Bake quantities for today</div>
                    </span>
                </a>
            </div>
        </section>

        <?php if (!empty($chartData)): ?>
        <section class="ops-section">
            <h2>Orders — Last 7 Days</h2>
            <div class="ops-chart">
                <div class="ops-chart-bars" role="img" aria-label="Bar chart of daily order counts for the last seven days">
                    <?php foreach ($chartData as $bar): ?>
                        <?php
                        $heightPct = $chartMax > 0 ? max(2, round(($bar['count'] / $chartMax) * 100)) : 2;
                        ?>
                        <div class="ops-chart-bar-wrap" title="<?php echo htmlspecialchars($bar['date'] . ': ' . $bar['count'] . ' orders'); ?>">
                            <span class="ops-chart-count"><?php echo $bar['count'] > 0 ? (int)$bar['count'] : ''; ?></span>
                            <div class="ops-chart-bar<?php echo $bar['is_today'] ? ' is-today' : ''; ?>" style="height: <?php echo (int)$heightPct; ?>%;"></div>
                            <span class="ops-chart-label"><?php echo htmlspecialchars($bar['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
