<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/operational_exceptions.php';

$selectedDate = $_GET['date'] ?? $_POST['delivery_date'] ?? date('Y-m-d', strtotime('+1 day'));
try {
    $selectedDate = bakery_inventory_validate_date((string)$selectedDate);
} catch (Throwable $e) {
    $selectedDate = date('Y-m-d', strtotime('+1 day'));
}

$focusDriverId = (int)($_GET['driver_id'] ?? $_POST['board_driver_id'] ?? 0);
$attentionIncomplete = (string)($_GET['attention'] ?? '') === 'incomplete';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$attentionLabel = $attentionIncomplete
    ? (function_exists('bakery_t') ? bakery_t('ops.attention.incomplete_load') : 'Showing drivers with incomplete loads')
    : '';
$pageReturnKey = $returnTarget['key'] ?? null;
$notice = '';
$error = '';
$savedDriverId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_load') {
    $savedDriverId = (int)($_POST['driver_id'] ?? 0);
    // Keep the board filter (all drivers vs one) separate from the driver being saved.
    try {
        if (!bakery_inventory_ready($db)) {
            throw new RuntimeException('Finished-goods inventory is not installed. Run the database migrations first.');
        }
        $quantities = $_POST['load'] ?? [];
        if (!is_array($quantities)) {
            $quantities = [];
        }
        bakery_inventory_save_driver_load(
            $db,
            $selectedDate,
            $savedDriverId,
            $quantities,
            trim((string)($_POST['notes'] ?? ''))
        );

        $added = 0;
        $returned = 0;
        foreach ($quantities as $productId => $rawQty) {
            $newQty = (int)$rawQty;
            $prevQty = (int)($_POST['prev_load'][$productId] ?? 0);
            $delta = $newQty - $prevQty;
            if ($delta > 0) {
                $added += $delta;
            } elseif ($delta < 0) {
                $returned += -$delta;
            }
        }
        $parts = ['Pickup saved for this driver.'];
        if ($added > 0) {
            $parts[] = "Reserved {$added} unit(s) from finished goods where stock was available.";
        }
        if ($returned > 0) {
            $parts[] = "Returned {$returned} unit(s) to finished goods via load correction.";
        }
        if ($added === 0 && $returned === 0) {
            $parts[] = 'Quantities unchanged; assigned orders advanced toward out for delivery.';
        } else {
            $parts[] = 'Assigned orders advanced toward out for delivery.';
        }
        $notice = implode(' ', $parts);

        $driverRow = bakery_get_driver_by_id($db, $savedDriverId);
        $driverName = $driverRow['name'] ?? ('Driver #' . $savedDriverId);
        bakery_record_operational_event($db, BAKERY_OP_DRIVER_LOAD_SAVED,
            'Saved pickup load for ' . $driverName, [
            'operational_date' => $selectedDate,
            'driver_id' => $savedDriverId,
            'metadata' => [
                'units_added' => $added,
                'units_returned' => $returned,
                'product_count' => count($quantities),
            ],
        ]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$drivers = bakery_get_drivers($db);
$inventoryReady = bakery_inventory_ready($db);

/**
 * Derive load readiness from assignments + product need/loaded (not a persisted lifecycle).
 *
 * @param int $stopCount
 * @param array<int, array<string, mixed>> $products
 * @return array{key:string,label:string,detail:string}
 */
if (!function_exists('bakery_load_readiness')) {
    function bakery_load_readiness(int $stopCount, array $products): array
    {
        $hasRequired = false;
        $anyLoaded = false;
        $allMet = true;
        $shortProducts = 0;
        $remainingUnits = 0;

        foreach ($products as $product) {
            $need = (int)$product['required_quantity'];
            $loaded = (int)$product['loaded_quantity'];
            if ($need > 0) {
                $hasRequired = true;
                $remain = max(0, $need - $loaded);
                $remainingUnits += $remain;
                if ($remain > 0) {
                    $allMet = false;
                    $shortProducts++;
                }
            }
            if ($loaded > 0) {
                $anyLoaded = true;
            }
        }

        if ($stopCount <= 0 && !$hasRequired && !$anyLoaded) {
            return [
                'key' => 'not_assigned',
                'label' => 'Not assigned',
                'detail' => 'No stops or product need for this delivery day.',
            ];
        }
        if ($hasRequired && !$anyLoaded) {
            return [
                'key' => 'needs_loading',
                'label' => 'Needs loading',
                'detail' => $remainingUnits . ' unit(s) still to load across ' . $shortProducts . ' product(s).',
            ];
        }
        if ($hasRequired && $allMet) {
            return [
                'key' => 'ready',
                'label' => 'Ready',
                'detail' => 'Loaded meets or exceeds order need for every product on this route.',
            ];
        }
        if ($stopCount > 0 && !$hasRequired && !$anyLoaded) {
            return [
                'key' => 'ready',
                'label' => 'Ready — no pickup',
                'detail' => $stopCount . ' assigned stop' . ($stopCount === 1 ? '' : 's')
                    . ' with no product quantities to load.',
            ];
        }
        if ($anyLoaded || $hasRequired) {
            return [
                'key' => 'partial',
                'label' => 'Partially loaded',
                'detail' => $remainingUnits > 0
                    ? $remainingUnits . ' unit(s) remaining across ' . $shortProducts . ' product(s).'
                    : 'Load recorded; order need is unclear for some lines.',
            ];
        }

        return [
            'key' => 'not_assigned',
            'label' => 'Not assigned',
            'detail' => 'No stops or product need for this delivery day.',
        ];
    }
}

$page_title = bakery_t('page.driver_load');
require_once 'includes/header.php';
require_once 'includes/nav.php';

$driverSheets = [];
$daySummary = [
    'drivers' => 0,
    'stops' => 0,
    'customers' => 0,
    'ready' => 0,
    'partial' => 0,
    'needs_loading' => 0,
    'not_assigned' => 0,
    'remaining_units' => 0,
    'inventory_blocked' => 0,
];
$loadProgress = ['drivers_with_work' => 0, 'incomplete' => []];
$todayDate = date('Y-m-d');
$todayProgress = ['drivers_with_work' => 0, 'incomplete' => []];
$todayIncompleteHref = '';
if ($inventoryReady && function_exists('bakery_inventory_load_progress')) {
    $loadProgress = bakery_inventory_load_progress($db, $selectedDate);
    $todayProgress = $selectedDate === $todayDate
        ? $loadProgress
        : bakery_inventory_load_progress($db, $todayDate);
    if ($todayProgress['incomplete'] !== []) {
        $todayFocus = $todayProgress['incomplete'][0];
        $todayParams = ['attention' => 'incomplete'];
        if (count($todayProgress['incomplete']) === 1) {
            $todayParams['driver_id'] = (int)$todayFocus['driver_id'];
        }
        $todayIncompleteHref = bakery_ops_link_driver_load($todayDate, $todayParams, $pageReturnKey);
    }
}

if ($inventoryReady) {
    $zoneJoin = function_exists('bakery_customer_zone_join_sql')
        ? bakery_customer_zone_join_sql()
        : 'LEFT JOIN zones z ON c.zone = z.name';

    $routeStmt = $db->prepare(
        "SELECT doa.driver_id,
                COUNT(DISTINCT doa.daily_order_id) AS stop_count,
                COUNT(DISTINCT do.customer_id) AS customer_count,
                GROUP_CONCAT(DISTINCT COALESCE(z.name, NULLIF(c.zone, ''), 'No zone') ORDER BY COALESCE(z.name, c.zone) SEPARATOR ', ') AS zones
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         " . bakery_sfb_ops_origin_clause('c', $db) . "
         {$zoneJoin}
         WHERE doa.delivery_date = ? AND do.order_date = doa.delivery_date
           AND doa.delivery_status <> 'cancelled'
         GROUP BY doa.driver_id"
    );
    $routeStmt->execute([$selectedDate]);
    $routeByDriver = [];
    foreach ($routeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $routeByDriver[(int)$row['driver_id']] = $row;
    }

    $stopStmt = $db->prepare(
        "SELECT doa.driver_id, doa.route_order, doa.delivery_status, do.status AS order_status,
                c.name AS customer_name,
                COALESCE(z.name, NULLIF(c.zone, ''), 'No zone') AS zone_label
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         " . bakery_sfb_ops_origin_clause('c', $db) . "
         {$zoneJoin}
         WHERE doa.delivery_date = ? AND do.order_date = doa.delivery_date
           AND doa.delivery_status <> 'cancelled'
         ORDER BY doa.driver_id, doa.route_order, c.name"
    );
    $stopStmt->execute([$selectedDate]);
    $stopsByDriver = [];
    foreach ($stopStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stopsByDriver[(int)$row['driver_id']][] = $row;
    }

    $productStmt = $db->prepare(
        "SELECT doa.driver_id, p.id AS product_id, p.name AS product_name,
                COALESCE(SUM(doi.quantity), 0) AS required_quantity,
                COALESCE(MAX(li.loaded_quantity), 0) AS loaded_quantity,
                COALESCE(MAX(inv.available_quantity), 0) AS available_quantity,
                COALESCE(MAX(inv.produced_quantity), 0) AS produced_quantity,
                MAX(dl.notes) AS load_notes,
                MAX(dl.id) AS driver_load_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN daily_order_items doi ON doi.daily_order_id = do.id
         JOIN products p ON p.id = doi.product_id
         LEFT JOIN driver_loads dl ON dl.driver_id = doa.driver_id AND dl.delivery_date = doa.delivery_date
         LEFT JOIN driver_load_items li ON li.driver_load_id = dl.id AND li.product_id = p.id
         LEFT JOIN product_inventory_days inv ON inv.product_id = p.id AND inv.delivery_date = doa.delivery_date
         WHERE doa.delivery_date = ? AND do.order_date = doa.delivery_date
           AND doa.delivery_status <> 'cancelled'
         GROUP BY doa.driver_id, p.id, p.name
         ORDER BY doa.driver_id, p.name"
    );
    $productStmt->execute([$selectedDate]);
    $productsByDriver = [];
    foreach ($productStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $productsByDriver[(int)$row['driver_id']][] = $row;
    }

    // Include load-only lines (extras / corrections) not currently on assigned orders.
    $extraStmt = $db->prepare(
        "SELECT dl.driver_id, p.id AS product_id, p.name AS product_name,
                0 AS required_quantity,
                COALESCE(li.loaded_quantity, 0) AS loaded_quantity,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                COALESCE(inv.produced_quantity, 0) AS produced_quantity,
                dl.notes AS load_notes,
                dl.id AS driver_load_id
         FROM driver_loads dl
         JOIN driver_load_items li ON li.driver_load_id = dl.id
         JOIN products p ON p.id = li.product_id
         LEFT JOIN product_inventory_days inv ON inv.product_id = p.id AND inv.delivery_date = dl.delivery_date
         WHERE dl.delivery_date = ?"
    );
    $extraStmt->execute([$selectedDate]);
    foreach ($extraStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $driverId = (int)$row['driver_id'];
        $productId = (int)$row['product_id'];
        $exists = false;
        foreach ($productsByDriver[$driverId] ?? [] as $existing) {
            if ((int)$existing['product_id'] === $productId) {
                $exists = true;
                break;
            }
        }
        if (!$exists && (int)$row['loaded_quantity'] > 0) {
            $productsByDriver[$driverId][] = $row;
        }
    }

    // Optional extras: finished-goods available today but not on this driver's orders.
    $invExtraStmt = $db->prepare(
        "SELECT p.id AS product_id, p.name AS product_name,
                0 AS required_quantity,
                0 AS loaded_quantity,
                COALESCE(inv.available_quantity, 0) AS available_quantity,
                COALESCE(inv.produced_quantity, 0) AS produced_quantity,
                NULL AS load_notes,
                NULL AS driver_load_id
         FROM product_inventory_days inv
         JOIN products p ON p.id = inv.product_id
         WHERE inv.delivery_date = ? AND inv.available_quantity > 0
         ORDER BY p.name"
    );
    $invExtraStmt->execute([$selectedDate]);
    $inventoryExtras = $invExtraStmt->fetchAll(PDO::FETCH_ASSOC);

    $notesStmt = $db->prepare('SELECT driver_id, notes FROM driver_loads WHERE delivery_date = ?');
    $notesStmt->execute([$selectedDate]);
    $notesByDriver = [];
    foreach ($notesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $notesByDriver[(int)$row['driver_id']] = (string)($row['notes'] ?? '');
    }

    $driverIds = [];
    foreach ($drivers as $driver) {
        $driverIds[(int)$driver['id']] = (string)$driver['name'];
    }
    // Drivers with work today even if later archived from the active list.
    foreach (array_keys($routeByDriver + $productsByDriver) as $driverId) {
        if (!isset($driverIds[$driverId])) {
            $nameStmt = $db->prepare('SELECT name FROM drivers WHERE id = ?');
            $nameStmt->execute([$driverId]);
            $driverIds[$driverId] = (string)($nameStmt->fetchColumn() ?: ('Driver #' . $driverId));
        }
    }

    asort($driverIds, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($driverIds as $driverId => $driverName) {
        $products = $productsByDriver[$driverId] ?? [];
        $route = $routeByDriver[$driverId] ?? null;
        $stopCount = (int)($route['stop_count'] ?? 0);
        $customerCount = (int)($route['customer_count'] ?? 0);
        $zones = trim((string)($route['zones'] ?? ''));
        $hasWork = $stopCount > 0 || !empty($products);

        if ($focusDriverId > 0 && $driverId !== $focusDriverId) {
            continue;
        }
        if ($focusDriverId <= 0 && !$hasWork) {
            continue;
        }

        // In single-driver focus, offer warehouse extras so managers can stage non-order units.
        if ($focusDriverId > 0) {
            $presentIds = [];
            foreach ($products as $product) {
                $presentIds[(int)$product['product_id']] = true;
            }
            foreach ($inventoryExtras as $extra) {
                $extraId = (int)$extra['product_id'];
                if (!isset($presentIds[$extraId])) {
                    $products[] = $extra;
                    $presentIds[$extraId] = true;
                }
            }
        }

        $loadNotes = $notesByDriver[$driverId] ?? '';

        $enriched = [];
        $driverRemaining = 0;
        $driverBlocked = 0;
        foreach ($products as $product) {
            $need = (int)$product['required_quantity'];
            $loaded = (int)$product['loaded_quantity'];
            $available = (int)$product['available_quantity'];
            $produced = (int)($product['produced_quantity'] ?? 0);
            $remaining = max(0, $need - $loaded);
            $usable = $available + $loaded; // warehouse free + already on this van
            $maxLoadable = $usable;
            $shortage = max(0, $remaining - $available);
            $notInProduction = $need > 0 && $produced <= 0 && $available <= 0;
            $lineStatus = 'ready';
            if ($need <= 0 && $loaded <= 0) {
                $lineStatus = 'none';
            } elseif ($notInProduction && $remaining > 0) {
                $lineStatus = 'not_produced';
            } elseif ($remaining > 0 && $shortage > 0) {
                $lineStatus = 'shortage';
                $driverBlocked++;
            } elseif ($remaining > 0) {
                $lineStatus = 'remaining';
            } elseif ($loaded > $need && $need > 0) {
                $lineStatus = 'over';
            } elseif ($need <= 0 && $loaded > 0) {
                $lineStatus = 'extra';
            }
            $driverRemaining += $remaining;
            $enriched[] = [
                'product_id' => (int)$product['product_id'],
                'name' => (string)$product['product_name'],
                'required_quantity' => $need,
                'loaded_quantity' => $loaded,
                'available_quantity' => $available,
                'produced_quantity' => $produced,
                'usable_quantity' => $usable,
                'remaining_quantity' => $remaining,
                'shortage_quantity' => $shortage,
                'max_loadable' => $maxLoadable,
                'not_in_production' => $notInProduction,
                'line_status' => $lineStatus,
                'default_pickup' => $loaded > 0 ? $loaded : $need,
            ];
        }

        usort($enriched, static function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        $readiness = bakery_load_readiness($stopCount, $enriched);
        $daySummary['drivers']++;
        $daySummary['stops'] += $stopCount;
        $daySummary['customers'] += $customerCount;
        $daySummary['remaining_units'] += $driverRemaining;
        $daySummary['inventory_blocked'] += $driverBlocked > 0 ? 1 : 0;
        if (isset($daySummary[$readiness['key']])) {
            $daySummary[$readiness['key']]++;
        }

        $driverSheets[] = [
            'driver_id' => $driverId,
            'name' => $driverName,
            'stop_count' => $stopCount,
            'customer_count' => $customerCount,
            'zones' => $zones !== '' ? $zones : '—',
            'stops' => $stopsByDriver[$driverId] ?? [],
            'products' => $enriched,
            'notes' => $loadNotes,
            'readiness' => $readiness,
            'remaining_units' => $driverRemaining,
            'inventory_blocked' => $driverBlocked,
            'highlight' => $savedDriverId > 0 && $savedDriverId === $driverId,
        ];
    }
    if ($attentionIncomplete && $driverSheets !== []) {
        $driverSheets = array_values(array_filter($driverSheets, static function ($sheet) {
            $key = $sheet['readiness']['key'] ?? '';
            return in_array($key, ['needs_loading', 'partial'], true);
        }));
    }
} elseif (!$error) {
    $error = 'Finished-goods inventory is not installed. Run scripts/run_migrations.php first.';
}

$pageExceptions = [];
try {
    $pageExceptions = bakery_ops_exceptions_for_date($db, $selectedDate, $pageReturnKey);
} catch (Throwable $e) {
    error_log('driver_load exceptions: ' . $e->getMessage());
}
?>
<main class="load-page container">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="load-heading">
        <div>
            <h1>Load &amp; Dispatch</h1>
            <p><?php bakery_te('driver_load.lead'); ?></p>
        </div>
        <div class="load-heading-actions">
            <a class="btn btn-outline" href="inventory.php?date=<?php echo urlencode($selectedDate); ?>">Inventory board</a>
            <a class="btn btn-outline" href="driver_assignment.php?date=<?php echo urlencode($selectedDate); ?>">Driver assignment</a>
            <a class="btn btn-primary" href="route_closeout.php?date=<?php echo urlencode($selectedDate); ?>">Route closeout</a>
        </div>
    </div>

    <?php if ($notice): ?><div class="load-notice success" role="status"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="load-notice error" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <?php
    $selectedIncomplete = $loadProgress['incomplete'] ?? [];
    $todayIncomplete = $todayProgress['incomplete'] ?? [];
    if ($selectedDate !== $todayDate && $todayIncomplete !== []):
        $todayNames = implode(', ', array_map(static function ($row) {
            return (string)$row['name'];
        }, $todayIncomplete));
        ?>
        <div class="load-notice warn" role="status">
            <?php echo htmlspecialchars(bakery_t('driver_load.today_still_open', [
                'date' => date('D, M j', strtotime($todayDate)),
                'count' => (string)count($todayIncomplete),
                'drivers' => $todayNames,
            ])); ?>
            <?php if ($todayIncompleteHref !== ''): ?>
                <a href="<?php echo htmlspecialchars($todayIncompleteHref); ?>"><?php bakery_te('driver_load.open_today'); ?></a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($selectedIncomplete !== []):
        $focus = $selectedIncomplete[0];
        $short = count($selectedIncomplete) === 1
            ? bakery_t('driver_load.finish_one', [
                'name' => (string)$focus['name'],
                'required' => number_format((int)$focus['required']),
                'loaded' => number_format((int)$focus['loaded']),
            ])
            : bakery_t('driver_load.finish_many', [
                'count' => (string)count($selectedIncomplete),
            ]);
        ?>
        <div class="load-notice warn" role="status" data-load-finish-hint>
            <?php echo htmlspecialchars($short); ?>
            <?php bakery_te('driver_load.finish_how'); ?>
        </div>
    <?php endif; ?>

    <form method="get" class="load-selector">
        <?php if ($pageReturnKey): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars((string)$pageReturnKey); ?>"><?php endif; ?>
        <?php if ($attentionIncomplete): ?><input type="hidden" name="attention" value="incomplete"><?php endif; ?>
        <label>Delivery day
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </label>
        <label>Focus driver
            <select name="driver_id">
                <option value="0" <?php echo $focusDriverId === 0 ? 'selected' : ''; ?>>All drivers with work</option>
                <?php foreach ($drivers as $driver): ?>
                    <option value="<?php echo (int)$driver['id']; ?>" <?php echo (int)$driver['id'] === $focusDriverId ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($driver['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-outline" type="submit">Show load board</button>
    </form>

    <?php if ($inventoryReady): ?>
    <div class="load-day-summary" aria-label="Day load summary">
        <div class="load-day-summary-main">
            <strong><?php echo date('D, M j', strtotime($selectedDate)); ?></strong>
            <span><?php echo (int)$daySummary['drivers']; ?> driver route(s) · <?php echo (int)$daySummary['stops']; ?> stop(s) · <?php echo (int)$daySummary['customers']; ?> customer(s)</span>
        </div>
        <div class="load-day-pills">
            <span class="load-pill ready"><?php echo (int)$daySummary['ready']; ?> ready</span>
            <span class="load-pill partial"><?php echo (int)$daySummary['partial']; ?> partial</span>
            <span class="load-pill needs"><?php echo (int)$daySummary['needs_loading']; ?> need loading</span>
            <?php if ($daySummary['inventory_blocked'] > 0): ?>
                <span class="load-pill blocked"><?php echo (int)$daySummary['inventory_blocked']; ?> inventory short</span>
            <?php endif; ?>
            <?php if ($daySummary['remaining_units'] > 0): ?>
                <span class="load-pill remain"><?php echo (int)$daySummary['remaining_units']; ?> unit(s) remaining</span>
            <?php endif; ?>
        </div>
    </div>
    <p class="load-legend">
        <strong>How to load:</strong> For each product, set <strong>Pickup quantity</strong> to what the driver actually picked up.
        Use <strong>Fill to need</strong> to set the full order amount, or type any number to adjust.
        <strong>Need</strong> = assigned order totals ·
        <strong>Loaded</strong> = saved on this driver’s pickup ·
        <strong>Remaining</strong> = still short of need ·
        Yellow/red warnings mean stock may be short — you can still save if the product was physically loaded.
        Reducing a pickup returns units to finished goods.
    </p>
    <?php endif; ?>

    <?php if (!$driverSheets && $inventoryReady): ?>
        <div class="load-empty">
            <?php if ($focusDriverId > 0): ?>
                No assigned stops or pickup lines for this driver on <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate))); ?>.
                <a href="driver_assignment.php?date=<?php echo urlencode($selectedDate); ?>">Assign orders</a>
                or choose another driver.
            <?php else: ?>
                No driver assignments or pickups for <?php echo htmlspecialchars(date('M j, Y', strtotime($selectedDate))); ?> yet.
                Start from <a href="driver_assignment.php?date=<?php echo urlencode($selectedDate); ?>">Driver assignment</a>,
                then return here to load vans.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php foreach ($driverSheets as $sheet):
        $readyKey = $sheet['readiness']['key'];
        ?>
        <section class="load-driver<?php echo $sheet['highlight'] ? ' is-saved' : ''; ?><?php echo in_array($readyKey, ['needs_loading', 'partial'], true) ? ' ops-attention-row' : ''; ?>" id="driver-<?php echo (int)$sheet['driver_id']; ?>" data-driver-id="<?php echo (int)$sheet['driver_id']; ?>">
            <header class="load-driver-head">
                <div class="load-driver-title">
                    <h2><?php echo htmlspecialchars($sheet['name']); ?></h2>
                    <?php
                    echo bakery_ops_render_row_chips($pageExceptions, [
                        'driver_id' => (int)$sheet['driver_id'],
                        'flags' => in_array($readyKey, ['needs_loading', 'partial'], true) ? ['load_incomplete' => true] : [],
                    ], ['date' => $selectedDate, 'return' => (string)$pageReturnKey]);
                    ?>
                    <span class="load-ready load-ready-<?php echo htmlspecialchars($readyKey); ?>" title="<?php echo htmlspecialchars($sheet['readiness']['detail']); ?>">
                        <?php echo htmlspecialchars($sheet['readiness']['label']); ?>
                    </span>
                </div>
                <div class="load-driver-meta">
                    <span><strong><?php echo (int)$sheet['stop_count']; ?></strong> stop(s)</span>
                    <span><strong><?php echo (int)$sheet['customer_count']; ?></strong> customer(s)</span>
                    <span>Zone(s): <strong><?php echo htmlspecialchars($sheet['zones']); ?></strong></span>
                    <?php if ($sheet['remaining_units'] > 0): ?>
                        <span class="load-meta-warn"><?php echo (int)$sheet['remaining_units']; ?> unit(s) remaining</span>
                    <?php endif; ?>
                    <?php if ($sheet['inventory_blocked'] > 0): ?>
                        <span class="load-meta-danger"><?php echo (int)$sheet['inventory_blocked']; ?> product(s) short in warehouse</span>
                    <?php endif; ?>
                </div>
                <p class="load-ready-detail"><?php echo htmlspecialchars($sheet['readiness']['detail']); ?></p>
            </header>

            <?php if ($sheet['stops']): ?>
            <details class="load-stops">
                <summary>Assigned stops (<?php echo count($sheet['stops']); ?>)</summary>
                <ol>
                    <?php foreach ($sheet['stops'] as $stop): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($stop['customer_name']); ?></strong>
                            <span class="load-stop-zone"><?php echo htmlspecialchars($stop['zone_label']); ?></span>
                            <span class="load-stop-status"><?php echo htmlspecialchars(str_replace('_', ' ', (string)$stop['order_status'])); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </details>
            <?php endif; ?>

            <form method="post" class="load-sheet" data-load-form>
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="save_load">
                <input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                <input type="hidden" name="driver_id" value="<?php echo (int)$sheet['driver_id']; ?>">
                <input type="hidden" name="board_driver_id" value="<?php echo (int)$focusDriverId; ?>">

                <?php if (!$sheet['products']): ?>
                    <p class="load-empty-inline">No product lines on assigned orders for this driver.</p>
                <?php else: ?>
                <div class="load-sheet-actions">
                    <p class="load-sheet-help">Set each line’s pickup quantity, or use the shortcuts below.</p>
                    <div class="load-bulk-actions">
                        <button type="button" class="btn btn-outline" data-load-all-need>Fill all to need</button>
                        <button type="button" class="btn btn-outline" data-reset-all-saved>Reset all to saved</button>
                    </div>
                </div>
                <div class="load-product-list" role="list">
                    <?php
                    $routeProducts = [];
                    $optionalProducts = [];
                    foreach ($sheet['products'] as $product) {
                        if ((int)$product['required_quantity'] > 0 || (int)$product['loaded_quantity'] > 0) {
                            $routeProducts[] = $product;
                        } else {
                            $optionalProducts[] = $product;
                        }
                    }
                    foreach ($routeProducts as $product):
                        $pid = (int)$product['product_id'];
                        $status = $product['line_status'];
                        $statusLabel = [
                            'ready' => 'Ready',
                            'remaining' => 'Still needed',
                            'shortage' => 'Stock short',
                            'not_produced' => 'Not in production',
                            'over' => 'Over need',
                            'extra' => 'Extra on van',
                            'none' => '',
                        ][$status] ?? '';
                        ?>
                        <article class="load-product load-product-<?php echo htmlspecialchars($status); ?>" role="listitem" data-product-row
                                 data-product-id="<?php echo $pid; ?>"
                                 data-need="<?php echo (int)$product['required_quantity']; ?>"
                                 data-loaded="<?php echo (int)$product['loaded_quantity']; ?>"
                                 data-available="<?php echo (int)$product['available_quantity']; ?>"
                                 data-produced="<?php echo (int)$product['produced_quantity']; ?>"
                                 data-max="<?php echo (int)$product['max_loadable']; ?>"
                                 data-not-produced="<?php echo $product['not_in_production'] ? '1' : '0'; ?>">
                            <div class="load-product-name">
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <?php if ($statusLabel): ?>
                                    <span class="load-line-badge"><?php echo htmlspecialchars($statusLabel); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="load-qty-grid">
                                <div class="load-qty">
                                    <span class="load-qty-label">Need</span>
                                    <span class="load-qty-value"><?php echo number_format($product['required_quantity']); ?></span>
                                </div>
                                <div class="load-qty">
                                    <span class="load-qty-label">Loaded</span>
                                    <span class="load-qty-value" data-loaded-display><?php echo number_format($product['loaded_quantity']); ?></span>
                                </div>
                                <div class="load-qty <?php echo $product['remaining_quantity'] > 0 ? 'is-remain' : 'is-ok'; ?>">
                                    <span class="load-qty-label">Remaining</span>
                                    <span class="load-qty-value" data-remaining-display>
                                        <?php echo $product['remaining_quantity'] > 0 ? number_format($product['remaining_quantity']) : '0 · Ready'; ?>
                                    </span>
                                </div>
                                <div class="load-qty">
                                    <span class="load-qty-label">FG available</span>
                                    <span class="load-qty-value" title="Finished-goods units free in warehouse right now"><?php echo number_format($product['available_quantity']); ?></span>
                                </div>
                                <div class="load-qty">
                                    <span class="load-qty-label">Produced</span>
                                    <span class="load-qty-value"><?php echo number_format($product['produced_quantity']); ?></span>
                                </div>
                            </div>
                            <?php if ($product['not_in_production']): ?>
                                <p class="load-prod-warn" data-prod-warn>
                                    No production recorded and no finished-goods stock for this day.
                                    If the product was baked or pulled from elsewhere, enter the pickup quantity anyway.
                                </p>
                            <?php else: ?>
                                <p class="load-prod-warn is-hidden" data-prod-warn hidden></p>
                            <?php endif; ?>
                            <?php if ($product['shortage_quantity'] > 0): ?>
                                <p class="load-shortage" data-shortage>
                                    Warehouse may be short <?php echo number_format($product['shortage_quantity']); ?> unit(s) to cover remaining need
                                    (need <?php echo number_format($product['remaining_quantity']); ?> more; <?php echo number_format($product['available_quantity']); ?> available in FG).
                                    You can still save if the driver loaded the product.
                                </p>
                            <?php else: ?>
                                <p class="load-shortage is-hidden" data-shortage hidden></p>
                            <?php endif; ?>
                            <div class="load-pickup-row">
                                <label class="load-pickup-label">
                                    Pickup quantity
                                    <input type="number"
                                           min="0"
                                           step="1"
                                           inputmode="numeric"
                                           name="load[<?php echo $pid; ?>]"
                                           value="<?php echo (int)$product['default_pickup']; ?>"
                                           data-pickup-input
                                           aria-label="Pickup quantity for <?php echo htmlspecialchars($product['name']); ?>">
                                </label>
                                <input type="hidden" name="prev_load[<?php echo $pid; ?>]" value="<?php echo (int)$product['loaded_quantity']; ?>">
                                <div class="load-pickup-actions">
                                    <?php if ((int)$product['required_quantity'] > 0): ?>
                                        <button type="button" class="btn btn-outline load-btn-need" data-fill-need
                                            title="Set pickup to full order need (<?php echo (int)$product['required_quantity']; ?>)">
                                            Fill to need
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($product['loaded_quantity'] > 0): ?>
                                        <button type="button" class="btn btn-outline load-btn-keep" data-reset-loaded title="Reset to last saved pickup">Reset to saved</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline load-btn-clear" data-clear-pickup title="Set pickup to zero">Clear</button>
                                </div>
                            </div>
                            <p class="load-delta-hint" data-delta-hint hidden></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($optionalProducts): ?>
                <details class="load-optional">
                    <summary>Optional stock from finished goods (<?php echo count($optionalProducts); ?> product(s) available)</summary>
                    <p class="load-optional-help">Not on this driver’s assigned orders. Enter a pickup quantity only for extras you want on the van; blank lines are not saved.</p>
                    <div class="load-product-list">
                        <?php foreach ($optionalProducts as $product):
                            $pid = (int)$product['product_id'];
                            ?>
                            <article class="load-product load-product-none" data-product-row data-optional-row
                                     data-product-id="<?php echo $pid; ?>"
                                     data-need="0"
                                     data-loaded="0"
                                     data-available="<?php echo (int)$product['available_quantity']; ?>"
                                     data-max="<?php echo (int)$product['max_loadable']; ?>">
                                <div class="load-product-name">
                                    <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                    <span class="load-line-badge">Optional</span>
                                </div>
                                <div class="load-qty-grid">
                                    <div class="load-qty">
                                        <span class="load-qty-label">Need</span>
                                        <span class="load-qty-value">0</span>
                                    </div>
                                    <div class="load-qty">
                                        <span class="load-qty-label">Loaded</span>
                                        <span class="load-qty-value">0</span>
                                    </div>
                                    <div class="load-qty">
                                        <span class="load-qty-label">FG available</span>
                                        <span class="load-qty-value"><?php echo number_format($product['available_quantity']); ?></span>
                                    </div>
                                </div>
                                <div class="load-pickup-row">
                                    <label class="load-pickup-label">
                                        Pickup quantity
                                        <input type="number"
                                               min="0"
                                               step="1"
                                               inputmode="numeric"
                                               value=""
                                               placeholder="0"
                                               data-pickup-input
                                               data-optional-input
                                               data-product-key="<?php echo $pid; ?>"
                                               aria-label="Optional pickup for <?php echo htmlspecialchars($product['name']); ?>">
                                    </label>
                                    <input type="hidden" value="0" data-optional-prev>
                                </div>
                                <p class="load-delta-hint" data-delta-hint hidden></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?php endif; ?>
                <?php endif; ?>

                <label class="load-notes">Load note (optional)
                    <input name="notes" maxlength="500" value="<?php echo htmlspecialchars($sheet['notes']); ?>" placeholder="e.g. Added 2 extra country loaves; shorted baguettes pending bake">
                </label>
                <div class="load-submit-row">
                    <button class="btn btn-success" type="submit" data-save-btn>Save pickup</button>
                    <span class="load-submit-help">Pickup quantities are saved as entered. Available finished-goods stock is reserved where possible; any excess is recorded as a confirmed load override.</span>
                </div>
            </form>
        </section>
    <?php endforeach; ?>

    <aside class="load-caveats">
        <h3>Loading tips</h3>
        <p>
            <strong>Fill to need</strong> sets pickup to the full order amount for that product.
            You can type any number to record what was actually loaded — even if production or warehouse stock looks short.
            Warnings are informational only; saving always records what you enter.
            Assigned orders advance toward <em>out for delivery</em> when you save.
        </p>
    </aside>
</main>
<style>
.load-page{--load-ink:var(--sf-text,#24312b);--load-muted:var(--sf-text-muted,#5f6f67);--load-line:var(--sf-border,#d7e0da);--load-bg:var(--sf-bg,#f4f7f5);--load-ok:var(--sf-success,#1f6b3a);--load-warn:var(--sf-warning,#8a5a12);--load-bad:var(--sf-danger,#9b2525);--load-ready-bg:var(--sf-success-bg,#e7f6ea);--load-partial-bg:var(--sf-warning-bg,#fff6e5);--load-need-bg:var(--sf-info-bg,#eef2ff);--load-bad-bg:var(--sf-danger-bg,#fdecec);margin-bottom:40px}
.load-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:24px 0 14px}
.load-heading h1{margin:0;color:var(--load-ink)}
.load-heading p,.load-legend,.load-ready-detail,.load-submit-help,.load-caveats p{color:var(--load-muted);margin:6px 0 0;line-height:1.45}
.load-heading-actions{display:flex;gap:8px;flex-wrap:wrap}
.load-selector{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:var(--load-bg);border-radius:8px;border:1px solid var(--load-line)}
.load-selector label,.load-notes,.load-pickup-label{display:flex;flex-direction:column;gap:5px;font-weight:600;color:var(--load-ink)}
.load-selector input,.load-selector select,.load-notes input,.load-pickup-label input{padding:8px;border:1px solid #cbd4cf;border-radius:5px;font:inherit}
.load-day-summary{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;margin:16px 0 8px;padding:12px 14px;background:linear-gradient(135deg,#eef6f0,#f7faf8);border-left:4px solid #2e7d4b;border-radius:0 8px 8px 0}
.load-day-summary-main{display:flex;flex-direction:column;gap:2px}
.load-day-pills{display:flex;gap:8px;flex-wrap:wrap}
.load-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.86rem;font-weight:700;background:#fff;border:1px solid var(--load-line)}
.load-pill.ready{color:var(--load-ok);border-color:#b9dfc4;background:var(--load-ready-bg)}
.load-pill.partial{color:var(--load-warn);border-color:#efd7a8;background:var(--load-partial-bg)}
.load-pill.needs{color:#31407a;border-color:#c9d2f5;background:var(--load-need-bg)}
.load-pill.blocked,.load-pill.remain{color:var(--load-bad);border-color:#efc2c2;background:var(--load-bad-bg)}
.load-legend{font-size:.92rem;margin:0 0 18px}
.load-driver{margin:0 0 22px;padding:16px;border:1px solid var(--load-line);border-radius:10px;background:#fff;box-shadow:0 1px 0 rgba(36,49,43,.04)}
.load-driver.is-saved{outline:2px solid #7cbc8d;outline-offset:1px}
.load-driver-head{margin-bottom:10px}
.load-driver-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.load-driver-title h2{margin:0;font-size:1.35rem;color:var(--load-ink)}
.load-ready{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:.8rem;font-weight:800;letter-spacing:.02em;text-transform:uppercase}
.load-ready-ready{background:var(--load-ready-bg);color:var(--load-ok)}
.load-ready-partial{background:var(--load-partial-bg);color:var(--load-warn)}
.load-ready-needs_loading{background:var(--load-need-bg);color:#31407a}
.load-ready-not_assigned{background:#eef1ef;color:#5a675f}
.load-driver-meta{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;color:var(--load-muted);font-size:.95rem}
.load-meta-warn{color:var(--load-warn);font-weight:700}
.load-meta-danger{color:var(--load-bad);font-weight:700}
.load-stops{margin:10px 0 14px;border:1px solid var(--load-line);border-radius:8px;background:var(--load-bg)}
.load-stops summary{cursor:pointer;padding:10px 12px;font-weight:700;color:var(--load-ink)}
.load-stops ol{margin:0;padding:0 12px 12px 32px}
.load-stops li{margin:6px 0;color:var(--load-ink)}
.load-stop-zone,.load-stop-status{margin-left:8px;color:var(--load-muted);font-size:.9rem}
.load-product-list{display:flex;flex-direction:column;gap:12px;margin:12px 0}
.load-product{padding:12px;border:1px solid var(--load-line);border-radius:8px;background:#fbfcfb}
.load-product-shortage{border-color:#e8b0b0;background:#fff8f8}
.load-product-not_produced{border-color:#d9c4a8;background:#fffaf5}
.load-product-remaining{border-color:#efd7a8;background:#fffdf8}
.load-product-ready{border-color:#b9dfc4;background:#f7fbf8}
.load-product-name{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.load-product-name strong{font-size:1.05rem;color:var(--load-ink)}
.load-line-badge{font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.03em;color:var(--load-muted)}
.load-product-shortage .load-line-badge{color:var(--load-bad)}
.load-product-not_produced .load-line-badge{color:#8a5a12}
.load-product-remaining .load-line-badge{color:var(--load-warn)}
.load-product-ready .load-line-badge{color:var(--load-ok)}
.load-qty-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.load-qty{display:flex;flex-direction:column;gap:2px;padding:8px;background:#fff;border:1px solid #e7eeea;border-radius:6px}
.load-qty-label{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--load-muted);font-weight:700}
.load-qty-value{font-size:1.2rem;font-weight:800;color:var(--load-ink);line-height:1.2}
.load-qty.is-remain .load-qty-value{color:var(--load-warn)}
.load-qty.is-ok .load-qty-value{color:var(--load-ok)}
.load-shortage{margin:8px 0 0;padding:8px 10px;border-radius:6px;background:var(--load-bad-bg);color:var(--load-bad);font-size:.9rem;font-weight:600}
.load-shortage.is-hidden{display:none}
.load-prod-warn{margin:8px 0 0;padding:8px 10px;border-radius:6px;background:var(--load-partial-bg);color:var(--load-warn);font-size:.9rem;font-weight:600}
.load-prod-warn.is-hidden{display:none}
.load-sheet-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:12px 0 4px;padding:10px 12px;background:var(--load-bg);border:1px solid var(--load-line);border-radius:8px}
.load-sheet-help{margin:0;color:var(--load-muted);font-size:.92rem}
.load-bulk-actions{display:flex;gap:8px;flex-wrap:wrap}
.load-pickup-row{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin-top:10px}
.load-pickup-label input{width:120px}
.load-pickup-actions{display:flex;gap:8px;flex-wrap:wrap}
.load-delta-hint{margin:8px 0 0;padding:8px 10px;border-radius:6px;font-size:.9rem;font-weight:600}
.load-delta-hint.is-add{background:#eef6f0;color:var(--load-ok)}
.load-delta-hint.is-return{background:#fff6e5;color:var(--load-warn)}
.load-delta-hint.is-warn{background:var(--load-partial-bg);color:var(--load-warn)}
.load-notes{max-width:640px;margin:16px 0}
.load-submit-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.load-submit-help{max-width:420px;font-size:.88rem}
.load-notice{padding:11px 14px;border-radius:6px;margin:12px 0}
.load-notice.success{background:#e7f6ea;color:#1d6534}
.load-notice.error{background:#fdecec;color:#9b2525}
.load-notice.warn{background:#fff6e5;color:#8a5a12}
.load-notice.warn a{color:#6a4308;font-weight:800}
.load-empty,.load-empty-inline{padding:18px;background:var(--load-bg);border-radius:8px;color:var(--load-muted)}
.load-optional{margin:14px 0;border:1px dashed #c5d0c9;border-radius:8px;background:#fbfcfb}
.load-optional summary{cursor:pointer;padding:10px 12px;font-weight:700;color:var(--load-ink)}
.load-optional-help{margin:0 12px 10px;color:var(--load-muted);font-size:.9rem}
.load-optional .load-product-list{padding:0 12px 12px}
.load-caveats{margin-top:28px;padding:14px 16px;border-top:1px solid var(--load-line)}
.load-caveats h3{margin:0 0 6px;font-size:1rem;color:var(--load-ink)}
.load-sheet.is-saving{opacity:.72;pointer-events:none}
@media(max-width:820px){
    .load-heading{flex-direction:column}
    .load-heading-actions .btn{width:100%;text-align:center}
    .load-qty-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .load-day-summary{flex-direction:column;align-items:flex-start}
}
</style>
<script>
(function () {
    function parseIntSafe(value) {
        var n = parseInt(String(value), 10);
        return Number.isFinite(n) ? n : 0;
    }

    function updateRow(row) {
        var input = row.querySelector('[data-pickup-input]');
        var hint = row.querySelector('[data-delta-hint]');
        var remainingDisplay = row.querySelector('[data-remaining-display]');
        if (!input) return;

        var need = parseIntSafe(row.getAttribute('data-need'));
        var savedLoaded = parseIntSafe(row.getAttribute('data-loaded'));
        var available = parseIntSafe(row.getAttribute('data-available'));
        var pickup = parseIntSafe(input.value);
        if (input.value !== '' && pickup < 0) {
            input.value = '0';
            pickup = 0;
        }

        var remaining = Math.max(0, need - pickup);
        if (remainingDisplay) {
            remainingDisplay.textContent = remaining > 0 ? String(remaining) : '0 · Ready';
            remainingDisplay.parentElement.classList.toggle('is-remain', remaining > 0);
            remainingDisplay.parentElement.classList.toggle('is-ok', remaining <= 0);
        }

        if (hint) {
            var delta = pickup - savedLoaded;
            hint.hidden = delta === 0;
            hint.classList.remove('is-add', 'is-return', 'is-warn');
            if (delta > 0) {
                var projectedAdd = delta;
                var short = Math.max(0, projectedAdd - available);
                if (short > 0) {
                    hint.hidden = false;
                    hint.classList.add('is-warn');
                    hint.textContent = 'Adding ' + projectedAdd + ' unit(s). Only ' + available + ' in finished goods — '
                        + short + ' will be saved as a confirmed load override.';
                } else {
                    hint.classList.add('is-add');
                    hint.textContent = 'Adding ' + delta + ' unit(s) from finished goods.';
                }
            } else if (delta < 0) {
                hint.classList.add('is-return');
                hint.textContent = 'Reducing by ' + (-delta) + ' unit(s) returns that stock to finished goods.';
            }
        }

        var shortageEl = row.querySelector('[data-shortage]');
        if (shortageEl) {
            var stillNeed = Math.max(0, need - savedLoaded);
            var projectedAdd = Math.max(0, pickup - savedLoaded);
            var short = Math.max(0, projectedAdd - available);
            if (short > 0) {
                shortageEl.hidden = false;
                shortageEl.classList.remove('is-hidden');
                shortageEl.textContent = 'Warehouse may be short ' + short + ' unit(s) for this change (adding ' + projectedAdd + '; ' + available + ' available). You can still save if loaded.';
            } else if (stillNeed > available && pickup < need) {
                shortageEl.hidden = false;
                shortageEl.classList.remove('is-hidden');
                shortageEl.textContent = 'Warehouse may be short ' + (stillNeed - available) + ' unit(s) to finish this line (need ' + stillNeed + ' more; ' + available + ' available). You can still save if loaded.';
            } else if (!(stillNeed > available && pickup < need)) {
                shortageEl.hidden = true;
                shortageEl.classList.add('is-hidden');
                shortageEl.textContent = '';
            }
        }
    }

    function setPickup(row, qty) {
        var inputEl = row.querySelector('[data-pickup-input]');
        if (!inputEl) return;
        inputEl.value = String(Math.max(0, qty));
        updateRow(row);
    }

    document.querySelectorAll('[data-product-row]').forEach(function (row) {
        var input = row.querySelector('[data-pickup-input]');
        if (input) {
            input.addEventListener('input', function () { updateRow(row); });
            input.addEventListener('change', function () { updateRow(row); });
            updateRow(row);
        }

        var fillNeed = row.querySelector('[data-fill-need]');
        if (fillNeed) {
            fillNeed.addEventListener('click', function () {
                setPickup(row, parseIntSafe(row.getAttribute('data-need')));
                input.focus();
            });
        }

        var resetLoaded = row.querySelector('[data-reset-loaded]');
        if (resetLoaded) {
            resetLoaded.addEventListener('click', function () {
                setPickup(row, parseIntSafe(row.getAttribute('data-loaded')));
            });
        }

        var clearPickup = row.querySelector('[data-clear-pickup]');
        if (clearPickup) {
            clearPickup.addEventListener('click', function () {
                setPickup(row, 0);
                input.focus();
            });
        }
    });

    document.querySelectorAll('[data-load-form]').forEach(function (form) {
        var loadAllBtn = form.querySelector('[data-load-all-need]');
        if (loadAllBtn) {
            loadAllBtn.addEventListener('click', function () {
                form.querySelectorAll('[data-product-row]:not([data-optional-row])').forEach(function (row) {
                    var need = parseIntSafe(row.getAttribute('data-need'));
                    if (need > 0) {
                        setPickup(row, need);
                    }
                });
            });
        }

        var resetAllBtn = form.querySelector('[data-reset-all-saved]');
        if (resetAllBtn) {
            resetAllBtn.addEventListener('click', function () {
                form.querySelectorAll('[data-product-row]:not([data-optional-row])').forEach(function (row) {
                    setPickup(row, parseIntSafe(row.getAttribute('data-loaded')));
                });
            });
        }

        form.addEventListener('submit', function (event) {
            var invalid = false;
            var overrideLines = [];

            form.querySelectorAll('[data-optional-input]').forEach(function (input) {
                var raw = String(input.value || '').trim();
                var key = input.getAttribute('data-product-key');
                input.removeAttribute('name');
                var prev = input.closest('[data-product-row]')
                    ? input.closest('[data-product-row]').querySelector('[data-optional-prev]')
                    : null;
                if (prev) prev.removeAttribute('name');
                if (raw === '') return;
                if (!/^\d+$/.test(raw)) {
                    invalid = true;
                    input.focus();
                    return;
                }
                var qty = parseIntSafe(raw);
                if (qty <= 0) return;
                input.setAttribute('name', 'load[' + key + ']');
                if (prev) {
                    prev.setAttribute('name', 'prev_load[' + key + ']');
                    prev.value = '0';
                }
            });

            form.querySelectorAll('[data-pickup-input]:not([data-optional-input])').forEach(function (input) {
                var value = input.value;
                if (value === '' || !/^\d+$/.test(value)) {
                    invalid = true;
                    input.focus();
                    return;
                }
                var row = input.closest('[data-product-row]');
                if (!row) return;
                var savedLoaded = parseIntSafe(row.getAttribute('data-loaded'));
                var available = parseIntSafe(row.getAttribute('data-available'));
                var pickup = parseIntSafe(value);
                var add = Math.max(0, pickup - savedLoaded);
                if (add > available) {
                    var nameEl = row.querySelector('.load-product-name strong');
                    overrideLines.push((nameEl ? nameEl.textContent : 'Product') + ': +' + add + ' (' + available + ' in FG)');
                }
            });

            if (invalid) {
                event.preventDefault();
                window.alert('Enter whole-number pickup quantities (0 or more) for every product on the route.');
                return;
            }

            if (overrideLines.length > 0) {
                var notProduced = form.querySelectorAll('[data-not-produced="1"]').length > 0;
                var msg = notProduced
                    ? <?php echo json_encode(bakery_t('driver_load.override_confirm_no_production'), JSON_UNESCAPED_UNICODE); ?>
                    : <?php echo json_encode(bakery_t('driver_load.override_confirm'), JSON_UNESCAPED_UNICODE); ?>;
                msg += '\n\n' + overrideLines.join('\n');
                if (!window.confirm(msg)) {
                    event.preventDefault();
                    return;
                }
            }

            if (form.classList.contains('is-saving')) {
                event.preventDefault();
                return;
            }
            form.classList.add('is-saving');
            var btn = form.querySelector('[data-save-btn]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Saving…';
            }
        });
    });

    var saved = document.querySelector('.load-driver.is-saved');
    if (saved) {
        saved.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
})();
</script>
<?php require_once 'includes/footer.php'; ?>
