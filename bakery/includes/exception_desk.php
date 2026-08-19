<?php
/**
 * Thumb-first exception desk. Coordination only — live operational exceptions
 * stay computed. Mutations reuse bakery_manager_exception_save and
 * bakery_delivery_recovery_*.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/manager_mode.php';
require_once __DIR__ . '/delivery_recovery.php';
require_once __DIR__ . '/operational_exceptions.php';

function bakery_exception_desk_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bakery_exception_desk_csrf(): string
{
    return function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
}

function bakery_exception_desk_reason_label(string $code): string
{
    $key = 'exception_desk.reason_' . $code;
    $translated = function_exists('bakery_t') ? bakery_t($key) : $key;
    if ($translated !== $key) {
        return $translated;
    }
    $codes = bakery_delivery_recovery_reason_codes();
    return $codes[$code] ?? $code;
}

function bakery_exception_desk_severity(array $row): string
{
    $severity = strtolower((string)($row['severity'] ?? 'warning'));
    return in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : 'warning';
}

/** @param list<array<string,mixed>> $exceptions */
function bakery_exception_desk_sort(array $exceptions): array
{
    $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($exceptions, static function (array $a, array $b) use ($rank): int {
        $sa = $rank[bakery_exception_desk_severity($a)] ?? 9;
        $sb = $rank[bakery_exception_desk_severity($b)] ?? 9;
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    return $exceptions;
}

function bakery_exception_desk_subject(array $exception): string
{
    $context = is_array($exception['context'] ?? null) ? $exception['context'] : [];
    foreach (['customer_name', 'product_name', 'driver_name'] as $nameKey) {
        $name = trim((string)($context[$nameKey] ?? $exception[$nameKey] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }
    if (!empty($context['customer_id'])) {
        return function_exists('bakery_t')
            ? bakery_t('ui.customer_num', ['id' => (int)$context['customer_id']])
            : ('Customer #' . (int)$context['customer_id']);
    }
    if (!empty($context['product_id'])) {
        return function_exists('bakery_t')
            ? bakery_t('ui.product_num', ['id' => (int)$context['product_id']])
            : ('Product #' . (int)$context['product_id']);
    }
    if (!empty($context['driver_id'])) {
        return function_exists('bakery_t')
            ? bakery_t('exception_desk.driver_num', ['id' => (int)$context['driver_id']])
            : ('Driver #' . (int)$context['driver_id']);
    }
    $category = trim((string)($exception['category'] ?? ''));
    return $category !== '' ? ucfirst(str_replace('_', ' ', $category)) : bakery_t('exception_desk.operating_item');
}

function bakery_exception_desk_fix_href(array $exception): string
{
    $href = trim((string)($exception['href'] ?? ''));
    if ($href !== '') {
        return $href;
    }
    $date = (string)($exception['_date'] ?? '');
    $type = (string)($exception['type'] ?? '');
    if ($date === '' || !function_exists('bakery_ops_link_daily_orders')) {
        return '';
    }
    if ($type === 'delivery_failed' || $type === 'delivery_failed_case') {
        return function_exists('bakery_ops_link_driver_assignment')
            ? bakery_ops_link_driver_assignment($date, ['filter' => 'failed'], 'manager')
            : '#failed-stop-recovery';
    }
    if ($type === 'production_fg_shortfall' || $type === 'production_shortfall') {
        return bakery_ops_link_inventory($date, ['attention' => 'shortfall'], 'manager');
    }
    return bakery_ops_link_daily_orders($date, [], 'manager');
}

function bakery_exception_desk_mine_input(array $exception): array
{
    $work = is_array($exception['work'] ?? null) ? $exception['work'] : [];
    $actorId = (int)(bakery_current_user()['id'] ?? 0);
    return [
        'acknowledge' => 1,
        'assigned_to_user_id' => $actorId,
        'resolution_note' => (string)($work['resolution_note'] ?? ''),
        'due_at' => '',
        'complete' => 0,
    ];
}

/** FG short by product for baker/pack cards. Does not mutate inventory. */
function bakery_exception_desk_product_shortages(PDO $db, string $date, ?array $limitProductIds = null): array
{
    if (!function_exists('bakery_inventory_ready') || !bakery_inventory_ready($db)) {
        return [];
    }
    if (!function_exists('bakery_operating_demand_by_product')) {
        require_once __DIR__ . '/demand_review.php';
    }
    if (!function_exists('bakery_operating_demand_by_product')) {
        return [];
    }
    $demand = bakery_operating_demand_by_product($db, $date);
    $required = $demand['by_product'] ?? [];
    if ($required === []) {
        return [];
    }
    $available = [];
    $loaded = [];
    $names = [];
    $stmt = $db->prepare(
        'SELECT product_id, available_quantity, loaded_quantity FROM product_inventory_days WHERE delivery_date = ?'
    );
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int)$row['product_id'];
        $available[$pid] = (int)$row['available_quantity'];
        $loaded[$pid] = (int)($row['loaded_quantity'] ?? 0);
    }
    $ids = array_map('intval', array_keys($required));
    if ($ids !== []) {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $nameStmt = $db->prepare("SELECT id, name FROM products WHERE id IN ({$marks})");
        $nameStmt->execute($ids);
        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(int)$row['id']] = (string)$row['name'];
        }
    }
    $out = [];
    foreach ($required as $productId => $qty) {
        $productId = (int)$productId;
        if (is_array($limitProductIds) && !in_array($productId, $limitProductIds, true)) {
            continue;
        }
        $need = (int)$qty;
        if ($need <= 0) {
            continue;
        }
        $have = ($available[$productId] ?? 0) + ($loaded[$productId] ?? 0);
        if ($have >= $need) {
            continue;
        }
        $out[] = [
            'product_id' => $productId,
            'product_name' => $names[$productId] ?? ('Product #' . $productId),
            'required' => $need,
            'available' => $have,
            'short' => $need - $have,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return $b['short'] <=> $a['short'];
    });
    return $out;
}

