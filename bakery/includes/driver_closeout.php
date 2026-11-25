<?php
/**
 * Driver-mode route closeout.
 *
 * The summary is read-only assembly. Saving still posts to route_closeout.php,
 * which calls bakery_inventory_reconcile_driver_load. This file does not
 * post inventory.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @return array{
 *   driver_id:int,
 *   date:string,
 *   delivered_stops:int,
 *   problem_stops:list<array{customer_name:string,reason:string}>,
 *   pieces_delivered:int,
 *   credits:int,
 *   returns:int,
 *   cash_collected:?float,
 *   open_stops:int,
 *   open_stop_names:list<string>,
 *   lines:list<array<string,mixed>>,
 *   has_load:bool,
 *   is_closed:bool,
 *   closeout_ready:bool
 * }
 */
function bakery_driver_closeout_empty(int $driverId, string $date): array
{
    return [
        'driver_id' => $driverId,
        'date' => $date,
        'delivered_stops' => 0,
        'problem_stops' => [],
        'pieces_delivered' => 0,
        'credits' => 0,
        'returns' => 0,
        'cash_collected' => null,
        'open_stops' => 0,
        'open_stop_names' => [],
        'lines' => [],
        'has_load' => false,
        'is_closed' => false,
        'closeout_ready' => false,
    ];
}

function bakery_driver_closeout_href(string $date, int $driverId): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $query = ['date' => $date, 'closeout' => '1'];
    if ($driverId > 0) {
        $query['driver_id'] = $driverId;
    }
    return $base . 'driver.php?' . http_build_query($query) . '#driver-closeout';
}

function bakery_driver_closeout_reason_label(string $code, string $note): string
{
    $code = strtolower(trim($code));
    $label = '';
    if ($code !== '' && function_exists('bakery_exception_desk_reason_label')) {
        $label = bakery_exception_desk_reason_label($code);
    } elseif ($code !== '') {
        $label = str_replace('_', ' ', $code);
    }
    $note = trim($note);
    if ($label === '' && $note === '') {
        return function_exists('bakery_t') ? bakery_t('driver.closeout_reason_missing') : 'Problem reported';
    }
    if ($note !== '' && strcasecmp($note, $label) !== 0) {
        return $label === '' ? $note : ($label . '. ' . $note);
    }
    return $label !== '' ? $label : $note;
}

/**
 * Stops, pieces, credits, returns, and cash for one driver's date.
 * Open-stop count uses bakery_inventory_driver_open_stops, the same gate
 * route closeout enforces.
 */
