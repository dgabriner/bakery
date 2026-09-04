<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';
require_once 'includes/product_inventory.php';
require_once 'includes/operational_timeline.php';
require_once 'includes/operational_exceptions.php';

$selectedDate = $_GET['date'] ?? $_POST['delivery_date'] ?? date('Y-m-d');
try {
    $selectedDate = bakery_inventory_validate_date((string)$selectedDate);
} catch (Throwable $e) {
    $selectedDate = date('Y-m-d');
}

$focusDriverId = (int)($_GET['driver_id'] ?? $_POST['driver_id'] ?? 0);
$attentionOpen = (string)($_GET['attention'] ?? '') === 'open';
$returnTarget = bakery_ops_return_resolve($_GET['return'] ?? null, $selectedDate);
$attentionLabel = $attentionOpen ? 'Showing routes that still need closeout' : '';
$notice = '';
$error = '';

$currentUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
$isDriverOnly = $currentUser && bakery_is_driver_route_role($currentUser['role_slug'] ?? '');
$driverScopedId = 0;
if ($isDriverOnly) {
    $driverScopedId = bakery_route_worker_driver_id($db, $currentUser, $selectedDate);
    if ($driverScopedId <= 0 && function_exists('bakery_get_selected_driver_id')) {
        $driverScopedId = (int)bakery_get_selected_driver_id();
    }
    if ($driverScopedId > 0) {
        $focusDriverId = $driverScopedId;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $postDriverId = (int)($_POST['driver_id'] ?? 0);
    if ($isDriverOnly && $driverScopedId > 0) {
        $postDriverId = $driverScopedId;
    }
    try {
        if (!bakery_inventory_closeout_ready($db)) {
            throw new RuntimeException('Route closeout is not installed. Run the database migrations first.');
        }
        if ($action === 'close_route') {
            $rawLines = $_POST['line'] ?? [];
            if (!is_array($rawLines)) {
                $rawLines = [];
            }
            $lines = [];
            foreach ($rawLines as $productId => $vals) {
                if (!is_array($vals)) {
                    continue;
                }
                $lines[(int)$productId] = [
                    'returned' => $vals['returned'] ?? 0,
                    'wasted' => $vals['wasted'] ?? 0,
                ];
            }
            bakery_inventory_reconcile_driver_load(
                $db,
                $selectedDate,
                $postDriverId,
                $lines,
                trim((string)($_POST['notes'] ?? ''))
            );
            $returnKey = trim((string)($_POST['return'] ?? $_GET['return'] ?? ''));
            if ($returnKey === 'manager' && empty($_POST['stay'])) {
                $dest = (defined('BASE_URL') ? BASE_URL : '') . 'manager.php?date=' . rawurlencode($selectedDate) . '&view=routes';
                header('Location: ' . $dest);
                exit;
            }
            $notice = 'Route closed. Loaded units are reconciled as delivered, returned, waste, and door credits.';
            $focusDriverId = $postDriverId;
        } elseif ($action === 'reopen_route') {
            if ($isDriverOnly) {
                throw new RuntimeException('Only a manager can reopen a closed route.');
            }
            bakery_inventory_reopen_driver_closeout($db, $selectedDate, $postDriverId);
            $notice = 'Route reopened. Closeout ledger movements were reversed; review pickup and close again when ready.';
            $focusDriverId = $postDriverId;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $focusDriverId = $postDriverId > 0 ? $postDriverId : $focusDriverId;
    }
}

$inventoryReady = bakery_inventory_ready($db);
$closeoutReady = bakery_inventory_closeout_ready($db);
$board = ($inventoryReady && $closeoutReady)
    ? bakery_inventory_closeout_board($db, $selectedDate)
    : [];

if ($isDriverOnly && $driverScopedId > 0) {
    $board = array_values(array_filter($board, static function ($row) use ($driverScopedId) {
        return (int)$row['driver_id'] === $driverScopedId;
    }));
}

if ($attentionOpen) {
    $board = array_values(array_filter($board, static function ($row) {
        return !empty($row['needs_closeout']);
    }));
}

$stats = [
    'open' => 0,
    'closed' => 0,
    'drivers' => count($board),
];
foreach ($board as $row) {
    if (!empty($row['is_reconciled'])) {
        $stats['closed']++;
    } elseif (!empty($row['needs_closeout'])) {
        $stats['open']++;
    }
}

$page_title = bakery_t('page.route_closeout');
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<p class="manager-desktop-only-hint"><?php bakery_te('manager_phone.desktop_use_manager'); ?>
  <a href="<?php echo htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'manager.php?date=' . rawurlencode($selectedDate) . '&view=routes', ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('nav.manager_today'); ?></a>
</p>
<main class="closeout-page container manager-desktop-only">
    <?php echo bakery_ops_render_return_banner($returnTarget, $attentionLabel); ?>
    <div class="closeout-heading">
        <div>
            <h1><?php bakery_te('page.route_closeout'); ?></h1>
            <p>Per-driver closeout for the operating date: loaded = delivered + returned + waste + door credits. Writes the finished-goods ledger.</p>
        </div>
        <div class="closeout-heading-actions">
            <a class="btn btn-outline" href="driver_load.php?date=<?php echo urlencode($selectedDate); ?>">Pickup loads</a>
            <a class="btn btn-outline" href="daily_run.php?date=<?php echo urlencode($selectedDate); ?>#deliver">Daily Run</a>
        </div>
    </div>

    <?php if ($notice): ?><div class="closeout-notice success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="closeout-notice error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <form method="get" class="closeout-selector">
        <label>Operating date
            <input type="date" name="date" value="<?php echo htmlspecialchars($selectedDate); ?>">
        </label>
        <?php if (!$isDriverOnly): ?>
        <label>Driver
            <select name="driver_id">
                <option value="0">All drivers with work</option>
                <?php foreach ($board as $row): ?>
                    <option value="<?php echo (int)$row['driver_id']; ?>" <?php echo $focusDriverId === (int)$row['driver_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($row['driver_name']); ?>
                        <?php echo !empty($row['is_reconciled']) ? ' (closed)' : (!empty($row['needs_closeout']) ? ' (open)' : ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="closeout-check">
            <input type="checkbox" name="attention" value="open" <?php echo $attentionOpen ? 'checked' : ''; ?>>
            Open routes only
        </label>
        <?php endif; ?>
        <?php if ($returnTarget): ?>
            <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnTarget['key']); ?>">
        <?php endif; ?>
        <button class="btn btn-outline" type="submit">View</button>
    </form>

    <?php if (!$inventoryReady): ?>
        <div class="closeout-notice error">Finished-goods inventory is not installed. Run scripts/run_migrations.php first.</div>
    <?php elseif (!$closeoutReady): ?>
        <div class="closeout-notice error">Route closeout migration is not installed. Run scripts/run_migrations.php (037_route_closeout).</div>
    <?php else: ?>
        <div class="closeout-day-summary">
            <div class="closeout-day-summary-main">
                <strong><?php echo date('l, M j', strtotime($selectedDate)); ?></strong>
                <span>Route closeout · loaded vs delivered vs returned vs waste vs door credits</span>
            </div>
            <div class="closeout-day-pills">
                <span class="closeout-pill"><?php echo (int)$stats['drivers']; ?> driver<?php echo $stats['drivers'] === 1 ? '' : 's'; ?></span>
                <span class="closeout-pill open"><?php echo (int)$stats['open']; ?> open</span>
                <span class="closeout-pill closed"><?php echo (int)$stats['closed']; ?> closed</span>
            </div>
        </div>

        <?php
        $shown = 0;
        foreach ($board as $sheet):
            $driverId = (int)$sheet['driver_id'];
            if ($focusDriverId > 0 && $driverId !== $focusDriverId) {
                continue;
            }
            $shown++;
            $lines = bakery_inventory_closeout_lines($db, $selectedDate, $driverId);
            $isClosed = !empty($sheet['is_reconciled']);
            $openStops = (int)$sheet['open_stops'];
            $hasLoad = !empty($sheet['load_id']);
            $hasShortLoad = false;
            foreach ($lines as $lineCheck) {
                $checkDelivered = (int)$lineCheck['delivered_quantity'];
                $checkCredits = (int)($lineCheck['credits_quantity'] ?? 0);
                if ((int)$lineCheck['loaded_quantity'] < ($checkDelivered + $checkCredits)) {
                    $hasShortLoad = true;
                    break;
                }
            }
            $canClose = !$isClosed && $hasLoad && $openStops === 0 && $lines !== [] && !$hasShortLoad;
            $statusKey = $isClosed ? 'closed' : ($openStops > 0 ? 'in_progress' : (!empty($sheet['needs_closeout']) ? 'open' : 'idle'));
            $statusLabel = [
                'closed' => 'Closed',
                'in_progress' => 'Stops open',
                'open' => 'Needs closeout',
                'idle' => 'No closeout needed',
            ][$statusKey];
        ?>
        <section class="closeout-driver" id="driver-<?php echo $driverId; ?>">
            <div class="closeout-driver-head">
                <div class="closeout-driver-title">
                    <h2><?php echo htmlspecialchars($sheet['driver_name']); ?></h2>
                    <span class="closeout-ready closeout-ready-<?php echo htmlspecialchars($statusKey); ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                </div>
                <div class="closeout-driver-meta">
                    <span><?php echo (int)$sheet['stop_count']; ?> stop<?php echo (int)$sheet['stop_count'] === 1 ? '' : 's'; ?></span>
                    <span><?php echo (int)$sheet['delivered_stops']; ?> delivered</span>
                    <?php if ($openStops > 0): ?>
                        <span class="closeout-meta-warn"><?php echo $openStops; ?> still open</span>
                    <?php endif; ?>
                    <?php if ((int)$sheet['failed_stops'] > 0): ?>
                        <span class="closeout-meta-warn"><?php echo (int)$sheet['failed_stops']; ?> failed</span>
                    <?php endif; ?>
                    <span><?php echo number_format((int)$sheet['loaded_units']); ?> loaded units</span>
                </div>
            </div>

            <?php if (!$hasLoad): ?>
                <div class="closeout-empty-inline">
                    No pickup load saved for this driver.
                    <a href="driver_load.php?date=<?php echo urlencode($selectedDate); ?>&amp;driver_id=<?php echo $driverId; ?>">Save pickup load</a>
                    before closing the route.
                </div>
            <?php elseif ($lines === []): ?>
                <div class="closeout-empty-inline">No product lines on this load.</div>
            <?php else: ?>
                <form method="post" class="closeout-sheet">
                    <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                    <input type="hidden" name="delivery_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                    <input type="hidden" name="driver_id" value="<?php echo $driverId; ?>">
                    <?php if ($returnTarget): ?>
                        <input type="hidden" name="return" value="<?php echo htmlspecialchars($returnTarget['key']); ?>">
                    <?php endif; ?>

                    <div class="closeout-table-wrap">
                        <table class="closeout-table">
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Loaded</th>
                                    <th>Delivered</th>
                                    <th><?php bakery_te('closeout.door_credits'); ?></th>
                                    <th>Returned</th>
                                    <th>Waste</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($lines as $line):
                                $pid = (int)$line['product_id'];
                                $loaded = (int)$line['loaded_quantity'];
                                $delivered = (int)$line['delivered_quantity'];
                                $credits = (int)($line['credits_quantity'] ?? 0);
                                $returned = (int)$line['returned_quantity'];
                                $wasted = (int)$line['wasted_quantity'];
                                $balance = $loaded - $delivered - $credits - $returned - $wasted;
                                $shortLoaded = $loaded < ($delivered + $credits);
                            ?>
                                <tr data-closeout-row
                                    data-loaded="<?php echo $loaded; ?>"
                                    data-delivered="<?php echo $delivered; ?>"
                                    data-credits="<?php echo $credits; ?>"
                                    class="<?php echo $shortLoaded ? 'is-short' : ''; ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($line['product_name']); ?></strong>
                                        <?php if ($shortLoaded && !$isClosed): ?>
                                            <div class="closeout-line-warn">Delivered exceeds loaded — correct the pickup load first.</div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($loaded); ?></td>
                                    <td><?php echo number_format($delivered); ?></td>
                                    <td><?php echo number_format($credits); ?></td>
                                    <td>
                                        <?php if ($isClosed || $shortLoaded): ?>
                                            <?php echo number_format($returned); ?>
                                        <?php else: ?>
                                            <input type="number" min="0" step="1" inputmode="numeric"
                                                   name="line[<?php echo $pid; ?>][returned]"
                                                   value="<?php echo $returned; ?>"
                                                   data-returned
                                                   aria-label="Returned <?php echo htmlspecialchars($line['product_name']); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isClosed || $shortLoaded): ?>
                                            <?php echo number_format($wasted); ?>
                                        <?php else: ?>
                                            <input type="number" min="0" step="1" inputmode="numeric"
                                                   name="line[<?php echo $pid; ?>][wasted]"
                                                   value="<?php echo $wasted; ?>"
                                                   data-wasted
                                                   aria-label="Waste <?php echo htmlspecialchars($line['product_name']); ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td data-balance class="<?php echo $balance === 0 ? 'is-ok' : 'is-bad'; ?>">
                                        <?php echo $balance; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <p class="closeout-help">
                        <?php bakery_te('closeout.balance_help'); ?>
                    </p>

                    <?php if (!$isClosed): ?>
                        <label class="closeout-notes">Closeout note (optional)
                            <input name="notes" maxlength="500" placeholder="e.g. 2 baguettes crushed; remainder returned to rack">
                        </label>
                        <div class="closeout-submit-row">
                            <button class="btn btn-success" type="submit" name="action" value="close_route"
                                <?php echo $canClose ? '' : 'disabled'; ?>>
                                Close route
                            </button>
                            <?php if ($openStops > 0): ?>
                                <span class="closeout-submit-help">Finish open stops before closing.</span>
                            <?php elseif (!$canClose): ?>
                                <span class="closeout-submit-help">Nothing to close yet.</span>
                            <?php else: ?>
                                <span class="closeout-submit-help">Posts return, waste, and delivery movements on the finished-goods ledger.</span>
                            <?php endif; ?>
                        </div>
                    <?php elseif (!$isDriverOnly): ?>
                        <div class="closeout-submit-row">
                            <button class="btn btn-outline" type="submit" name="action" value="reopen_route"
                                    onclick="return confirm('Reopen this route and reverse closeout inventory movements?');">
                                Reopen closeout
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>

        <?php if ($shown === 0): ?>
            <div class="closeout-empty">No drivers need route closeout for this date.</div>
        <?php endif; ?>
    <?php endif; ?>
</main>
<style>
.closeout-page{--ink:var(--sf-text,#24312b);--muted:var(--sf-text-muted,#5f6f67);--line:var(--sf-border,#d7e0da);--bg:var(--sf-bg,#f4f7f5);--ok:var(--sf-success,#1f6b3a);--warn:var(--sf-warning,#8a5a12);--bad:var(--sf-danger,#9b2525);margin-bottom:40px}
.closeout-heading{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin:24px 0 14px}
.closeout-heading h1{margin:0;color:var(--ink)}
.closeout-heading p,.closeout-help,.closeout-submit-help{color:var(--muted);margin:6px 0 0;line-height:1.45}
.closeout-heading-actions{display:flex;gap:8px;flex-wrap:wrap}
.closeout-selector{display:flex;gap:10px;align-items:end;flex-wrap:wrap;padding:14px;background:var(--bg);border-radius:8px;border:1px solid var(--line)}
.closeout-selector label,.closeout-notes{display:flex;flex-direction:column;gap:5px;font-weight:600;color:var(--ink)}
.closeout-selector input,.closeout-selector select,.closeout-notes input,.closeout-table input{padding:8px;border:1px solid #cbd4cf;border-radius:5px;font:inherit}
.closeout-check{flex-direction:row!important;align-items:center;gap:8px!important;font-weight:600}
.closeout-day-summary{display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;margin:16px 0 8px;padding:12px 14px;background:linear-gradient(135deg,#eef6f0,#f7faf8);border-left:4px solid #2e7d4b;border-radius:0 8px 8px 0}
.closeout-day-summary-main{display:flex;flex-direction:column;gap:2px}
.closeout-day-pills{display:flex;gap:8px;flex-wrap:wrap}
.closeout-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:.86rem;font-weight:700;background:#fff;border:1px solid var(--line)}
.closeout-pill.open{color:var(--warn);border-color:#efd7a8;background:#fff6e5}
.closeout-pill.closed{color:var(--ok);border-color:#b9dfc4;background:#e7f6ea}
.closeout-driver{margin:0 0 22px;padding:16px;border:1px solid var(--line);border-radius:10px;background:#fff}
.closeout-driver-title{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.closeout-driver-title h2{margin:0;font-size:1.35rem;color:var(--ink)}
.closeout-ready{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:.8rem;font-weight:800;letter-spacing:.02em;text-transform:uppercase}
.closeout-ready-closed{background:#e7f6ea;color:var(--ok)}
.closeout-ready-open{background:#fff6e5;color:var(--warn)}
.closeout-ready-in_progress{background:#eef2ff;color:#31407a}
.closeout-ready-idle{background:#eef1ef;color:#5a675f}
.closeout-driver-meta{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px;color:var(--muted);font-size:.95rem}
.closeout-meta-warn{color:var(--warn);font-weight:700}
.closeout-table-wrap{overflow-x:auto;margin:12px 0}
.closeout-table{width:100%;border-collapse:collapse;min-width:560px}
.closeout-table th,.closeout-table td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
.closeout-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted)}
.closeout-table input{width:88px}
.closeout-table td[data-balance]{font-weight:800}
.closeout-table td[data-balance].is-ok{color:var(--ok)}
.closeout-table td[data-balance].is-bad{color:var(--bad)}
.closeout-table tr.is-short{background:#fff8f8}
.closeout-line-warn{margin-top:4px;color:var(--bad);font-size:.85rem;font-weight:600}
.closeout-notes{max-width:640px;margin:12px 0}
.closeout-submit-row{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:8px}
.closeout-notice{padding:11px 14px;border-radius:6px;margin:12px 0}
.closeout-notice.success{background:#e7f6ea;color:#1d6534}
.closeout-notice.error{background:#fdecec;color:#9b2525}
.closeout-empty,.closeout-empty-inline{padding:18px;background:var(--bg);border-radius:8px;color:var(--muted)}
@media(max-width:820px){
    .closeout-heading{flex-direction:column}
    .closeout-heading-actions .btn{width:100%;text-align:center}
}
</style>
<script>
(function () {
    function refreshRow(row) {
        var loaded = parseInt(row.getAttribute('data-loaded') || '0', 10) || 0;
        var delivered = parseInt(row.getAttribute('data-delivered') || '0', 10) || 0;
        var credits = parseInt(row.getAttribute('data-credits') || '0', 10) || 0;
        var returnedInput = row.querySelector('[data-returned]');
        var wastedInput = row.querySelector('[data-wasted]');
        if (!returnedInput || !wastedInput) return;
        var returned = parseInt(returnedInput.value || '0', 10);
        var wasted = parseInt(wastedInput.value || '0', 10);
        if (isNaN(returned)) returned = 0;
        if (isNaN(wasted)) wasted = 0;
        var balance = loaded - delivered - credits - returned - wasted;
        var cell = row.querySelector('[data-balance]');
        if (!cell) return;
        cell.textContent = String(balance);
        cell.classList.toggle('is-ok', balance === 0);
        cell.classList.toggle('is-bad', balance !== 0);
    }
    document.querySelectorAll('[data-closeout-row]').forEach(function (row) {
        row.querySelectorAll('input').forEach(function (input) {
            input.addEventListener('input', function () { refreshRow(row); });
        });
        refreshRow(row);
    });
})();
</script>
<?php require_once 'includes/footer.php'; ?>