function bakery_exception_desk_shortage_exception(array $shortage, string $date): array
{
    $productId = (int)($shortage['product_id'] ?? 0);
    $name = (string)($shortage['product_name'] ?? '');
    $short = (int)($shortage['short'] ?? 0);
    $exception = bakery_ops_exception([
        'type' => 'production_fg_shortfall',
        'severity' => 'critical',
        'category' => 'production',
        'title' => $name !== '' ? $name : bakery_t('exception_desk.fg_short_title'),
        'detail' => bakery_t('exception_desk.short_by', ['count' => $short]),
        'count' => $short,
        'href' => function_exists('bakery_ops_link_pack_list')
            ? bakery_ops_link_pack_list($date, ['view' => 'product'], 'manager')
            : '',
        'action' => bakery_t('exception_desk.fix'),
        'product_id' => $productId,
        'context' => [
            'product_id' => $productId,
            'product_name' => $name,
        ],
    ]);
    $exception['_date'] = $date;
    return $exception;
}

function bakery_exception_desk_flag_shortage(PDO $db, string $date, int $productId, string $note, string $productName = ''): void
{
    $note = bakery_delivery_recovery_note($note, true);
    if (function_exists('bakery_user_has_role')
        && bakery_user_has_role(['baker'])
        && !bakery_user_has_role(['administrator', 'manager'])
    ) {
        $ids = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
        if (!is_array($ids) || !in_array($productId, $ids, true)) {
            throw new RuntimeException('You can only flag shortages on your assigned products');
        }
    }
    if ($productName === '') {
        $stmt = $db->prepare('SELECT name FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $productName = (string)($stmt->fetchColumn() ?: '');
    }
    $exception = bakery_exception_desk_shortage_exception([
        'product_id' => $productId,
        'product_name' => $productName,
        'short' => 0,
    ], $date);
    bakery_manager_exception_save($db, $exception, $date, [
        'acknowledge' => 1,
        'resolution_note' => $note,
        'assigned_to_user_id' => '',
        'due_at' => '',
        'complete' => 0,
    ]);
}

function bakery_exception_desk_reason_chips_html(string $fieldName = 'reason_code'): string
{
    $html = '<div class="exception-desk__chips" role="radiogroup" aria-label="' . bakery_exception_desk_h(bakery_t('exception_desk.reason')) . '">';
    $first = true;
    foreach (bakery_delivery_recovery_reason_codes() as $code => $_label) {
        $id = 'ed-reason-' . preg_replace('/[^a-z0-9_]+/', '-', $code) . '-' . substr(sha1($fieldName . $code), 0, 6);
        $html .= '<label class="exception-desk__chip" for="' . bakery_exception_desk_h($id) . '">';
        $html .= '<input type="radio" id="' . bakery_exception_desk_h($id) . '" name="' . bakery_exception_desk_h($fieldName) . '" value="'
            . bakery_exception_desk_h($code) . '"' . ($first ? ' checked' : '') . ' required>';
        $html .= '<span>' . bakery_exception_desk_h(bakery_exception_desk_reason_label($code)) . '</span></label>';
        $first = false;
    }
    $html .= '</div>';
    return $html;
}

function bakery_exception_desk_driver_fail_form(array $stop, string $actionUrl = 'complete_delivery.php'): string
{
    $status = (string)($stop['delivery_status'] ?? 'pending');
    if (in_array($status, ['delivered', 'cancelled', 'failed'], true)) {
        if ($status === 'failed') {
            return '<p class="exception-desk__reported">' . bakery_exception_desk_h(bakery_t('exception_desk.already_failed')) . '</p>';
        }
        return '';
    }
    $assignmentId = (int)($stop['assignment_id'] ?? 0);
    $orderId = (int)($stop['daily_order_id'] ?? 0);
    $customer = (string)($stop['customer_name'] ?? '');
    ob_start();
    ?>
    <form class="exception-desk exception-desk--driver" method="post" action="<?php echo bakery_exception_desk_h($actionUrl); ?>">
        <?php echo bakery_exception_desk_csrf(); ?>
        <input type="hidden" name="action" value="report_failed_stop">
        <input type="hidden" name="assignment_id" value="<?php echo $assignmentId; ?>">
        <input type="hidden" name="daily_order_id" value="<?php echo $orderId; ?>">
        <p class="exception-desk__kicker"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.cant_deliver')); ?></p>
        <?php if ($customer !== ''): ?>
            <p class="exception-desk__who"><?php echo bakery_exception_desk_h($customer); ?></p>
        <?php endif; ?>
        <?php echo bakery_exception_desk_reason_chips_html(); ?>
        <label class="exception-desk__note-label" for="ed-driver-note-<?php echo $orderId; ?>"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.driver_note')); ?></label>
        <textarea id="ed-driver-note-<?php echo $orderId; ?>" name="manager_note" rows="2" maxlength="2000"
            placeholder="<?php echo bakery_exception_desk_h(bakery_t('exception_desk.driver_note_ph')); ?>"></textarea>
        <button class="exception-desk__btn exception-desk__btn--primary" type="submit"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.report')); ?></button>
    </form>
    <?php
    return (string)ob_get_clean();
}

function bakery_exception_desk_baker_markup(array $shortages, string $date, string $formAction): string
{
    if ($shortages === []) {
        return '';
    }
    ob_start();
    ?>
    <section class="exception-desk exception-desk--baker" aria-label="<?php echo bakery_exception_desk_h(bakery_t('exception_desk.baker_title')); ?>">
        <h2 class="exception-desk__title"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.baker_title')); ?></h2>
        <?php foreach ($shortages as $shortage): ?>
            <?php
            $pid = (int)($shortage['product_id'] ?? 0);
            $name = (string)($shortage['product_name'] ?? '');
            $short = (int)($shortage['short'] ?? 0);
            ?>
            <article class="exception-desk__card exception-desk__card--critical">
                <span class="exception-desk__sev"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.critical')); ?></span>
                <h3><?php echo bakery_exception_desk_h($name); ?></h3>
                <p class="exception-desk__who"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.short_by', ['count' => $short])); ?></p>
                <p class="exception-desk__detail"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.baker_detail')); ?></p>
                <form method="post" action="<?php echo bakery_exception_desk_h($formAction); ?>" class="exception-desk__actions exception-desk__actions--stack">
                    <?php echo bakery_exception_desk_csrf(); ?>
                    <input type="hidden" name="exception_desk_mutation" value="flag_shortage">
                    <input type="hidden" name="date" value="<?php echo bakery_exception_desk_h($date); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                    <input type="hidden" name="product_name" value="<?php echo bakery_exception_desk_h($name); ?>">
                    <label class="exception-desk__note-label"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.baker_note')); ?>
                        <textarea name="resolution_note" rows="2" maxlength="2000" required
                            placeholder="<?php echo bakery_exception_desk_h(bakery_t('exception_desk.baker_note_ph')); ?>"></textarea>
                    </label>
                    <button class="exception-desk__btn exception-desk__btn--primary" type="submit"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.flag_shortage')); ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function bakery_exception_desk_render_baker(PDO $db, string $date, array $shortages, string $formAction): void
{
    if (!function_exists('bakery_user_has_role') || !bakery_user_has_role(['baker'])) {
        return;
    }
    echo bakery_exception_desk_baker_markup($shortages, $date, $formAction);
}

function bakery_exception_desk_handle_baker_post(PDO $db): ?string
{
    if (($_POST['exception_desk_mutation'] ?? '') !== 'flag_shortage') {
        return null;
    }
    if (function_exists('bakery_require_role')) {
        bakery_require_role(['baker']);
    }
    $date = trim((string)($_POST['date'] ?? ''));
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Use a valid delivery date.');
    }
    bakery_exception_desk_flag_shortage(
        $db,
        $date,
        (int)($_POST['product_id'] ?? 0),
        (string)($_POST['resolution_note'] ?? ''),
        (string)($_POST['product_name'] ?? '')
    );
    return bakery_t('exception_desk.flagged');
}

function bakery_exception_desk_retry_default(string $date): string
{
    $now = new DateTimeImmutable('now');
    $candidate = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $now->modify('+1 hour')->format('H:i'));
    if ($candidate && $candidate->getTimestamp() > time() && $candidate->format('Y-m-d') === $date) {
        return $candidate->format('Y-m-d\TH:i');
    }
    $afternoon = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' 16:00');
    if ($afternoon && $afternoon->getTimestamp() > time()) {
        return $afternoon->format('Y-m-d\TH:i');
    }
    return $date . 'T23:30';
}

