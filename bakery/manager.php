<?php
/**
 * Manager Mode — one dated workspace for overseeing orders, routes, drivers,
 * and the operational handoff that follows delivery.
 */
define('ACCESS_ALLOWED', true);

require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/dashboard_command_center.php';
require_once 'includes/daily_run.php';
require_once 'includes/operational_exceptions.php';
require_once 'includes/manager_mode.php';
require_once 'includes/exception_workshop.php';
require_once 'includes/exception_desk.php';
require_once 'includes/demand_review.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/staging_live_approval.php';

$today = date('Y-m-d');
$selectedDate = trim((string)($_GET['date'] ?? $today));
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $selectedDate);
if (!$dateObject || $dateObject->format('Y-m-d') !== $selectedDate) {
    $selectedDate = $today;
    $dateObject = new DateTimeImmutable('today');
}

$previousDate = $dateObject->modify('-1 day')->format('Y-m-d');
$nextDate = $dateObject->modify('+1 day')->format('Y-m-d');
$dateDisplay = $dateObject->format('l, F j, Y');
$page_title = bakery_t('page.manager');

$commandCenter = null;
$dailyRun = null;
$managerError = null;
$stageByKey = [];
$exceptions = [];
$driverRows = [];
$managerNotice = null;
$assignableManagers = [];
$routePlan = [];
$handoffBoard = [];
$recoveryCases = [];
$untriagedFailedStops = [];
$productionRows = [];
$productionSummary = [
    'demand' => 0,
    'target' => 0,
    'produced' => 0,
    'remaining' => 0,
    'planned_products' => 0,
    'has_daily' => false,
    'plan_available' => false,
    'inventory_available' => false,
];
$packingSummary = [
    'required_lines' => 0,
    'checked_lines' => 0,
    'required_units' => 0,
    'checked_units' => 0,
    'percent' => 0,
    'tracking_available' => false,
    'last_checked_at' => null,
    'last_checked_by' => '',
];
$packingProducts = [];
$bakerRows = [];
$bakerAuditAvailable = false;
$bakerActivityAvailable = false;
$workflowEvents = [];

try {
    $commandCenter = bakery_dashboard_command_center($db, $selectedDate);
    foreach ($commandCenter['stages'] as $stage) {
        $stageByKey[(string)($stage['key'] ?? '')] = $stage;
    }
    $exceptions = bakery_ops_enrich_exceptions($commandCenter['exceptions'] ?? [], $selectedDate, 'manager');
    $dailyRun = bakery_daily_run_build($db, $selectedDate);
    $exceptions = bakery_manager_enrich_exception_work($db, $exceptions, $selectedDate);
    $assignableManagers = bakery_manager_assignable_users($db);
    $routePlan = bakery_manager_route_plan($db, $selectedDate);
    $handoffBoard = bakery_manager_handoff_board($db, $selectedDate, $commandCenter, $dailyRun);
    $recoveryCases = bakery_delivery_recovery_cases_for_date($db, $selectedDate);
    $untriagedFailedStops = bakery_delivery_recovery_untriaged_failed_stops($db, $selectedDate);
} catch (Throwable $e) {
    error_log('manager workspace: ' . $e->getMessage());
    $managerError = bakery_dashboard_safe_error_message($e);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $mutation = (string)($_POST['manager_mutation'] ?? '');
        if ($mutation === 'approve_live') {
            if (strtolower(trim((string)($_POST['confirm_phrase'] ?? ''))) !== 'confirm') {
                throw new RuntimeException('Type confirm to approve this staging release.');
            }
            bakery_staging_live_approval_submit((string)($_POST['release_id'] ?? ''), (string)($_POST['git_commit'] ?? ''));
            $managerNotice = 'Staging approved for live. Live has not been changed.';
        } elseif ($mutation === 'exception_work') {
            $workKey = (string)($_POST['work_key'] ?? '');
            $matched = null;
            foreach ($exceptions as $exception) {
                if (hash_equals((string)($exception['work_key'] ?? ''), $workKey)) {
                    $matched = $exception;
                    break;
                }
            }
            if ($matched === null) {
                throw new RuntimeException('That exception is no longer in the current operating-day queue.');
            }
            bakery_manager_exception_save($db, $matched, $selectedDate, $_POST);
            $managerNotice = !empty($_POST['complete']) ? 'Exception work marked complete.' : 'Exception work saved.';
        } elseif (function_exists('bakery_exception_workshop_handle_post')
            && bakery_exception_workshop_handle_post($db, $selectedDate, $exceptions, $_POST)) {
            // Workshop mutations redirect on success.
        } elseif ($mutation === 'recovery_report') {
            bakery_delivery_recovery_report_failure($db, (int)($_POST['assignment_id'] ?? 0), $_POST);
            $managerNotice = 'Failed stop added to the recovery board.';
        } elseif ($mutation === 'recovery_action') {
            bakery_delivery_recovery_apply($db, (int)($_POST['case_id'] ?? 0), (string)($_POST['recovery_action'] ?? ''), $_POST);
            $managerNotice = 'Delivery recovery updated.';
        }
        if ($managerNotice !== null) {
            header('Location: ' . BASE_URL . 'manager.php?date=' . rawurlencode($selectedDate) . '&notice=' . rawurlencode($managerNotice));
            exit;
        }
    } catch (Throwable $e) {
        error_log('manager mutation: ' . $e->getMessage());
        $managerError = $e->getMessage();
    }
}

if (!empty($_GET['notice'])) {
    $managerNotice = substr(trim((string)$_GET['notice']), 0, 160);
} elseif (!empty($_GET['msg'])) {
    $managerNotice = substr(trim((string)$_GET['msg']), 0, 160);
}

$metric = static function (string $stageKey, string $metricKey, $fallback = null) use ($stageByKey) {
    $value = $stageByKey[$stageKey]['metrics'][$metricKey]['value'] ?? $fallback;
    return $value === null ? $fallback : $value;
};

$dailyOrders = (int)$metric('demand', 'daily_orders', 0);
$missingDaily = (int)$metric('demand', 'missing_daily', 0);
$unassigned = (int)$metric('delivery', 'unassigned', 0);
$pending = (int)$metric('delivery', 'pending', 0);
$inTransit = (int)$metric('delivery', 'in_transit', 0);
$delivered = (int)$metric('delivery', 'delivered', 0);
$failed = (int)$metric('delivery', 'failed', 0);
$routesOpen = $metric('load', 'routes_open', null);
$assignedStops = max(0, $dailyOrders - $unassigned);