function bakery_driver_closeout_summary(PDO $db, string $date, int $driverId): array
{
    $summary = bakery_driver_closeout_empty($driverId, $date);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if ($driverId <= 0 || !$parsed || $parsed->format('Y-m-d') !== $date) {
        return $summary;
    }

    $summary['closeout_ready'] = function_exists('bakery_inventory_closeout_ready')
        && bakery_inventory_closeout_ready($db);

    if (function_exists('table_exists') && table_exists($db, 'driver_loads')) {
        $loadStmt = $db->prepare(
            'SELECT status FROM driver_loads WHERE driver_id = ? AND delivery_date = ? LIMIT 1'
        );
        $loadStmt->execute([$driverId, $date]);
        $loadStatus = $loadStmt->fetchColumn();
        if ($loadStatus !== false) {
            $summary['has_load'] = true;
            $summary['is_closed'] = (string)$loadStatus === 'reconciled';
        }
    }

    if (function_exists('bakery_inventory_driver_open_stops')) {
        $open = bakery_inventory_driver_open_stops($db, $date, $driverId);
        $summary['open_stops'] = (int)($open['open'] ?? 0);
    }

    $cashTracked = function_exists('column_exists') && column_exists($db, 'daily_orders', 'amount_collected');
    $pieceSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivered_pieces')
        ? 'do.delivered_pieces'
        : 'NULL AS delivered_pieces';
    $cashSql = $cashTracked ? 'do.amount_collected' : 'NULL AS amount_collected';
    $creditSql = function_exists('column_exists') && column_exists($db, 'daily_orders', 'credits_taken_back')
        ? 'do.credits_taken_back'
        : 'NULL AS credits_taken_back';

    $stopStmt = $db->prepare(
        "SELECT doa.id AS assignment_id, doa.daily_order_id, doa.delivery_status, doa.route_order,
                c.name AS customer_name, {$pieceSql}, {$cashSql}, {$creditSql}
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         JOIN customers c ON c.id = do.customer_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ? AND doa.delivery_status <> 'cancelled'
         ORDER BY doa.route_order, c.name"
    );
    $stopStmt->execute([$driverId, $date]);
    $stops = $stopStmt->fetchAll(PDO::FETCH_ASSOC);

    $reasonsByAssignment = [];
    if (function_exists('table_exists') && table_exists($db, 'delivery_recovery_cases')) {
        $reasonStmt = $db->prepare(
            'SELECT failed_assignment_id, failure_reason, manager_note
             FROM delivery_recovery_cases
             WHERE delivery_date = ? AND original_driver_id = ?'
        );
        $reasonStmt->execute([$date, $driverId]);
        foreach ($reasonStmt->fetchAll(PDO::FETCH_ASSOC) as $reasonRow) {
            $reasonsByAssignment[(int)$reasonRow['failed_assignment_id']] = $reasonRow;
        }
    }

    $flagged = [];
    if (function_exists('bakery_delivery_empty_flagged_order_ids') && defined('BAKERY_OP_DELIVERY_EMPTY_FLAGGED')) {
        $flagged = bakery_delivery_empty_flagged_order_ids($db, $date);
    }

    $creditsFromOrders = 0;
    foreach ($stops as $stop) {
        $status = (string)($stop['delivery_status'] ?? '');
        $name = (string)($stop['customer_name'] ?? '');
        $orderId = (int)($stop['daily_order_id'] ?? 0);
        $assignmentId = (int)($stop['assignment_id'] ?? 0);
        $isFlagged = $orderId > 0 && isset($flagged[$orderId]);
        if ($status === 'delivered') {
            $summary['delivered_stops']++;
            if ($stop['delivered_pieces'] !== null && $stop['delivered_pieces'] !== '') {
                $summary['pieces_delivered'] += (int)$stop['delivered_pieces'];
            }
            if ($cashTracked && $stop['amount_collected'] !== null && $stop['amount_collected'] !== '') {
                $summary['cash_collected'] = round((float)($summary['cash_collected'] ?? 0) + (float)$stop['amount_collected'], 2);
            }
            if ($stop['credits_taken_back'] !== null && $stop['credits_taken_back'] !== '') {
                $creditsFromOrders += (int)$stop['credits_taken_back'];
            }
        } elseif ($status === 'pending' || $status === 'in_transit') {
            if ($name !== '') {
                $summary['open_stop_names'][] = $name;
            }
        }
        if ($status === 'failed' || $isFlagged) {
            $reasonRow = $reasonsByAssignment[$assignmentId] ?? null;
            $code = is_array($reasonRow) ? (string)($reasonRow['failure_reason'] ?? '') : '';
            $note = is_array($reasonRow) ? (string)($reasonRow['manager_note'] ?? '') : '';
            if ($code === '' && $note === '' && $isFlagged) {
                $note = function_exists('bakery_t') ? bakery_t('driver.closeout_office_flag') : 'Flagged for the office';
            }
            $summary['problem_stops'][] = [
                'customer_name' => $name,
                'reason' => bakery_driver_closeout_reason_label($code, $note),
            ];
        }
    }
    if ($cashTracked && $summary['cash_collected'] === null) {
        $summary['cash_collected'] = 0.0;
    }

    if (function_exists('bakery_inventory_ready') && bakery_inventory_ready($db)) {
        $summary['lines'] = bakery_inventory_closeout_lines($db, $date, $driverId);
    }
    $linePieces = 0;
    foreach ($summary['lines'] as $line) {
        $summary['credits'] += (int)($line['credits_quantity'] ?? 0);
        $summary['returns'] += (int)($line['returned_quantity'] ?? 0);
        $linePieces += (int)($line['delivered_quantity'] ?? 0);
    }
    if ($summary['credits'] === 0) {
        $summary['credits'] = $creditsFromOrders;
    }
    if ($summary['pieces_delivered'] === 0) {
        $summary['pieces_delivered'] = $linePieces;
    }

    return $summary;
}

/**
 * @param array{open?:bool,driver_only?:bool} $options
 */