function bakery_exception_desk_work_form(array $exception, string $date, string $mode): string
{
    $workKey = (string)($exception['work_key'] ?? '');
    $work = is_array($exception['work'] ?? null) ? $exception['work'] : [];
    $note = (string)($work['resolution_note'] ?? '');
    $actorId = (int)(bakery_current_user()['id'] ?? 0);
    ob_start();
    if ($mode === 'mine') {
        ?>
        <form method="post" class="exception-desk__action-form">
            <?php echo bakery_exception_desk_csrf(); ?>
            <input type="hidden" name="manager_mutation" value="exception_work">
            <input type="hidden" name="work_key" value="<?php echo bakery_exception_desk_h($workKey); ?>">
            <input type="hidden" name="acknowledge" value="1">
            <input type="hidden" name="assigned_to_user_id" value="<?php echo $actorId; ?>">
            <input type="hidden" name="resolution_note" value="<?php echo bakery_exception_desk_h($note); ?>">
            <button class="exception-desk__btn exception-desk__btn--primary" type="submit"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.mine')); ?></button>
        </form>
        <?php
    } elseif ($mode === 'note') {
        ?>
        <details class="exception-desk__more">
            <summary><?php echo bakery_exception_desk_h(bakery_t('exception_desk.note')); ?></summary>
            <form method="post" class="exception-desk__note-form">
                <?php echo bakery_exception_desk_csrf(); ?>
                <input type="hidden" name="manager_mutation" value="exception_work">
                <input type="hidden" name="work_key" value="<?php echo bakery_exception_desk_h($workKey); ?>">
                <input type="hidden" name="acknowledge" value="1">
                <input type="hidden" name="assigned_to_user_id" value="<?php echo (int)($work['assigned_to_user_id'] ?? $actorId); ?>">
                <label><?php echo bakery_exception_desk_h(bakery_t('exception_desk.note')); ?>
                    <textarea name="resolution_note" rows="2" maxlength="2000" required><?php echo bakery_exception_desk_h($note); ?></textarea>
                </label>
                <div class="exception-desk__note-actions">
                    <button class="exception-desk__btn" type="submit"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.save_note')); ?></button>
                    <button class="exception-desk__btn exception-desk__btn--primary" type="submit" name="complete" value="1"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.complete')); ?></button>
                </div>
            </form>
        </details>
        <?php
    }
    return (string)ob_get_clean();
}