try {
    $drivers = function_exists('bakery_get_drivers') ? bakery_get_drivers($db, false) : [];
    $assignmentCounts = [];
    if (table_exists($db, 'daily_order_assignments')) {
        $assignmentStmt = $db->prepare("\n            SELECT driver_id,\n                   COUNT(*) AS stop_count,\n                   COALESCE(SUM(CASE WHEN COALESCE(delivery_status, 'pending') = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,\n                   COALESCE(SUM(CASE WHEN delivery_status = 'in_transit' THEN 1 ELSE 0 END), 0) AS in_transit_count,\n                   COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered_count,\n                   COALESCE(SUM(CASE WHEN delivery_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_count\n            FROM daily_order_assignments\n            WHERE delivery_date = ?\n            GROUP BY driver_id\n        ");
        $assignmentStmt->execute([$selectedDate]);
        foreach ($assignmentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignmentCounts[(int)$row['driver_id']] = $row;
        }
    }

    foreach ($drivers as $driver) {
        $driverId = (int)($driver['id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $counts = $assignmentCounts[$driverId] ?? [];
        $driverRows[] = [
            'id' => $driverId,
            'name' => (string)($driver['name'] ?? ('Driver #' . $driverId)),
            'stops' => (int)($counts['stop_count'] ?? 0),
            'pending' => (int)($counts['pending_count'] ?? 0),
            'in_transit' => (int)($counts['in_transit_count'] ?? 0),
            'delivered' => (int)($counts['delivered_count'] ?? 0),
            'failed' => (int)($counts['failed_count'] ?? 0),
        ];
    }
} catch (Throwable $e) {
    error_log('manager driver board: ' . $e->getMessage());
    if ($managerError === null) {
        $managerError = 'Driver board unavailable. Other operating-day controls remain available.';
    }
}

$driversWithWork = count(array_filter($driverRows, static function (array $row): bool {
    return $row['stops'] > 0;
}));

/*
 * Production and packing stay read-only here. The manager board combines the
 * canonical planning, production, and packing records without introducing a
 * second operational state or a second way to post inventory.
 */
try {
    $demand = bakery_operating_demand_by_product($db, $selectedDate);
    $demandByProduct = array_map('intval', (array)($demand['by_product'] ?? []));
    $productionSummary['has_daily'] = !empty($demand['has_daily']);
    $productionSummary['demand'] = array_sum($demandByProduct);
    $productionSummary['plan_available'] = table_exists($db, 'production_plan_items');
    $productionSummary['inventory_available'] = table_exists($db, 'product_inventory_days');

    $plannedByProduct = [];
    if ($productionSummary['plan_available']) {
        $planStmt = $db->prepare(
            'SELECT product_id, planned_quantity FROM production_plan_items WHERE delivery_date = ?'
        );
        $planStmt->execute([$selectedDate]);
        foreach ($planStmt->fetchAll(PDO::FETCH_ASSOC) as $plan) {
            $plannedByProduct[(int)$plan['product_id']] = (int)$plan['planned_quantity'];
        }
    }

    $inventoryByProduct = [];
    if ($productionSummary['inventory_available']) {
        $inventoryStmt = $db->prepare(
            'SELECT product_id, produced_quantity, available_quantity, loaded_quantity
             FROM product_inventory_days WHERE delivery_date = ?'
        );
        $inventoryStmt->execute([$selectedDate]);
        foreach ($inventoryStmt->fetchAll(PDO::FETCH_ASSOC) as $inventory) {
            $inventoryByProduct[(int)$inventory['product_id']] = $inventory;
        }
    }

    $productIds = array_values(array_unique(array_merge(
        array_keys($demandByProduct),
        array_keys($plannedByProduct),
        array_keys($inventoryByProduct)
    )));
    if ($productIds) {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $productStmt = $db->prepare(
            "SELECT p.id, p.name, COALESCE(dt.name, '') AS dough_type_name
             FROM products p
             LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
             WHERE p.id IN ({$placeholders})"
        );
        $productStmt->execute($productIds);
        foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $productId = (int)$product['id'];
            $demandQuantity = (int)($demandByProduct[$productId] ?? 0);
            $hasPlan = array_key_exists($productId, $plannedByProduct);
            $plannedQuantity = $hasPlan ? (int)$plannedByProduct[$productId] : null;
            $targetQuantity = $hasPlan ? $plannedQuantity : $demandQuantity;
            $inventory = $inventoryByProduct[$productId] ?? [];
            $producedQuantity = (int)($inventory['produced_quantity'] ?? 0);
            $availableQuantity = (int)($inventory['available_quantity'] ?? 0);
            $loadedQuantity = (int)($inventory['loaded_quantity'] ?? 0);
            $remainingQuantity = max(0, $targetQuantity - $producedQuantity);
            $planGap = $hasPlan ? max(0, $demandQuantity - $plannedQuantity) : 0;

            $productionRows[] = [
                'product_id' => $productId,
                'product_name' => (string)$product['name'],
                'dough_type_name' => (string)$product['dough_type_name'],
                'demand_quantity' => $demandQuantity,
                'planned_quantity' => $plannedQuantity,
                'target_quantity' => $targetQuantity,
                'produced_quantity' => $producedQuantity,
                'available_quantity' => $availableQuantity,
                'loaded_quantity' => $loadedQuantity,
                'remaining_quantity' => $remainingQuantity,
                'plan_gap' => $planGap,
            ];
            $productionSummary['target'] += $targetQuantity;
            $productionSummary['produced'] += min($producedQuantity, $targetQuantity);
            $productionSummary['remaining'] += $remainingQuantity;
            if ($hasPlan) {
                $productionSummary['planned_products']++;
            }
        }
    }
    usort($productionRows, static function (array $a, array $b): int {
        if ($a['remaining_quantity'] !== $b['remaining_quantity']) {
            return $b['remaining_quantity'] <=> $a['remaining_quantity'];
        }
        return strcasecmp($a['product_name'], $b['product_name']);
    });
} catch (Throwable $e) {
    error_log('manager production handoff: ' . $e->getMessage());
    if ($managerError === null) {
        $managerError = 'Production handoff data is unavailable. Open Daily Production for the current bake sheet.';
    }
}

try {
    $packingLines = bakery_operating_demand_lines($db, $selectedDate);
    $packingLineMap = [];
    foreach ($packingLines as $line) {
        $customerId = (int)($line['customer_id'] ?? 0);
        $productId = (int)($line['product_id'] ?? 0);
        $quantity = (int)($line['quantity'] ?? 0);
        if ($customerId <= 0 || $productId <= 0 || $quantity <= 0) {
            continue;
        }
        $lineKey = "c{$customerId}_p{$productId}";
        $packingLineMap[$lineKey] = [
            'product_id' => $productId,
            'product_name' => (string)($line['product_name'] ?? ('Product #' . $productId)),
            'quantity' => $quantity,
        ];
    }

    $packingChecks = [];
    $packingSummary['tracking_available'] = table_exists($db, 'pack_progress');
    if ($packingSummary['tracking_available']) {
        $packStmt = $db->prepare(
            'SELECT pp.line_key, pp.checked_at, COALESCE(u.display_name, \'Packing staff\') AS checked_by
             FROM pack_progress pp
             LEFT JOIN users u ON u.id = pp.checked_by_user_id
             WHERE pp.pack_date = ?'
        );
        $packStmt->execute([$selectedDate]);
        foreach ($packStmt->fetchAll(PDO::FETCH_ASSOC) as $check) {
            $packingChecks[(string)$check['line_key']] = $check;
        }
    }

    foreach ($packingLineMap as $lineKey => $line) {
        $productId = $line['product_id'];
        if (!isset($packingProducts[$productId])) {
            $packingProducts[$productId] = [
                'product_name' => $line['product_name'],
                'required_lines' => 0,
                'checked_lines' => 0,
                'required_units' => 0,
                'checked_units' => 0,
            ];
        }
        $isChecked = isset($packingChecks[$lineKey]);
        $packingSummary['required_lines']++;
        $packingSummary['required_units'] += $line['quantity'];
        $packingProducts[$productId]['required_lines']++;
        $packingProducts[$productId]['required_units'] += $line['quantity'];
        if ($isChecked) {
            $packingSummary['checked_lines']++;
            $packingSummary['checked_units'] += $line['quantity'];
            $packingProducts[$productId]['checked_lines']++;
            $packingProducts[$productId]['checked_units'] += $line['quantity'];
            $checkedAt = (string)($packingChecks[$lineKey]['checked_at'] ?? '');
            if ($checkedAt !== '' && ($packingSummary['last_checked_at'] === null || $checkedAt > $packingSummary['last_checked_at'])) {
                $packingSummary['last_checked_at'] = $checkedAt;
                $packingSummary['last_checked_by'] = (string)($packingChecks[$lineKey]['checked_by'] ?? 'Packing staff');
            }
        }
    }
    $packingSummary['percent'] = $packingSummary['required_lines'] > 0
        ? (int)round(($packingSummary['checked_lines'] / $packingSummary['required_lines']) * 100)
        : 0;
    uasort($packingProducts, static function (array $a, array $b): int {
        $aOpen = $a['required_lines'] - $a['checked_lines'];
        $bOpen = $b['required_lines'] - $b['checked_lines'];
        if ($aOpen !== $bOpen) {
            return $bOpen <=> $aOpen;
        }
        return strcasecmp($a['product_name'], $b['product_name']);
    });
} catch (Throwable $e) {
    error_log('manager packing handoff: ' . $e->getMessage());
}

/* Managers can see baker workflow use without exposing the broader administrator-only login history. */
try {
    $bakerAuditAvailable = bakery_login_audit_ready($db);
    $bakerActivityAvailable = $bakerAuditAvailable && bakery_login_audit_activity_ready($db);
    if ($bakerAuditAvailable) {
        $bakerStmt = $db->prepare(
            "SELECT u.id, u.display_name,
                    MAX(CASE WHEN la.outcome = 'success' THEN la.login_at END) AS last_login_at,
                    MAX(CASE WHEN la.outcome = 'success' THEN la.last_seen_at END) AS last_seen_at,
                    MAX(CASE WHEN la.outcome = 'success' AND DATE(la.login_at) = ? THEN la.login_at END) AS date_login_at,
                    MAX(CASE WHEN la.outcome = 'success' AND la.logout_at IS NULL
                              AND la.last_seen_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                        THEN 1 ELSE 0 END) AS is_active
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN login_audit la ON la.user_id = u.id
             WHERE r.slug = 'baker' AND u.is_active = 1
             GROUP BY u.id, u.display_name
             ORDER BY u.display_name"
        );
        $bakerStmt->execute([$selectedDate]);
        foreach ($bakerStmt->fetchAll(PDO::FETCH_ASSOC) as $baker) {
            $bakerRows[(int)$baker['id']] = [
                'id' => (int)$baker['id'],
                'display_name' => (string)$baker['display_name'],
                'last_login_at' => $baker['last_login_at'],
                'last_seen_at' => $baker['last_seen_at'],
                'date_login_at' => $baker['date_login_at'],
                'is_active' => !empty($baker['is_active']),
                'last_opened_at' => null,
                'last_opened_label' => '',
            ];
        }

        if ($bakerActivityAvailable && $bakerRows) {
            $bakerIds = array_keys($bakerRows);
            $placeholders = implode(',', array_fill(0, count($bakerIds), '?'));
            $activityStmt = $db->prepare(
                "SELECT la.user_id, laa.occurred_at, laa.page_path, laa.page_title
                 FROM login_audit_activity laa
                 JOIN login_audit la ON la.id = laa.login_audit_id
                 JOIN (
                     SELECT la2.user_id, MAX(laa2.occurred_at) AS latest_at
                     FROM login_audit_activity laa2
                     JOIN login_audit la2 ON la2.id = laa2.login_audit_id
                     WHERE la2.user_id IN ({$placeholders})
                       AND (laa2.page_path LIKE '%production.php%' OR laa2.page_path LIKE '%pack_list.php%')
                     GROUP BY la2.user_id
                 ) latest ON latest.user_id = la.user_id AND latest.latest_at = laa.occurred_at
                 WHERE la.user_id IN ({$placeholders})
                   AND (laa.page_path LIKE '%production.php%' OR laa.page_path LIKE '%pack_list.php%')
                 ORDER BY laa.occurred_at DESC"
            );
            $activityStmt->execute(array_merge($bakerIds, $bakerIds));
            foreach ($activityStmt->fetchAll(PDO::FETCH_ASSOC) as $activity) {
                $bakerId = (int)$activity['user_id'];
                if (!isset($bakerRows[$bakerId]) || $bakerRows[$bakerId]['last_opened_at'] !== null) {
                    continue;
                }
                $path = (string)($activity['page_path'] ?? '');
                $bakerRows[$bakerId]['last_opened_at'] = (string)$activity['occurred_at'];
                $bakerRows[$bakerId]['last_opened_label'] = strpos($path, 'pack_list.php') !== false
                    ? 'Pack List'
                    : 'Daily Production';
            }
        }
        $bakerRows = array_values($bakerRows);
    }
} catch (Throwable $e) {
    error_log('manager baker activity: ' . $e->getMessage());
    $bakerRows = [];
}

try {
    $workflowEvents = bakery_operational_timeline_fetch($db, [
        'operational_date' => $selectedDate,
        'limit' => 5,
    ]);
} catch (Throwable $e) {
    error_log('manager workflow audit: ' . $e->getMessage());
}

$exceptionState = static function (string $severity): string {
    return in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : 'warning';
};

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/manager.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/manager_recovery.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">

<main class="manager-page">
  <header class="manager-hero">
    <div>
      <p class="manager-eyebrow">Bakery Manager · operating day control</p>
      <h1>Run bakery operations from one place</h1>
      <p class="manager-hero__copy">See production, packing, baker activity, routes, and the work that must be reconciled before closeout.</p>
    </div>
    <div class="manager-hero__actions">
      <?php if (bakery_staging_live_approval_available()): $approval = bakery_staging_live_approval_latest(); ?>
        <details class="manager-live-approval">
          <summary class="sf-btn sf-btn--primary">Approve staging for Live</summary>
          <form method="post" class="manager-live-approval__form">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="manager_mutation" value="approve_live">
            <label>Release ID <input name="release_id" required pattern="[A-Za-z0-9._:-]{3,160}" placeholder="release_YYYYMMDD_HHMMSS"></label>
            <label>Git commit (optional) <input name="git_commit" pattern="[0-9a-fA-F]{7,64}"></label>
            <label>Type <code>confirm</code> <input name="confirm_phrase" required autocomplete="off"></label>
            <button type="submit" class="sf-btn sf-btn--outline">Record approval</button>
            <?php if (is_array($approval)): ?><small>Last approval: <?php echo htmlspecialchars((string)($approval['release_id'] ?? '')); ?> — <?php echo htmlspecialchars((string)($approval['approved_at'] ?? '')); ?></small><?php endif; ?>
          </form>
        </details>
      <?php endif; ?>
      <a class="sf-btn sf-btn--primary" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_run.php?date=<?php echo urlencode($selectedDate); ?>">Open Daily Run</a>
      <a class="sf-btn sf-btn--outline" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_brief.php?date=<?php echo urlencode($selectedDate); ?>">Shift brief</a>
    </div>
  </header>

  <nav class="manager-date-nav" aria-label="Choose operating date">
    <a href="?date=<?php echo urlencode($previousDate); ?>">Previous day</a>
    <?php if ($selectedDate !== $today): ?>
      <a class="manager-date-nav__today" href="?date=<?php echo urlencode($today); ?>">Today</a>
    <?php endif; ?>
    <form method="get" action="">
      <label class="sf-sr-only" for="manager-date">Operating date</label>
      <input id="manager-date" type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
      <button type="submit">Go</button>
    </form>
    <a href="?date=<?php echo urlencode($nextDate); ?>">Next day</a>
    <strong><?php echo htmlspecialchars($dateDisplay); ?></strong>
  </nav>

  <?php if ($managerError !== null): ?>
    <div class="sf-alert sf-alert--warning" role="alert"><?php echo htmlspecialchars($managerError); ?></div>
  <?php endif; ?>
  <?php if ($managerNotice !== null): ?>
    <div class="sf-alert sf-alert--success" role="status"><?php echo htmlspecialchars($managerNotice); ?></div>
  <?php endif; ?>

  <section class="manager-scorecard" aria-label="Operating day health">
    <article class="manager-score manager-score--<?php echo $missingDaily > 0 ? 'attention' : 'ready'; ?>">
      <span class="manager-score__label">Orders ready</span>
      <strong><?php echo number_format($dailyOrders); ?></strong>
      <small><?php echo $missingDaily > 0 ? number_format($missingDaily) . ' standing customer(s) missing dated orders' : 'Dated customer orders for this day'; ?></small>
      <a href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($selectedDate, $missingDaily > 0 ? ['review' => 'missing'] : [], 'manager')); ?>">Review orders</a>
    </article>
    <article class="manager-score manager-score--<?php echo $unassigned > 0 ? 'attention' : 'ready'; ?>">
      <span class="manager-score__label">Routes assigned</span>
      <strong><?php echo number_format($assignedStops); ?><small>/<?php echo number_format($dailyOrders); ?> stops</small></strong>
      <small><?php echo $unassigned > 0 ? number_format($unassigned) . ' stop(s) still need a driver' : 'Every dated order has a driver route'; ?></small>
      <a href="<?php echo htmlspecialchars(bakery_ops_link_driver_assignment($selectedDate, $unassigned > 0 ? ['filter' => 'unassigned'] : [], 'manager')); ?>">Manage assignments</a>
    </article>
    <article class="manager-score manager-score--<?php echo $failed > 0 ? 'attention' : 'ready'; ?>">
      <span class="manager-score__label">Driver progress</span>
      <strong><?php echo number_format($delivered); ?><small> delivered</small></strong>
      <small><?php echo number_format($driversWithWork); ?> driver(s) working · <?php echo number_format($pending + $inTransit); ?> open · <?php echo number_format($failed); ?> failed</small>
      <a href="#driver-board">See driver board</a>
    </article>
    <article class="manager-score manager-score--<?php echo !empty($dailyRun['operational_complete']) || !empty($dailyRun['is_closed']) ? 'ready' : 'attention'; ?>">
      <span class="manager-score__label">Closeout</span>
      <strong><?php echo !empty($dailyRun['is_closed']) ? 'Closed' : (!empty($dailyRun['operational_complete']) ? 'Ready' : 'In progress'); ?></strong>
      <small><?php
        if ($routesOpen !== null) {
            echo (int)$routesOpen . ' route(s) still open for reconciliation';
        } elseif ($dailyRun) {
            echo htmlspecialchars((string)($dailyRun['progress']['label'] ?? 'Operating progress unavailable'));
        } else {
            echo 'Open Daily Run for closeout status';
        }
      ?></small>
      <a href="<?php echo htmlspecialchars(bakery_ops_link_route_closeout($selectedDate, $routesOpen ? ['attention' => 'open'] : [], 'manager')); ?>">Route closeout</a>
    </article>
  </section>

  <?php
    $productionPercent = $productionSummary['target'] > 0
      ? min(100, (int)round(($productionSummary['produced'] / $productionSummary['target']) * 100))
      : 0;
    $bakersActive = count(array_filter($bakerRows, static function (array $row): bool {
        return !empty($row['is_active']);
    }));
  ?>
  <section class="manager-handoff-board" aria-labelledby="manager-handoff-title">
    <div class="manager-section__header">
      <div>
        <p class="manager-eyebrow">Bake to dispatch</p>
        <h2 id="manager-handoff-title">Production, packing & baker presence</h2>
      </div>
      <span class="manager-handoff-board__note">Read-only signals from the production, packing, and app records for this date.</span>
    </div>
    <div class="manager-handoff-cards">
      <article class="manager-handoff-card manager-handoff-card--production">
        <div class="manager-handoff-card__top">
          <span>Going to production</span>
          <strong class="manager-handoff-card__state <?php echo $productionSummary['remaining'] > 0 ? 'is-open' : 'is-ready'; ?>">
            <?php echo $productionSummary['target'] > 0 ? ($productionSummary['remaining'] > 0 ? number_format($productionSummary['remaining']) . ' to make' : 'Production covered') : 'No bake target'; ?>
          </strong>
        </div>
        <strong class="manager-handoff-card__metric"><?php echo number_format($productionSummary['produced']); ?><small> / <?php echo number_format($productionSummary['target']); ?> units</small></strong>
        <div class="manager-progress" aria-label="<?php echo $productionPercent; ?> percent of production target recorded"><span style="width:<?php echo $productionPercent; ?>%"></span></div>
        <p><?php echo $productionSummary['has_daily'] ? 'Dated demand is feeding this bake target.' : 'Forecast demand is being used until dated orders are ready.'; ?></p>
        <div class="manager-handoff-card__links">
          <a href="<?php echo htmlspecialchars(BASE_URL); ?>production_center.php?week=<?php echo urlencode($selectedDate); ?>">Adjust plan</a>
          <a href="<?php echo htmlspecialchars(bakery_ops_link_production($selectedDate, [], 'manager')); ?>">Open bake sheet</a>
        </div>
      </article>

      <article class="manager-handoff-card manager-handoff-card--packing">
        <div class="manager-handoff-card__top">
          <span>Packing progress</span>
          <strong class="manager-handoff-card__state <?php echo $packingSummary['required_lines'] > 0 && $packingSummary['checked_lines'] === $packingSummary['required_lines'] ? 'is-ready' : 'is-open'; ?>">
            <?php echo $packingSummary['tracking_available'] ? $packingSummary['percent'] . '% checked' : 'Checks unavailable'; ?>
          </strong>
        </div>
        <strong class="manager-handoff-card__metric"><?php echo number_format($packingSummary['checked_lines']); ?><small> / <?php echo number_format($packingSummary['required_lines']); ?> lines</small></strong>
        <div class="manager-progress" aria-label="<?php echo $packingSummary['percent']; ?> percent of packing lines checked"><span style="width:<?php echo $packingSummary['percent']; ?>%"></span></div>
        <p><?php
          if (!$packingSummary['tracking_available']) {
              echo 'Open the Pack List once to begin shared check-off tracking.';
          } elseif ($packingSummary['last_checked_at']) {
              echo 'Last check by ' . htmlspecialchars($packingSummary['last_checked_by']) . ' at ' . htmlspecialchars(date('g:i A', strtotime($packingSummary['last_checked_at']))) . '.';
          } else {
              echo $packingSummary['required_lines'] > 0 ? 'No packing lines have been checked yet.' : 'No packing lines are required for this date.';
          }
        ?></p>
        <div class="manager-handoff-card__links">
          <a href="<?php echo htmlspecialchars(bakery_ops_link_pack_list($selectedDate, ['view' => 'product'], 'manager')); ?>">Open pack list</a>
          <a href="<?php echo htmlspecialchars(BASE_URL); ?>inventory.php?date=<?php echo urlencode($selectedDate); ?>">Check finished goods</a>
        </div>
      </article>

      <article class="manager-handoff-card manager-handoff-card--baker">
        <div class="manager-handoff-card__top">
          <span>Baker app presence</span>
          <strong class="manager-handoff-card__state <?php echo $bakersActive > 0 ? 'is-live' : 'is-neutral'; ?>">
            <?php echo $bakersActive > 0 ? number_format($bakersActive) . ' active now' : 'No active baker session'; ?>
          </strong>
        </div>
        <strong class="manager-handoff-card__metric"><?php echo number_format(count($bakerRows)); ?><small> baker<?php echo count($bakerRows) === 1 ? '' : 's'; ?> tracked</small></strong>
        <div class="manager-presence-dots" aria-hidden="true">
          <?php if ($bakerRows): ?>
            <?php foreach ($bakerRows as $baker): ?><i class="<?php echo $baker['is_active'] ? 'is-live' : ''; ?>"></i><?php endforeach; ?>
          <?php else: ?><i></i><i></i><i></i><?php endif; ?>
        </div>
        <p><?php echo $bakerActivityAvailable ? 'See the last Daily Production or Pack List opened below.' : 'Page-view activity is not available in this database yet.'; ?></p>
        <div class="manager-handoff-card__links">
          <a href="#baker-activity">View baker activity</a>
          <a href="<?php echo htmlspecialchars(bakery_ops_link_production($selectedDate, [], 'manager')); ?>">Open baker view</a>
        </div>
      </article>
    </div>
  </section>

  <?php
  // Prompt 11 — mobile exception desk (visible ≤720px). Do not delete if Prompt 12 adds a workshop.
  if (function_exists('bakery_exception_desk_render')) { bakery_exception_desk_render($db, $selectedDate, $exceptions); }
  ?>

  <section class="manager-section manager-attention manager-desktop-only" aria-labelledby="manager-attention-title">
    <div class="manager-section__header">
      <div>
        <p class="manager-eyebrow">Act next</p>
        <h2 id="manager-attention-title">Manager attention queue</h2>
      </div>
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_run.php?date=<?php echo urlencode($selectedDate); ?>#blockers">All run blockers</a>
    </div>
    <div class="manager-workshop-host">
      <?php
      // Prompt 12 — desktop exception workshop (min-width: 900px).
      if (function_exists('bakery_exception_workshop_render')) {
          bakery_exception_workshop_render($db, $selectedDate, $exceptions);
      }
      ?>
    </div>
    <?php if ($exceptions): ?>
      <div class="manager-attention-list">
        <?php foreach (array_slice($exceptions, 0, 8) as $exception): ?>
          <?php $severity = $exceptionState((string)($exception['severity'] ?? 'warning')); ?>
          <article class="manager-attention-item manager-attention-item--<?php echo htmlspecialchars($severity); ?>">
            <div class="manager-attention-item__badge"><?php echo htmlspecialchars(ucfirst($severity)); ?></div>
            <div class="manager-attention-item__copy">
              <h3><?php echo htmlspecialchars((string)($exception['title'] ?? 'Operational item')); ?></h3>
              <p><?php echo htmlspecialchars((string)($exception['detail'] ?? 'Review this operating-day item.')); ?></p>
            </div>
            <?php if (!empty($exception['href'])): ?>
              <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars((string)$exception['href']); ?>"><?php echo htmlspecialchars((string)($exception['action'] ?? 'Open')); ?></a>
            <?php endif; ?>
            <?php if (($exception['type'] ?? '') === 'delivery_failed'): ?>
              <a class="sf-btn sf-btn--outline sf-btn--sm" href="#failed-stop-recovery">Recovery</a>
            <?php endif; ?>
            <?php $work = is_array($exception['work'] ?? null) ? $exception['work'] : null; ?>
            <details class="manager-exception-work">
              <summary><?php echo $work && !empty($work['completed_at']) ? 'Completed' : ($work && !empty($work['acknowledged_at']) ? 'Owned' : 'Assign work'); ?></summary>
              <form method="post" class="manager-work-form">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="manager_mutation" value="exception_work">
                <input type="hidden" name="work_key" value="<?php echo htmlspecialchars((string)$exception['work_key']); ?>">
                <label><input type="checkbox" name="acknowledge" value="1" <?php echo $work && !empty($work['acknowledged_at']) ? 'checked' : ''; ?>> Acknowledged</label>
                <label>Manager
                  <select name="assigned_to_user_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($assignableManagers as $manager): ?>
                      <option value="<?php echo (int)$manager['id']; ?>" <?php echo $work && (int)($work['assigned_to_user_id'] ?? 0) === (int)$manager['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$manager['display_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label>Due time <input type="datetime-local" name="due_at" value="<?php echo $work && !empty($work['due_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime((string)$work['due_at']))) : ''; ?>"></label>
                <label>Resolution note <textarea name="resolution_note" rows="2" maxlength="2000"><?php echo htmlspecialchars((string)($work['resolution_note'] ?? '')); ?></textarea></label>
                <div><button class="sf-btn sf-btn--outline sf-btn--sm" type="submit">Save work</button><button class="sf-btn sf-btn--primary sf-btn--sm" type="submit" name="complete" value="1">Complete</button></div>
              </form>
            </details>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="manager-clear-state">
        <strong>No exceptions are asking for attention.</strong>
        <span>Keep an eye on driver progress and complete route reconciliation when deliveries finish.</span>
      </div>
    <?php endif; ?>
  </section>

  <div class="manager-operations-grid">
    <section class="manager-section manager-production-board" aria-labelledby="production-board-title">
      <div class="manager-section__header">
        <div>
          <p class="manager-eyebrow">Production control</p>
          <h2 id="production-board-title">What is going to production</h2>
        </div>
        <div class="manager-section__actions">
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>production_center.php?week=<?php echo urlencode($selectedDate); ?>">Production Center</a>
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_ops_link_production($selectedDate, [], 'manager')); ?>">Daily Production</a>
        </div>
      </div>
      <?php if ($productionRows): ?>
        <div class="manager-production-summary">
          <span><strong><?php echo number_format($productionSummary['demand']); ?></strong> demand units</span>
          <span><strong><?php echo number_format($productionSummary['planned_products']); ?></strong> products with saved targets</span>
          <span><strong><?php echo number_format($productionSummary['remaining']); ?></strong> units still to record</span>
        </div>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm manager-production-table">
            <thead><tr><th>Product</th><th class="num">Demand</th><th class="num">Plan</th><th class="num">Produced</th><th class="num">To make</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($productionRows as $row): ?>
                <?php
                  $isCovered = $row['remaining_quantity'] === 0;
                  $isPartial = $row['produced_quantity'] > 0 && !$isCovered;
                  $statusLabel = $row['plan_gap'] > 0
                      ? number_format($row['plan_gap']) . ' below demand'
                      : ($isCovered ? 'Covered' : ($isPartial ? 'In production' : 'Queued'));
                  $statusClass = $row['plan_gap'] > 0 ? 'danger' : ($isCovered ? 'success' : ($isPartial ? 'warning' : 'neutral'));
                ?>
                <tr>
                  <td data-label="Product"><strong><?php echo htmlspecialchars($row['product_name']); ?></strong><?php if ($row['dough_type_name'] !== ''): ?><small class="manager-table-note"><?php echo htmlspecialchars($row['dough_type_name']); ?></small><?php endif; ?></td>
                  <td class="num" data-label="Demand"><?php echo number_format($row['demand_quantity']); ?></td>
                  <td class="num" data-label="Plan"><?php echo $row['planned_quantity'] === null ? '<span class="manager-muted">Demand target</span>' : number_format($row['planned_quantity']); ?></td>
                  <td class="num" data-label="Produced"><?php echo number_format($row['produced_quantity']); ?><small class="manager-table-note"><?php echo number_format($row['available_quantity'] + $row['loaded_quantity']); ?> on hand</small></td>
                  <td class="num" data-label="To make"><strong><?php echo number_format($row['remaining_quantity']); ?></strong></td>
                  <td data-label="Status"><span class="sf-badge sf-badge--<?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="sf-empty"><p class="sf-empty__title">No production demand is available for this date.</p><p class="sf-empty__detail">Review dated orders or the saved production plan before starting the bake.</p><a class="sf-btn sf-btn--outline" href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($selectedDate, [], 'manager')); ?>">Review orders</a></div>
      <?php endif; ?>
    </section>

    <aside class="manager-section manager-packing-board" aria-labelledby="packing-board-title">
      <div class="manager-section__header">
        <div>
          <p class="manager-eyebrow">Packing control</p>
          <h2 id="packing-board-title">Packing readiness</h2>
        </div>
        <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_ops_link_pack_list($selectedDate, ['view' => 'customer'], 'manager')); ?>">Open list</a>
      </div>
      <div class="manager-packing-stat">
        <strong><?php echo number_format($packingSummary['checked_units']); ?><small> / <?php echo number_format($packingSummary['required_units']); ?> units checked</small></strong>
        <div class="manager-progress" aria-label="<?php echo $packingSummary['percent']; ?> percent packing complete"><span style="width:<?php echo $packingSummary['percent']; ?>%"></span></div>
      </div>
      <?php if (!$packingSummary['tracking_available']): ?>
        <p class="manager-inline-note">Shared pack check-offs will appear after the Pack List is opened for this date.</p>
      <?php elseif ($packingProducts): ?>
        <ul class="manager-packing-products">
          <?php foreach ($packingProducts as $packingProduct): ?>
            <li>
              <div><strong><?php echo htmlspecialchars($packingProduct['product_name']); ?></strong><small><?php echo number_format($packingProduct['checked_lines']); ?> / <?php echo number_format($packingProduct['required_lines']); ?> customer lines checked</small></div>
              <span><?php echo number_format($packingProduct['required_units'] - $packingProduct['checked_units']); ?> open</span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="manager-inline-note">No packing lines are required for this date.</p>
      <?php endif; ?>
      <div class="manager-packing-board__footer">
        <span><?php echo $packingSummary['last_checked_at'] ? 'Last checked ' . htmlspecialchars(date('M j, g:i A', strtotime($packingSummary['last_checked_at']))) : 'No pack check recorded'; ?></span>
        <a href="<?php echo htmlspecialchars(bakery_ops_link_pack_list($selectedDate, ['view' => 'route'], 'manager')); ?>">View by route</a>
      </div>
    </aside>
  </div>

  <div class="manager-audit-grid">
    <section class="manager-section manager-baker-activity" id="baker-activity" aria-labelledby="baker-activity-title">
      <div class="manager-section__header">
        <div>
          <p class="manager-eyebrow">Baker audit</p>
          <h2 id="baker-activity-title">Baker app activity</h2>
        </div>
        <span class="manager-audit-scope">Workflow pages only</span>
      </div>
      <?php if (!$bakerAuditAvailable): ?>
        <div class="manager-inline-note">Baker sign-in history is not available until the login audit migration has been installed.</div>
      <?php elseif (!$bakerRows): ?>
        <div class="manager-inline-note">No active baker profiles are available to track yet.</div>
      <?php else: ?>
        <div class="manager-baker-list">
          <?php foreach ($bakerRows as $baker): ?>
            <article class="manager-baker-row">
              <div class="manager-baker-avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr($baker['display_name'], 0, 1))); ?></div>
              <div class="manager-baker-row__identity"><strong><?php echo htmlspecialchars($baker['display_name']); ?></strong><small><?php echo $baker['is_active'] ? 'Active session · last seen ' . htmlspecialchars(date('g:i A', strtotime((string)$baker['last_seen_at']))) : 'Last seen ' . ($baker['last_seen_at'] ? htmlspecialchars(date('M j, g:i A', strtotime((string)$baker['last_seen_at']))) : 'not yet recorded'); ?></small></div>
              <div class="manager-baker-row__open"><span>Last app page opened</span><strong><?php echo $baker['last_opened_label'] !== '' ? htmlspecialchars($baker['last_opened_label']) : 'Not recorded'; ?></strong><small><?php echo $baker['last_opened_at'] ? htmlspecialchars(date('M j, g:i A', strtotime((string)$baker['last_opened_at']))) : 'Open the baker app to begin tracking'; ?></small></div>
              <div class="manager-baker-row__sign-in"><span>Selected-day sign-in</span><strong><?php echo $baker['date_login_at'] ? htmlspecialchars(date('g:i A', strtotime((string)$baker['date_login_at']))) : 'No sign-in'; ?></strong></div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="manager-section manager-workflow-tools" aria-labelledby="workflow-tools-title">
      <div class="manager-section__header">
        <div>
          <p class="manager-eyebrow">Controls & audit</p>
          <h2 id="workflow-tools-title">Manager tool suite</h2>
        </div>
        <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>operational_timeline.php?context=day&amp;date=<?php echo urlencode($selectedDate); ?>">Full timeline</a>
      </div>
      <div class="manager-tools-list">
        <a href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($selectedDate, [], 'manager')); ?>"><strong>Demand & order changes</strong><span>Review the commercial commitment</span></a>
        <a href="<?php echo htmlspecialchars(BASE_URL); ?>production_center.php?week=<?php echo urlencode($selectedDate); ?>"><strong>Production targets</strong><span>Adjust saved plan quantities</span></a>
        <a href="<?php echo htmlspecialchars(bakery_ops_link_pack_list($selectedDate, [], 'manager')); ?>"><strong>Packing checklist</strong><span>Check customer, product, or route work</span></a>
        <a href="<?php echo htmlspecialchars(bakery_ops_link_driver_assignment($selectedDate, [], 'manager')); ?>"><strong>Routes & dispatch</strong><span>Assign drivers and shape delivery work</span></a>
        <a href="<?php echo htmlspecialchars(bakery_ops_link_driver_load($selectedDate, [], 'manager')); ?>"><strong>Pickup loads</strong><span>Move finished goods into route custody</span></a>
        <a href="<?php echo htmlspecialchars(bakery_ops_link_route_closeout($selectedDate, [], 'manager')); ?>"><strong>Route reconciliation</strong><span>Audit returns, waste, and delivery differences</span></a>
      </div>
    </section>
  </div>

  <section class="manager-section manager-workflow-audit" aria-labelledby="workflow-audit-title">
    <div class="manager-section__header">
      <div>
        <p class="manager-eyebrow">Operating record</p>
        <h2 id="workflow-audit-title">Recent workflow audit</h2>
      </div>
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>operational_timeline.php?context=day&amp;date=<?php echo urlencode($selectedDate); ?>">Open full audit trail</a>
    </div>
    <?php if ($workflowEvents): ?>
      <ol class="manager-workflow-events">
        <?php foreach ($workflowEvents as $event): ?>
          <li>
            <time datetime="<?php echo htmlspecialchars((string)$event['occurred_at']); ?>"><?php echo htmlspecialchars(date('g:i A', strtotime((string)$event['occurred_at']))); ?></time>
            <div><strong><?php echo htmlspecialchars((string)($event['summary'] ?? 'Operational activity')); ?></strong><small><?php echo htmlspecialchars(ucfirst((string)($event['category'] ?? 'workflow')) . ' · ' . bakery_operational_actor_label($event)); ?></small></div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php else: ?>
      <div class="manager-inline-note">No recorded workflow activity is available for this operating date yet.</div>
    <?php endif; ?>
  </section>

  <section class="manager-section manager-route-plan" aria-labelledby="route-plan-title">
    <div class="manager-section__header">
      <div><p class="manager-eyebrow">Plan safely</p><h2 id="route-plan-title">Route planning</h2></div>
      <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_ops_link_driver_assignment($selectedDate, [], 'manager')); ?>">Open Driver Assignment</a>
    </div>
    <p class="manager-section__intro">This panel is read-only: route changes stay in Driver Assignment, which preserves its assignment safety checks.</p>
    <div class="manager-route-plan-grid">
      <div>
        <h3>Unassigned stops by zone <small><?php echo number_format((int)($routePlan['unassigned_count'] ?? 0)); ?> total · <?php echo number_format((int)($routePlan['tight_window_count'] ?? 0)); ?> tight window(s)</small></h3>
        <?php if (!empty($routePlan['unassigned_by_zone'])): ?>
          <?php foreach ($routePlan['unassigned_by_zone'] as $zone => $stops): ?>
            <article class="manager-zone-group"><h4><?php echo htmlspecialchars((string)$zone); ?> <span><?php echo count($stops); ?> stop(s)</span></h4><ul>
              <?php foreach ($stops as $stop): ?>
                <li><strong><?php echo htmlspecialchars((string)$stop['customer_name']); ?></strong><span class="sf-badge sf-badge--<?php echo $stop['window_pressure'] === 'tight' ? 'danger' : ($stop['window_pressure'] === 'deadline' ? 'warning' : 'neutral'); ?>"><?php echo htmlspecialchars((string)$stop['window_label']); ?></span><a href="<?php echo htmlspecialchars(bakery_ops_link_driver_assignment($selectedDate, ['focus_order' => (int)$stop['daily_order_id']], 'manager')); ?>">Assign safely</a></li>
              <?php endforeach; ?>
            </ul></article>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="manager-clear-state"><strong>Every dated stop has a route.</strong><span>Review capacity and windows before dispatch.</span></div>
        <?php endif; ?>
      </div>
      <div>
        <h3>Driver capacity & availability</h3>
        <div class="sf-table-wrap"><table class="sf-table sf-table--stack-sm"><thead><tr><th>Driver</th><th class="num">Stops</th><th>Availability</th><th>Capacity</th></tr></thead><tbody>
          <?php foreach (($routePlan['drivers'] ?? []) as $driver): ?><tr><td><strong><?php echo htmlspecialchars((string)$driver['name']); ?></strong></td><td class="num"><?php echo number_format((int)$driver['stop_count']); ?></td><td><?php echo htmlspecialchars((string)$driver['availability']); ?></td><td><?php echo htmlspecialchars((string)$driver['capacity']); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </div>
    </div>
  </section>

  <section class="manager-section manager-handoff-board" aria-labelledby="handoff-board-title">
    <div class="manager-section__header"><div><p class="manager-eyebrow">Dated handoff</p><h2 id="handoff-board-title">Production → packing → dispatch</h2></div></div>
    <div class="manager-handoff-board__grid">
      <article><span>Dated demand</span><strong><?php echo !empty($handoffBoard['demand_confirmed']['confirmed']) ? 'Confirmed' : 'Awaiting confirmation'; ?></strong><small><?php echo number_format((int)($handoffBoard['demand_confirmed']['units'] ?? 0)); ?> committed units</small><a href="<?php echo htmlspecialchars((string)($handoffBoard['demand_confirmed']['href'] ?? '#')); ?>">Review demand</a></article>
      <article><span>Production planned vs demand</span><strong><?php echo number_format((int)($handoffBoard['production']['actual'] ?? 0)); ?> / <?php echo number_format((int)($handoffBoard['production']['required'] ?? 0)); ?></strong><small>planned units / required units</small><a href="<?php echo htmlspecialchars((string)($handoffBoard['production']['href'] ?? '#')); ?>">Production plan</a></article>
      <article><span>Packed vs required</span><strong><?php echo number_format((int)($handoffBoard['pack']['actual'] ?? 0)); ?> / <?php echo number_format((int)($handoffBoard['pack']['required'] ?? 0)); ?></strong><small>checked packing lines / required lines</small><a href="<?php echo htmlspecialchars((string)($handoffBoard['pack']['href'] ?? '#')); ?>">Pack list</a></article>
      <article><span>Loaded vs required</span><strong><?php echo number_format((int)($handoffBoard['load']['actual'] ?? 0)); ?> / <?php echo number_format((int)($handoffBoard['load']['required'] ?? 0)); ?></strong><small>loaded units / required units</small><a href="<?php echo htmlspecialchars((string)($handoffBoard['load']['href'] ?? '#')); ?>">Pickup loads</a></article>
    </div>
  </section>

  <section class="manager-section manager-recovery manager-desktop-only" id="failed-stop-recovery" aria-labelledby="recovery-title">
    <div class="manager-section__header"><div><p class="manager-eyebrow">Recover deliberately</p><h2 id="recovery-title">Failed-stop recovery</h2></div><a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(bakery_ops_link_billing($selectedDate, ['attention' => 'needs_attention'], 'manager')); ?>">Billing & credit handoff</a></div>
    <p class="manager-section__intro">Each decision is logged. Customer communication and billing are handoffs only; invoice and credit changes stay in their canonical modules.</p>
    <?php foreach ($untriagedFailedStops as $stop): ?>
      <form method="post" class="manager-recovery-report"><strong><?php echo htmlspecialchars((string)$stop['customer_name']); ?></strong><span><?php echo htmlspecialchars((string)($stop['driver_name'] ?? 'Unassigned driver')); ?></span><?php echo bakery_csrf_field(); ?><input type="hidden" name="manager_mutation" value="recovery_report"><input type="hidden" name="assignment_id" value="<?php echo (int)$stop['assignment_id']; ?>"><select name="reason_code" required><?php foreach (bakery_delivery_recovery_reason_codes() as $code => $label): ?><option value="<?php echo htmlspecialchars($code); ?>"><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?></select><input name="manager_note" maxlength="2000" placeholder="Manager assessment" required><button class="sf-btn sf-btn--primary sf-btn--sm" type="submit">Open recovery</button></form>
    <?php endforeach; ?>
    <?php if ($recoveryCases): ?>
      <div class="manager-recovery-list">
      <?php foreach ($recoveryCases as $case): ?>
        <?php $reasonLabels = bakery_delivery_recovery_reason_codes(); $reasonLabel = $reasonLabels[(string)$case['failure_reason']] ?? (string)$case['failure_reason']; ?>
        <details class="manager-recovery-case"><summary><strong><?php echo htmlspecialchars((string)$case['customer_name']); ?></strong><span><?php echo htmlspecialchars(str_replace('_', ' ', (string)$case['workflow_state'])); ?></span><small><?php echo htmlspecialchars($reasonLabel); ?></small></summary>
          <div class="manager-recovery-case__body"><p>Original driver: <?php echo (int)$case['original_driver_id']; ?> · Active driver: <?php echo htmlspecialchars((string)($case['active_driver_name'] ?? '—')); ?></p>
            <form method="post" class="manager-work-form"><?php echo bakery_csrf_field(); ?><input type="hidden" name="manager_mutation" value="recovery_action"><input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>"><input type="hidden" name="recovery_action" value="update_handoffs"><label>Customer communication <select name="communication_status"><?php foreach (['not_needed','pending','contacted','unable_to_reach'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $case['customer_communication_status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></option><?php endforeach; ?></select></label><label>Billing / credit <select name="billing_handoff"><?php foreach (['not_needed','review_needed','credit_requested','credit_issued','not_billable'] as $status): ?><option value="<?php echo $status; ?>" <?php echo $case['billing_handoff'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(str_replace('_', ' ', $status)); ?></option><?php endforeach; ?></select></label><label>Manager note <textarea name="manager_note" rows="2" required><?php echo htmlspecialchars((string)$case['manager_note']); ?></textarea></label><label>Communication note <input name="communication_note" maxlength="2000" value="<?php echo htmlspecialchars((string)$case['customer_communication_note']); ?>"></label><button class="sf-btn sf-btn--outline sf-btn--sm" type="submit">Save handoffs</button></form>
            <form method="post" class="manager-recovery-actions"><?php echo bakery_csrf_field(); ?><input type="hidden" name="manager_mutation" value="recovery_action"><input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>"><input name="manager_note" maxlength="2000" placeholder="Decision note" required><button class="sf-btn sf-btn--outline sf-btn--sm" name="recovery_action" value="acknowledge">Acknowledge</button><label>Retry at <input type="datetime-local" name="retry_at"></label><button class="sf-btn sf-btn--outline sf-btn--sm" name="recovery_action" value="retry">Schedule retry</button><select name="to_driver_id"><option value="">Reassign to…</option><?php foreach (($routePlan['drivers'] ?? []) as $driver): ?><option value="<?php echo (int)$driver['id']; ?>"><?php echo htmlspecialchars((string)$driver['name']); ?> · <?php echo (int)$driver['stop_count']; ?> stops</option><?php endforeach; ?></select><button class="sf-btn sf-btn--outline sf-btn--sm" name="recovery_action" value="reassign">Reassign in Driver Assignment</button><button class="sf-btn sf-btn--primary sf-btn--sm" name="recovery_action" value="resolve">Resolve</button><button class="sf-btn sf-btn--outline sf-btn--sm" name="recovery_action" value="close">Close</button></form>
          </div>
        </details>
      <?php endforeach; ?></div>
    <?php elseif (!$untriagedFailedStops): ?>
      <div class="manager-clear-state"><strong>No failed stops need recovery.</strong><span>Failed stops will appear here with their auditable handoffs.</span></div>
    <?php endif; ?>
  </section>

  <div class="manager-work-grid">
    <section class="manager-section manager-driver-board" id="driver-board" aria-labelledby="driver-board-title">
      <div class="manager-section__header">
        <div>
          <p class="manager-eyebrow">Routes & drivers</p>
          <h2 id="driver-board-title">Driver board</h2>
        </div>
        <div class="manager-section__actions">
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>route_manager.php?date=<?php echo urlencode($selectedDate); ?>">Route manager</a>
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>route_summary.php?date=<?php echo urlencode($selectedDate); ?>">Route summary</a>
          <a class="sf-btn sf-btn--outline sf-btn--sm" href="<?php echo htmlspecialchars(BASE_URL); ?>daily_route.php?date=<?php echo urlencode($selectedDate); ?>">Daily route</a>
        </div>
      </div>
      <?php if ($driverRows): ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm">
            <thead><tr><th>Driver</th><th class="num">Stops</th><th class="num">Open</th><th class="num">Delivered</th><th class="num">Issue</th><th>Route</th></tr></thead>
            <tbody>
              <?php foreach ($driverRows as $driver): ?>
                <?php $open = $driver['pending'] + $driver['in_transit']; ?>
                <tr class="<?php echo $driver['failed'] > 0 ? 'manager-driver-row--issue' : ''; ?>">
                  <td data-label="Driver"><strong><?php echo htmlspecialchars($driver['name']); ?></strong><?php if ($driver['in_transit'] > 0): ?><small class="manager-live">In transit</small><?php endif; ?></td>
                  <td class="num" data-label="Stops"><?php echo number_format($driver['stops']); ?></td>
                  <td class="num" data-label="Open"><?php echo number_format($open); ?></td>
                  <td class="num" data-label="Delivered"><?php echo number_format($driver['delivered']); ?></td>
                  <td data-label="Issue"><?php if ($driver['failed'] > 0): ?><span class="sf-badge sf-badge--danger"><?php echo number_format($driver['failed']); ?> failed</span><?php elseif ($driver['stops'] === 0): ?><span class="sf-badge sf-badge--neutral">No route</span><?php else: ?><span class="sf-badge sf-badge--success">On route</span><?php endif; ?></td>
                  <td data-label="Route"><a href="<?php echo htmlspecialchars(BASE_URL); ?>driver.php?driver_id=<?php echo (int)$driver['id']; ?>&amp;date=<?php echo urlencode($selectedDate); ?>">Open route</a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="sf-empty"><p class="sf-empty__title">No active drivers are available.</p><p class="sf-empty__detail">Add or restore a driver before building routes for this day.</p><a class="sf-btn sf-btn--outline" href="<?php echo htmlspecialchars(BASE_URL); ?>drivers.php">Driver management</a></div>
      <?php endif; ?>
    </section>

    <aside class="manager-section manager-workflow" aria-labelledby="workflow-title">
      <div>
        <p class="manager-eyebrow">Control sequence</p>
        <h2 id="workflow-title">What the manager owns</h2>
      </div>
      <ol>
        <li><span>1</span><div><strong>Confirm dated demand</strong><small>Resolve missing and changed orders before dispatch.</small><a href="<?php echo htmlspecialchars(bakery_ops_link_daily_orders($selectedDate, [], 'manager')); ?>">Daily Orders</a></div></li>
        <li><span>2</span><div><strong>Assign and shape routes</strong><small>Give every stop a driver, then review route order.</small><a href="<?php echo htmlspecialchars(bakery_ops_link_driver_assignment($selectedDate, [], 'manager')); ?>">Driver Assignment</a></div></li>
        <li><span>3</span><div><strong>Supervise delivery</strong><small>Watch open, delivered, and failed stops without changing roles.</small><a href="#driver-board">Driver board</a></div></li>
        <li><span>4</span><div><strong>Reconcile the route</strong><small>Close loads, returns, cash, and delivery differences.</small><a href="<?php echo htmlspecialchars(bakery_ops_link_route_closeout($selectedDate, [], 'manager')); ?>">Route Closeout</a></div></li>
      </ol>
      <div class="manager-handoff">
        <strong>Downstream handoff</strong>
        <p>Production, packing, and pickup stay connected to this operating day without taking over the manager view.</p>
        <div>
          <a href="<?php echo htmlspecialchars(bakery_ops_link_production($selectedDate, [], 'manager')); ?>">Production</a>
          <a href="<?php echo htmlspecialchars(bakery_ops_link_pack_list($selectedDate, [], 'manager')); ?>">Pack list</a>
          <a href="<?php echo htmlspecialchars(bakery_ops_link_driver_load($selectedDate, [], 'manager')); ?>">Pickup loads</a>
        </div>
      </div>
    </aside>
  </div>
</main>

<?php require_once 'includes/footer.php'; ?>