function bakery_driver_closeout_render(PDO $db, string $date, int $driverId, array $options = []): void
{
    $openPanel = !empty($options['open']);
    $driverOnly = !empty($options['driver_only']);
    try {
        $summary = bakery_driver_closeout_summary($db, $date, $driverId);
    } catch (Throwable $e) {
        $summary = bakery_driver_closeout_empty($driverId, $date);
        $summary['error'] = function_exists('bakery_t')
            ? bakery_t('driver.closeout_not_ready')
            : 'Route closeout is not available on this phone yet.';
    }

    $h = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    $openStops = (int)$summary['open_stops'];
    $lines = $summary['lines'];
    $short = false;
    foreach ($lines as $line) {
        $loaded = (int)($line['loaded_quantity'] ?? 0);
        $delivered = (int)($line['delivered_quantity'] ?? 0);
        $credits = (int)($line['credits_quantity'] ?? 0);
        if ($loaded < ($delivered + $credits)) {
            $short = true;
            break;
        }
    }
    $canSubmit = !empty($summary['closeout_ready'])
        && !empty($summary['has_load'])
        && empty($summary['is_closed'])
        && $lines !== []
        && !$short
        && ($openStops === 0 || $driverOnly);
    $action = (defined('BASE_URL') ? BASE_URL : '') . 'route_closeout.php';
    $cash = $summary['cash_collected'];
    $cashLabel = $cash === null
        ? bakery_t('driver.closeout_cash_missing')
        : ('$' . number_format((float)$cash, 2));
    ?>
    <section class="driver-closeout" id="driver-closeout"<?php echo $openPanel ? '' : ' hidden'; ?>
        data-open-stops="<?php echo $openStops; ?>"
        data-delivered-stops="<?php echo (int)$summary['delivered_stops']; ?>"
        aria-labelledby="driverCloseoutTitle">
        <h2 id="driverCloseoutTitle"><?php bakery_te('driver.closeout_title'); ?></h2>
        <?php if (!empty($summary['error'])): ?>
            <p class="driver-closeout-warn" role="alert"><?php echo $h($summary['error']); ?></p>
        <?php endif; ?>
        <ul class="driver-closeout-stats">
            <li><span><?php bakery_te('driver.closeout_delivered'); ?></span><strong><?php echo (int)$summary['delivered_stops']; ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_problems'); ?></span><strong><?php echo count($summary['problem_stops']); ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_pieces'); ?></span><strong><?php echo (int)$summary['pieces_delivered']; ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_credits'); ?></span><strong><?php echo (int)$summary['credits']; ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_returns'); ?></span><strong id="driverCloseoutReturns"><?php echo (int)$summary['returns']; ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_cash'); ?></span><strong><?php echo $h($cashLabel); ?></strong></li>
            <li><span><?php bakery_te('driver.closeout_open'); ?></span><strong><?php echo $openStops; ?></strong></li>
        </ul>

        <h3><?php bakery_te('driver.closeout_problems'); ?></h3>
        <?php if ($summary['problem_stops'] === []): ?>
            <p class="driver-closeout-quiet"><?php bakery_te('driver.closeout_no_problems'); ?></p>
        <?php else: ?>
            <ul class="driver-closeout-problems">
                <?php foreach ($summary['problem_stops'] as $problem): ?>
                    <li>
                        <strong><?php echo $h($problem['customer_name']); ?></strong>
                        <span><?php echo $h($problem['reason']); ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($summary['is_closed'])): ?>
            <p class="driver-closeout-note"><?php bakery_te('driver.closeout_closed'); ?></p>
            <p class="driver-closeout-quiet"><?php bakery_te('driver.closeout_reopen_manager'); ?></p>
        <?php elseif (!empty($summary['error'])): ?>
        <?php elseif (empty($summary['closeout_ready'])): ?>
            <p class="driver-closeout-warn" role="alert"><?php bakery_te('driver.closeout_not_ready'); ?></p>
        <?php elseif (empty($summary['has_load'])): ?>
            <p class="driver-closeout-warn" role="alert"><?php bakery_te('driver.closeout_no_load'); ?></p>
        <?php elseif ($lines === []): ?>
            <p class="driver-closeout-warn" role="alert"><?php bakery_te('driver.closeout_no_lines'); ?></p>
        <?php else: ?>
            <?php if ($openStops > 0): ?>
                <p class="driver-closeout-warn" role="alert"><?php echo $h(bakery_t(
                    $openStops === 1 ? 'driver.closeout_open_warn_one' : 'driver.closeout_open_warn',
                    ['count' => $openStops]
                )); ?></p>
                <?php if ($summary['open_stop_names'] !== []): ?>
                    <ul class="driver-closeout-open">
                        <?php foreach ($summary['open_stop_names'] as $openName): ?>
                            <li><?php echo $h($openName); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!$driverOnly): ?>
                    <p class="driver-closeout-quiet"><?php bakery_te('driver.closeout_manager_open'); ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p class="driver-closeout-quiet"><?php bakery_te('driver.closeout_none_open'); ?></p>
            <?php endif; ?>

            <?php if ($canSubmit): ?>
            <form class="driver-closeout-form" method="post" action="<?php echo $h($action); ?>">
                <?php echo function_exists('bakery_csrf_field') ? bakery_csrf_field() : ''; ?>
                <input type="hidden" name="delivery_date" value="<?php echo $h($date); ?>">
                <input type="hidden" name="driver_id" value="<?php echo (int)$driverId; ?>">
                <input type="hidden" name="return" value="driver">
                <p class="driver-closeout-quiet"><?php bakery_te('driver.closeout_van'); ?></p>
                <ul class="driver-closeout-lines">
                    <?php foreach ($lines as $line):
                        $pid = (int)$line['product_id'];
                        $loaded = (int)$line['loaded_quantity'];
                        $delivered = (int)$line['delivered_quantity'];
                        $credits = (int)($line['credits_quantity'] ?? 0);
                        $returned = (int)$line['returned_quantity'];
                        $wasted = (int)$line['wasted_quantity'];
                        $balance = $loaded - $delivered - $credits - $returned - $wasted;
                        $productName = (string)$line['product_name'];
                        $lineShort = $loaded < ($delivered + $credits);
                    ?>
                    <li data-closeout-row data-loaded="<?php echo $loaded; ?>" data-delivered="<?php echo $delivered; ?>" data-credits="<?php echo $credits; ?>">
                        <strong><?php echo $h($productName); ?></strong>
                        <span><?php bakery_te('driver.closeout_loaded'); ?> <?php echo number_format($loaded); ?></span>
                        <span><?php bakery_te('driver.closeout_line_delivered'); ?> <?php echo number_format($delivered); ?></span>
                        <span><?php bakery_te('driver.closeout_credits'); ?> <?php echo number_format($credits); ?></span>
                        <?php if ($lineShort): ?>
                            <span class="driver-closeout-warn"><?php bakery_te('driver.closeout_short'); ?></span>
                        <?php else: ?>
                            <label><?php bakery_te('driver.closeout_returned'); ?>
                                <input type="number" min="0" step="1" inputmode="numeric" data-returned
                                    name="line[<?php echo $pid; ?>][returned]" value="<?php echo $returned; ?>"
                                    aria-label="<?php echo $h(bakery_t('driver.closeout_returned') . ' ' . $productName); ?>">
                            </label>
                            <label><?php bakery_te('driver.closeout_waste'); ?>
                                <input type="number" min="0" step="1" inputmode="numeric" data-wasted
                                    name="line[<?php echo $pid; ?>][wasted]" value="<?php echo $wasted; ?>"
                                    aria-label="<?php echo $h(bakery_t('driver.closeout_waste') . ' ' . $productName); ?>">
                            </label>
                            <span data-balance><?php bakery_te('driver.closeout_balance'); ?>: <?php echo $balance; ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <label class="driver-closeout-note-field"><?php bakery_te('driver.closeout_note'); ?>
                    <input name="notes" maxlength="500">
                </label>
                <?php if ($openStops > 0): ?>
                    <label class="driver-closeout-confirm">
                        <input type="checkbox" name="confirm_open_stops" value="1" required>
                        <span><?php bakery_te('driver.closeout_confirm_open'); ?></span>
                    </label>
                <?php endif; ?>
                <button class="driver-closeout-submit" type="submit" name="action" value="close_route"><?php bakery_te('driver.closeout_submit'); ?></button>
            </form>
            <?php elseif ($short): ?>
                <p class="driver-closeout-warn" role="alert"><?php bakery_te('driver.closeout_short'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php if ($canSubmit): ?>
    <script>
    (function () {
        var root = document.getElementById('driver-closeout');
        if (!root) return;
        function rowBalance(row) {
            var loaded = parseInt(row.getAttribute('data-loaded') || '0', 10) || 0;
            var delivered = parseInt(row.getAttribute('data-delivered') || '0', 10) || 0;
            var credits = parseInt(row.getAttribute('data-credits') || '0', 10) || 0;
            var returnedInput = row.querySelector('[data-returned]');
            var wastedInput = row.querySelector('[data-wasted]');
            if (!returnedInput || !wastedInput) return 0;
            var returned = parseInt(returnedInput.value || '0', 10);
            var wasted = parseInt(wastedInput.value || '0', 10);
            if (isNaN(returned)) returned = 0;
            if (isNaN(wasted)) wasted = 0;
            var balance = loaded - delivered - credits - returned - wasted;
            var cell = row.querySelector('[data-balance]');
            if (cell) cell.textContent = <?php echo json_encode(bakery_t('driver.closeout_balance'), JSON_UNESCAPED_UNICODE); ?> + ': ' + String(balance);
            return returned;
        }
        function refresh() {
            var returns = 0;
            root.querySelectorAll('[data-closeout-row]').forEach(function (row) {
                returns += rowBalance(row);
            });
            var total = document.getElementById('driverCloseoutReturns');
            if (total) total.textContent = String(returns);
        }
        root.querySelectorAll('[data-closeout-row] input').forEach(function (input) {
            input.addEventListener('input', refresh);
        });
    })();
    </script>
    <?php endif;
}