function bakery_exception_desk_exception_card(array $exception, string $date): string
{
    $severity = bakery_exception_desk_severity($exception);
    $title = (string)($exception['title'] ?? bakery_t('exception_desk.operating_item'));
    $detail = (string)($exception['detail'] ?? '');
    $href = bakery_exception_desk_fix_href($exception + ['_date' => $date]);
    $fixLabel = (string)($exception['action'] ?? '');
    if ($fixLabel === '') {
        $fixLabel = bakery_t('exception_desk.fix');
    }
    ob_start();
    ?>
    <article class="exception-desk__card exception-desk__card--<?php echo bakery_exception_desk_h($severity); ?>">
        <span class="exception-desk__sev"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.' . $severity)); ?></span>
        <h3><?php echo bakery_exception_desk_h($title); ?></h3>
        <p class="exception-desk__who"><?php echo bakery_exception_desk_h(bakery_exception_desk_subject($exception)); ?></p>
        <?php if ($detail !== ''): ?>
            <p class="exception-desk__detail"><?php echo bakery_exception_desk_h($detail); ?></p>
        <?php endif; ?>
        <div class="exception-desk__actions">
            <?php echo bakery_exception_desk_work_form($exception, $date, 'mine'); ?>
            <?php if ($href !== ''): ?>
                <a class="exception-desk__btn" href="<?php echo bakery_exception_desk_h($href); ?>"><?php echo bakery_exception_desk_h($fixLabel); ?></a>
            <?php endif; ?>
            <?php echo bakery_exception_desk_work_form($exception, $date, 'note'); ?>
        </div>
    </article>
    <?php
    return (string)ob_get_clean();
}

function bakery_exception_desk_recovery_card(array $case, array $drivers, string $date): string
{
    $reason = bakery_exception_desk_reason_label((string)($case['failure_reason'] ?? ''));
    $state = str_replace('_', ' ', (string)($case['workflow_state'] ?? 'open'));
    $customer = (string)($case['customer_name'] ?? '');
    $driver = (string)($case['active_driver_name'] ?? '');
    $retryDefault = bakery_exception_desk_retry_default($date);
    ob_start();
    ?>
    <article class="exception-desk__card exception-desk__card--critical">
        <span class="exception-desk__sev"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.critical')); ?></span>
        <h3><?php echo bakery_exception_desk_h(bakery_t('exception_desk.failed_stop')); ?></h3>
        <p class="exception-desk__who"><?php echo bakery_exception_desk_h($customer !== '' ? $customer : $driver); ?></p>
        <p class="exception-desk__detail"><?php echo bakery_exception_desk_h($reason . ' · ' . $state); ?></p>
        <form method="post" class="exception-desk__actions exception-desk__actions--recovery">
            <?php echo bakery_exception_desk_csrf(); ?>
            <input type="hidden" name="manager_mutation" value="recovery_action">
            <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
            <label class="exception-desk__note-label"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.decision_note')); ?>
                <textarea name="manager_note" rows="2" maxlength="2000"><?php echo bakery_exception_desk_h((string)($case['manager_note'] ?? '')); ?></textarea>
            </label>
            <div class="exception-desk__btn-row">
                <button class="exception-desk__btn exception-desk__btn--primary" type="submit" name="recovery_action" value="acknowledge"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.ack')); ?></button>
                <button class="exception-desk__btn" type="submit" name="recovery_action" value="retry"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.retry')); ?></button>
                <button class="exception-desk__btn" type="submit" name="recovery_action" value="reassign"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.reassign')); ?></button>
            </div>
            <details class="exception-desk__more">
                <summary><?php echo bakery_exception_desk_h(bakery_t('exception_desk.retry_when')); ?></summary>
                <label><?php echo bakery_exception_desk_h(bakery_t('exception_desk.retry_at')); ?>
                    <input type="datetime-local" name="retry_at" value="<?php echo bakery_exception_desk_h($retryDefault); ?>">
                </label>
            </details>
            <label class="exception-desk__select-label"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.reassign_to')); ?>
                <select name="to_driver_id">
                    <option value=""><?php echo bakery_exception_desk_h(bakery_t('exception_desk.choose_driver')); ?></option>
                    <?php foreach ($drivers as $driverRow): ?>
                        <option value="<?php echo (int)$driverRow['id']; ?>"><?php echo bakery_exception_desk_h((string)$driverRow['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <details class="exception-desk__more">
                <summary><?php echo bakery_exception_desk_h(bakery_t('exception_desk.more')); ?></summary>
                <label><?php echo bakery_exception_desk_h(bakery_t('exception_desk.communication')); ?>
                    <select name="communication_status">
                        <?php foreach (['not_needed', 'pending', 'contacted', 'unable_to_reach'] as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo ($case['customer_communication_status'] ?? '') === $status ? 'selected' : ''; ?>><?php echo bakery_exception_desk_h(str_replace('_', ' ', $status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><?php echo bakery_exception_desk_h(bakery_t('exception_desk.billing_handoff')); ?>
                    <select name="billing_handoff">
                        <?php foreach (['not_needed', 'review_needed', 'credit_requested', 'credit_issued', 'not_billable'] as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo ($case['billing_handoff'] ?? '') === $status ? 'selected' : ''; ?>><?php echo bakery_exception_desk_h(str_replace('_', ' ', $status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button class="exception-desk__btn" type="submit" name="recovery_action" value="update_handoffs"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.save_handoff')); ?></button>
            </details>
        </form>
    </article>
    <?php
    return (string)ob_get_clean();
}

function bakery_exception_desk_untriaged_card(array $stop): string
{
    ob_start();
    ?>
    <article class="exception-desk__card exception-desk__card--critical">
        <span class="exception-desk__sev"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.critical')); ?></span>
        <h3><?php echo bakery_exception_desk_h(bakery_t('exception_desk.failed_stop')); ?></h3>
        <p class="exception-desk__who"><?php echo bakery_exception_desk_h((string)($stop['customer_name'] ?? '')); ?></p>
        <p class="exception-desk__detail"><?php echo bakery_exception_desk_h((string)($stop['driver_name'] ?? bakery_t('exception_desk.unassigned_driver'))); ?></p>
        <form method="post" class="exception-desk__actions exception-desk__actions--stack">
            <?php echo bakery_exception_desk_csrf(); ?>
            <input type="hidden" name="manager_mutation" value="recovery_report">
            <input type="hidden" name="assignment_id" value="<?php echo (int)$stop['assignment_id']; ?>">
            <?php echo bakery_exception_desk_reason_chips_html(); ?>
            <label class="exception-desk__note-label"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.decision_note')); ?>
                <textarea name="manager_note" rows="2" maxlength="2000" required></textarea>
            </label>
            <button class="exception-desk__btn exception-desk__btn--primary" type="submit"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.open_recovery')); ?></button>
        </form>
    </article>
    <?php
    return (string)ob_get_clean();
}

/**
 * Manager phone queue. Hidden above 720px via CSS. Does not emit due-at or bulk controls.
 *
 * @param list<array<string,mixed>> $exceptions
 * @param array<string,mixed> $options
 */
function bakery_exception_desk_manager_markup(?PDO $db, string $date, array $exceptions, array $options = []): string
{
    $recoveryCases = $options['recovery_cases'] ?? null;
    $untriaged = $options['untriaged'] ?? null;
    $drivers = $options['drivers'] ?? null;
    if ($db instanceof PDO && $recoveryCases === null && function_exists('bakery_delivery_recovery_cases_for_date')) {
        $recoveryCases = bakery_delivery_recovery_cases_for_date($db, $date);
    }
    if ($db instanceof PDO && $untriaged === null && function_exists('bakery_delivery_recovery_untriaged_failed_stops')) {
        $untriaged = bakery_delivery_recovery_untriaged_failed_stops($db, $date);
    }
    if ($db instanceof PDO && $drivers === null && function_exists('bakery_manager_route_plan')) {
        $plan = bakery_manager_route_plan($db, $date);
        $drivers = $plan['drivers'] ?? [];
    }
    $recoveryCases = is_array($recoveryCases) ? $recoveryCases : [];
    $untriaged = is_array($untriaged) ? $untriaged : [];
    $drivers = is_array($drivers) ? $drivers : [];

    $queue = [];
    foreach ($exceptions as $exception) {
        if (!is_array($exception)) {
            continue;
        }
        if ((string)($exception['type'] ?? '') === 'delivery_failed' && ($recoveryCases !== [] || $untriaged !== [])) {
            continue;
        }
        $exception['_date'] = $date;
        $queue[] = $exception;
    }
    $queue = bakery_exception_desk_sort($queue);

    ob_start();
    ?>
    <section class="exception-desk exception-desk--manager" aria-label="<?php echo bakery_exception_desk_h(bakery_t('exception_desk.manager_title')); ?>">
        <h2 class="exception-desk__title"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.manager_title')); ?></h2>
        <?php if ($queue === [] && $recoveryCases === [] && $untriaged === []): ?>
            <p class="exception-desk__empty"><?php echo bakery_exception_desk_h(bakery_t('exception_desk.none')); ?></p>
        <?php endif; ?>
        <?php foreach ($untriaged as $stop): ?>
            <?php echo bakery_exception_desk_untriaged_card($stop); ?>
        <?php endforeach; ?>
        <?php foreach ($recoveryCases as $case): ?>
            <?php echo bakery_exception_desk_recovery_card($case, $drivers, $date); ?>
        <?php endforeach; ?>
        <?php foreach ($queue as $exception): ?>
            <?php echo bakery_exception_desk_exception_card($exception, $date); ?>
        <?php endforeach; ?>
    </section>
    <?php
    return (string)ob_get_clean();
}

function bakery_exception_desk_render(PDO $db, string $selectedDate, array $exceptions): void
{
    echo bakery_exception_desk_manager_markup($db, $selectedDate, $exceptions);
}
