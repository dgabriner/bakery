<?php
/**
 * Missed pan dulce carry-forward.
 *
 * At close of day, and again from scripts/demand_scheduler.php, a routed stop
 * that is still open or arrived-not-closed and has a pan dulce order is added
 * to the next service day. Same driver, same stop position, label
 * "Pendiente de ayer". The next service day is bakery_survey_next_sell_date_from_db,
 * so a Sunday miss rolls to Monday.
 *
 * One order per store per day. An existing positive delivery is left alone.
 * A zeroed or removed dated order is restored from that store's standing order
 * for that weekday only.
 *
 * Exclusions, chosen without store names:
 * - delivered, failed, or cancelled assignments
 * - a customer_delivery_skips row, or assignment notes that start a line with
 *   "Skipped:" (the existing no-entregar marker from bakery_skip_delivery_stop)
 * - a dated_route_removals row for the source date
 * - a $0 special-order stop: an open special order with quantity, and no
 *   positive-priced Pan Dulce line. Tacolicious Valencia, Bar 49, and Marcellas
 *   match this when they ride the route as special-only $0 stops.
 *
 * Rerunning close or the overnight job does not add a second stop. A manager
 * removal stays removed.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_load.php';
require_once __DIR__ . '/survey_store_verify.php';
require_once __DIR__ . '/daily_order_generation.php';
require_once __DIR__ . '/driver_assignments.php';
require_once __DIR__ . '/special_orders.php';

function bakery_pan_carry_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'route_carry_forwards');
}

function bakery_pan_carry_next_service_date(PDO $db, string $sourceDate): string
{
    return bakery_survey_next_sell_date_from_db($db, $sourceDate);
}

/**
 * Carry every missed pan dulce stop from one closed or past service day.
 *
 * @return array{carried:int,deduped:int,restored:int,skipped:int}
 */
function bakery_pan_carry_forward_day(PDO $db, string $sourceDate): array
{
    $counts = ['carried' => 0, 'deduped' => 0, 'restored' => 0, 'skipped' => 0];
    if (!bakery_pan_carry_ready($db) || !table_exists($db, 'daily_order_assignments')) {
        return $counts;
    }
    $sourceDate = bakery_survey_validate_ymd($sourceDate);
    $targetDate = bakery_pan_carry_next_service_date($db, $sourceDate);
    foreach (bakery_pan_carry_source_stops($db, $sourceDate) as $stop) {
        $existing = bakery_pan_carry_find($db, $sourceDate, (int)$stop['customer_id']);
        if ($existing) {
            $counts['skipped']++;
            continue;
        }
        if (!bakery_pan_carry_stop_is_eligible($db, $stop)) {
            $counts['skipped']++;
            continue;
        }
        $result = bakery_pan_carry_place($db, $stop, $targetDate);
        $outcome = (string)($result['outcome'] ?? 'skipped');
        if ($outcome === 'carried') {
            $counts['carried']++;
        } elseif ($outcome === 'deduped') {
            $counts['deduped']++;
        } elseif ($outcome === 'restored') {
            $counts['restored']++;
            $counts['carried']++;
        } else {
            $counts['skipped']++;
        }
    }
    return $counts;
}

/**
 * Overnight window. Dates before $today only, so an open morning route is not carried.
 *
 * @return array<string, array{carried:int,deduped:int,restored:int,skipped:int}>
 */
function bakery_pan_carry_forward_recent(PDO $db, string $today, int $days = 7): array
{
    $today = bakery_survey_validate_ymd($today);
    $days = max(1, min(14, $days));
    $cursor = new DateTimeImmutable($today);
    $out = [];
    for ($i = 1; $i <= $days; $i++) {
        $source = $cursor->modify('-' . $i . ' days')->format('Y-m-d');
        $out[$source] = bakery_pan_carry_forward_day($db, $source);
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_pan_carry_source_stops(PDO $db, string $sourceDate): array
{
    $notes = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes')
        ? 'doa.notes'
        : 'NULL AS notes';
    $stmt = $db->prepare(
        "SELECT doa.id AS assignment_id, doa.daily_order_id, doa.driver_id, doa.route_order,
                doa.delivery_status, {$notes},
                do.customer_id, c.name AS customer_name
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         WHERE doa.delivery_date = ?
         ORDER BY doa.driver_id, doa.route_order, doa.id"
    );
    $stmt->execute([$sourceDate]);
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['source_date'] = $sourceDate;
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param array<string, mixed> $stop
 */
function bakery_pan_carry_stop_is_eligible(PDO $db, array $stop): bool
{
    $status = (string)($stop['delivery_status'] ?? 'pending');
    if (in_array($status, ['delivered', 'cancelled', 'failed'], true)) {
        return false;
    }
    if (!in_array($status, ['pending', 'in_transit', 'rescheduled', ''], true)) {
        return false;
    }
    $notes = (string)($stop['notes'] ?? '');
    if (preg_match('/(^|\n)\s*Skipped:/', $notes) === 1) {
        return false;
    }
    $customerId = (int)($stop['customer_id'] ?? 0);
    $sourceDate = (string)($stop['source_date'] ?? '');
    if (bakery_pan_carry_customer_skipped($db, $customerId, $sourceDate)) {
        return false;
    }
    if (bakery_pan_carry_route_removed($db, $customerId, $sourceDate)) {
        return false;
    }
    $pan = bakery_pan_carry_pan_counts($db, (int)($stop['daily_order_id'] ?? 0));
    if ($pan['pan_qty'] <= 0) {
        return false;
    }
    if (bakery_pan_carry_is_zero_special($db, (int)$stop['daily_order_id'], $pan)) {
        return false;
    }
    return true;
}

function bakery_pan_carry_customer_skipped(PDO $db, int $customerId, string $date): bool
{
    if ($customerId <= 0 || !table_exists($db, 'customer_delivery_skips')) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM customer_delivery_skips WHERE customer_id = ? AND skip_date = ? LIMIT 1');
    $stmt->execute([$customerId, $date]);
    return (bool)$stmt->fetchColumn();
}

function bakery_pan_carry_route_removed(PDO $db, int $customerId, string $date): bool
{
    if ($customerId <= 0 || !table_exists($db, 'dated_route_removals')) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM dated_route_removals WHERE customer_id = ? AND delivery_date = ? LIMIT 1');
    $stmt->execute([$customerId, $date]);
    return (bool)$stmt->fetchColumn();
}

/**
 * @return array{pan_qty:int,priced_pan_qty:int}
 */
function bakery_pan_carry_pan_counts(PDO $db, int $dailyOrderId): array
{
    $empty = ['pan_qty' => 0, 'priced_pan_qty' => 0];
    if ($dailyOrderId <= 0 || !table_exists($db, 'daily_order_items')) {
        return $empty;
    }
    $noCharge = function_exists('column_exists') && column_exists($db, 'daily_order_items', 'is_no_charge')
        ? 'COALESCE(doi.is_no_charge, 0) = 0'
        : '1 = 1';
    $stmt = $db->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN doi.quantity > 0 AND LOWER(pl.name) = 'pan dulce' THEN doi.quantity ELSE 0 END), 0) AS pan_qty,
            COALESCE(SUM(CASE
                WHEN doi.quantity > 0 AND LOWER(pl.name) = 'pan dulce'
                 AND doi.unit_price > 0 AND {$noCharge}
                THEN doi.quantity ELSE 0 END), 0) AS priced_pan_qty
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ?"
    );
    $stmt->execute([$dailyOrderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'pan_qty' => (int)($row['pan_qty'] ?? 0),
        'priced_pan_qty' => (int)($row['priced_pan_qty'] ?? 0),
    ];
}

/**
 * $0 special-order store: open special order, and no positive-priced pan dulce.
 * Store names are not used.
 *
 * @param array{pan_qty:int,priced_pan_qty:int} $pan
 */
function bakery_pan_carry_is_zero_special(PDO $db, int $dailyOrderId, array $pan): bool
{
    if ($dailyOrderId <= 0 || (int)($pan['priced_pan_qty'] ?? 0) > 0) {
        return false;
    }
    if (!function_exists('bakery_special_order_has_positive_qty') || !bakery_special_orders_ready($db)) {
        return false;
    }
    return bakery_special_order_has_positive_qty($db, $dailyOrderId);
}

/**
 * @param array<string, mixed> $stop
 * @return array{outcome:string,carry_id:int}
 */
function bakery_pan_carry_place(PDO $db, array $stop, string $targetDate): array
{
    $customerId = (int)$stop['customer_id'];
    $driverId = (int)$stop['driver_id'];
    $routeOrder = max(1, (int)($stop['route_order'] ?? 1));
    $sourceDate = (string)$stop['source_date'];
    $orderStmt = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1');
    $orderStmt->execute([$customerId, $targetDate]);
    $targetOrderId = (int)($orderStmt->fetchColumn() ?: 0);
    $targetPan = $targetOrderId > 0 ? bakery_pan_carry_pan_counts($db, $targetOrderId) : ['pan_qty' => 0, 'priced_pan_qty' => 0];
    $restored = false;
    if ($targetOrderId > 0 && (int)$targetPan['pan_qty'] > 0) {
        $carryId = bakery_pan_carry_insert($db, $stop, $targetDate, $targetOrderId, null, 'deduped', false);
        bakery_pan_carry_audit($db, $carryId, 'dedupe', 'target_date', null, $targetDate);
        return ['outcome' => 'deduped', 'carry_id' => $carryId];
    }
    if ($targetOrderId > 0 && bakery_pan_carry_target_blocked($db, $customerId, $targetDate, $targetOrderId)) {
        $carryId = bakery_pan_carry_insert($db, $stop, $targetDate, $targetOrderId, null, 'skipped', false);
        return ['outcome' => 'skipped', 'carry_id' => $carryId];
    }
    if (bakery_pan_carry_standing_qty($db, $customerId, $targetDate) > 0) {
        bakery_driver_restore_standing_for_dated_order($db, $customerId, $targetDate, $targetOrderId);
        $restored = true;
        $orderStmt->execute([$customerId, $targetDate]);
        $targetOrderId = (int)($orderStmt->fetchColumn() ?: 0);
    }
    if ($targetOrderId <= 0) {
        $targetOrderId = bakery_daily_order_find_or_create($db, $customerId, $targetDate);
    }
    $targetPan = bakery_pan_carry_pan_counts($db, $targetOrderId);
    if ((int)$targetPan['pan_qty'] <= 0) {
        bakery_pan_carry_copy_source_pan($db, (int)$stop['daily_order_id'], $targetOrderId);
    }
    if ($restored || bakery_pan_carry_route_removed($db, $customerId, $targetDate)) {
        bakery_dated_route_removal_clear($db, $targetDate, $customerId);
    }
    $assignmentId = bakery_pan_carry_ensure_assignment($db, $targetOrderId, $driverId, $targetDate, $routeOrder);
    $outcome = $restored ? 'restored' : 'carried';
    $carryId = bakery_pan_carry_insert($db, $stop, $targetDate, $targetOrderId, $assignmentId, $outcome, $restored);
    bakery_pan_carry_audit($db, $carryId, 'carry', 'target_date', $sourceDate, $targetDate);
    if ($restored) {
        bakery_pan_carry_audit($db, $carryId, 'restore', 'standing', '0', 'standing');
    }
    return ['outcome' => $outcome, 'carry_id' => $carryId];
}

function bakery_pan_carry_target_blocked(PDO $db, int $customerId, string $date, int $dailyOrderId): bool
{
    if (bakery_pan_carry_customer_skipped($db, $customerId, $date)) {
        return true;
    }
    if (!table_exists($db, 'daily_order_assignments')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT delivery_status FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$dailyOrderId, $date]);
    $status = (string)($stmt->fetchColumn() ?: '');
    return $status === 'cancelled';
}

function bakery_pan_carry_standing_qty(PDO $db, int $customerId, string $date): int
{
    if ($customerId <= 0 || !table_exists($db, 'standing_orders') || !function_exists('bakery_standing_day_from_date')) {
        return 0;
    }
    $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($date));
    $stmt = $db->prepare(
        "SELECT COALESCE(SUM(quantity), 0) FROM standing_orders
         WHERE customer_id = ? AND day_of_week {$dayClause['sql']} AND quantity > 0"
    );
    $stmt->execute(array_merge([$customerId], $dayClause['values']));
    return (int)$stmt->fetchColumn();
}

function bakery_pan_carry_copy_source_pan(PDO $db, int $sourceOrderId, int $targetOrderId): void
{
    if ($sourceOrderId <= 0 || $targetOrderId <= 0) {
        return;
    }
    $stmt = $db->prepare(
        "SELECT doi.product_id, doi.quantity, doi.unit_price
         FROM daily_order_items doi
         JOIN products p ON p.id = doi.product_id
         JOIN dough_types dt ON dt.id = p.dough_type_id
         JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE doi.daily_order_id = ? AND doi.quantity > 0 AND LOWER(pl.name) = 'pan dulce'"
    );
    $stmt->execute([$sourceOrderId]);
    $upsert = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            quantity = IF(quantity > 0, quantity, VALUES(quantity)),
            unit_price = IF(quantity > 0, unit_price, VALUES(unit_price)),
            line_total = IF(quantity > 0, line_total, VALUES(line_total))'
    );
    $wrote = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $qty = (int)$line['quantity'];
        $unit = round((float)$line['unit_price'], 2);
        $upsert->execute([$targetOrderId, (int)$line['product_id'], $qty, $unit, round($qty * $unit, 2)]);
        $wrote = true;
    }
    if ($wrote && function_exists('bakery_daily_order_recompute_total')) {
        bakery_daily_order_recompute_total($db, $targetOrderId);
    }
}

function bakery_pan_carry_ensure_assignment(
    PDO $db,
    int $dailyOrderId,
    int $driverId,
    string $date,
    int $routeOrder
): int {
    $existing = $db->prepare(
        'SELECT id, driver_id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ? ORDER BY id LIMIT 1'
    );
    $existing->execute([$dailyOrderId, $date]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')->execute([(int)$row['driver_id'], $dailyOrderId]);
        return (int)$row['id'];
    }
    $routeOrder = bakery_pan_carry_claim_route_order($db, $driverId, $date, $routeOrder);
    $hasNotes = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes');
    if ($hasNotes) {
        $db->prepare(
            'INSERT INTO daily_order_assignments
                (daily_order_id, driver_id, delivery_date, route_order, delivery_status, notes)
             VALUES (?, ?, ?, ?, \'pending\', NULL)'
        )->execute([$dailyOrderId, $driverId, $date, $routeOrder]);
    } else {
        $db->prepare(
            'INSERT INTO daily_order_assignments
                (daily_order_id, driver_id, delivery_date, route_order, delivery_status)
             VALUES (?, ?, ?, ?, \'pending\')'
        )->execute([$dailyOrderId, $driverId, $date, $routeOrder]);
    }
    $assignmentId = (int)$db->lastInsertId();
    $db->prepare('UPDATE daily_orders SET driver_id = ? WHERE id = ?')->execute([$driverId, $dailyOrderId]);
    return $assignmentId;
}

function bakery_pan_carry_claim_route_order(PDO $db, int $driverId, string $date, int $wanted): int
{
    if ($wanted <= 0) {
        $wanted = 1;
    }
    $taken = $db->prepare(
        'SELECT id FROM daily_order_assignments WHERE driver_id = ? AND delivery_date = ? AND route_order = ? LIMIT 1'
    );
    $taken->execute([$driverId, $date, $wanted]);
    if (!$taken->fetchColumn()) {
        return $wanted;
    }
    $tail = $db->prepare(
        'SELECT id, route_order FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ? AND route_order >= ?
         ORDER BY route_order DESC, id DESC'
    );
    $tail->execute([$driverId, $date, $wanted]);
    $shift = $db->prepare('UPDATE daily_order_assignments SET route_order = ? WHERE id = ?');
    foreach ($tail->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $shift->execute([(int)$row['route_order'] + 1, (int)$row['id']]);
    }
    return $wanted;
}

/**
 * @param array<string, mixed> $stop
 */
function bakery_pan_carry_insert(
    PDO $db,
    array $stop,
    string $targetDate,
    ?int $targetOrderId,
    ?int $targetAssignmentId,
    string $outcome,
    bool $restored
): int {
    $stmt = $db->prepare(
        'INSERT INTO route_carry_forwards (
            source_date, source_assignment_id, customer_id, driver_id, route_order,
            target_date, target_daily_order_id, target_assignment_id, outcome, status, restored_standing
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?)'
    );
    $stmt->execute([
        (string)$stop['source_date'],
        (int)($stop['assignment_id'] ?? 0) ?: null,
        (int)$stop['customer_id'],
        (int)$stop['driver_id'],
        max(1, (int)($stop['route_order'] ?? 1)),
        $targetDate,
        $targetOrderId ?: null,
        $targetAssignmentId ?: null,
        substr($outcome, 0, 32),
        $restored ? 1 : 0,
    ]);
    return (int)$db->lastInsertId();
}

/**
 * @return array<string, mixed>|null
 */
function bakery_pan_carry_find(PDO $db, string $sourceDate, int $customerId): ?array
{
    if (!bakery_pan_carry_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT * FROM route_carry_forwards WHERE source_date = ? AND customer_id = ? LIMIT 1'
    );
    $stmt->execute([$sourceDate, $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Active carries landing on $date, keyed by customer id.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_pan_carry_labels_for_date(PDO $db, string $date): array
{
    if (!bakery_pan_carry_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT * FROM route_carry_forwards
         WHERE target_date = ? AND status = 'active' AND outcome IN ('carried', 'restored')
         ORDER BY id"
    );
    $stmt->execute([$date]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['label'] = bakery_route_load_text('pan_carry.label', [], 'Left from yesterday');
        $map[(int)$row['customer_id']] = $row;
    }
    return $map;
}

function bakery_pan_carry_assert_manager(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    bakery_require_role(['administrator', 'manager']);
}

/**
 * One tap. Removes the carried assignment and does not put it back on the next run.
 */
function bakery_pan_carry_remove(PDO $db, int $carryId, ?int $userId = null): bool
{
    bakery_pan_carry_assert_manager();
    if ($carryId <= 0 || !bakery_pan_carry_ready($db)) {
        return false;
    }
    $stmt = $db->prepare('SELECT * FROM route_carry_forwards WHERE id = ? LIMIT 1');
    $stmt->execute([$carryId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    if ((string)$row['status'] === 'removed') {
        return true;
    }
    $assignmentId = (int)($row['target_assignment_id'] ?? 0);
    $dailyOrderId = (int)($row['target_daily_order_id'] ?? 0);
    $driverId = (int)$row['driver_id'];
    $targetDate = (string)$row['target_date'];
    if ($assignmentId <= 0 && $dailyOrderId > 0) {
        $find = $db->prepare(
            'SELECT id FROM daily_order_assignments WHERE daily_order_id = ? AND driver_id = ? AND delivery_date = ? LIMIT 1'
        );
        $find->execute([$dailyOrderId, $driverId, $targetDate]);
        $assignmentId = (int)($find->fetchColumn() ?: 0);
    }
    if ($assignmentId > 0) {
        $db->prepare('DELETE FROM daily_order_assignments WHERE id = ? AND delivery_status NOT IN (\'delivered\', \'in_transit\')')
            ->execute([$assignmentId]);
    }
    $customerId = (int)$row['customer_id'];
    bakery_dated_route_removal_record($db, $targetDate, $customerId, $driverId);
    if ($dailyOrderId > 0) {
        $db->prepare(
            'UPDATE daily_orders SET driver_id = NULL
             WHERE id = ? AND NOT EXISTS (
                SELECT 1 FROM daily_order_assignments doa WHERE doa.daily_order_id = daily_orders.id
             )'
        )->execute([$dailyOrderId]);
    }
    $db->prepare(
        "UPDATE route_carry_forwards
         SET status = 'removed', removed_at = NOW(), removed_by_user_id = ?
         WHERE id = ?"
    )->execute([$userId, $carryId]);
    bakery_pan_carry_audit($db, $carryId, 'remove', 'status', 'active', 'removed');
    return true;
}

function bakery_pan_carry_audit(
    PDO $db,
    int $carryId,
    string $action,
    string $field,
    ?string $old,
    ?string $new
): void {
    if ($carryId <= 0) {
        return;
    }
    bakery_audit_write($db, 'route_carry', $carryId, $action, $field, $old, $new);
}

/**
 * Carried stops for the board, with a one-tap remove for admins and managers.
 *
 * @param array<string, mixed>|null $user
 */
function bakery_pan_carry_board_html(PDO $db, string $date, ?array $user = null): string
{
    if (!bakery_route_load_viewer_may_see_warning($user)) {
        return '';
    }
    $labels = bakery_pan_carry_labels_for_date($db, $date);
    if ($labels === []) {
        return '';
    }
    $canRemove = bakery_route_load_viewer_may_see_warning($user);
    $html = '<div class="pan-carry-board" data-pan-carry="1">';
    foreach ($labels as $row) {
        $name = '';
        $nameStmt = $db->prepare('SELECT name FROM customers WHERE id = ?');
        $nameStmt->execute([(int)$row['customer_id']]);
        $name = (string)($nameStmt->fetchColumn() ?: '');
        $html .= '<div class="pan-carry-row">';
        $html .= '<span class="pan-carry-label">' . bakery_route_load_h((string)$row['label']) . '</span> ';
        $html .= '<span class="pan-carry-store">' . bakery_route_load_h($name) . '</span>';
        if ($canRemove) {
            $html .= bakery_pan_carry_remove_form((int)$row['id'], $date);
        }
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Label for one stop. The remove control is only for an admin or manager.
 *
 * @param array<string, mixed> $stop
 * @param array<string, mixed>|null $user
 */
function bakery_pan_carry_stop_badge_html(array $stop, string $date, ?array $user = null): string
{
    $label = trim((string)($stop['carry_label'] ?? ''));
    if ($label === '') {
        return '';
    }
    $html = '<span class="pan-carry-label">' . bakery_route_load_h($label) . '</span>';
    $carryId = (int)($stop['carry_id'] ?? 0);
    if ($carryId > 0 && bakery_route_load_viewer_may_see_warning($user)) {
        $html .= bakery_pan_carry_remove_form($carryId, $date);
    }
    return $html;
}

function bakery_pan_carry_remove_form(int $carryId, string $date): string
{
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    $label = bakery_route_load_text('pan_carry.remove', [], 'Remove carried stop');
    return '<form method="post" class="pan-carry-remove-form">'
        . $csrf
        . '<input type="hidden" name="action" value="remove_pan_carry">'
        . '<input type="hidden" name="carry_id" value="' . $carryId . '">'
        . '<input type="hidden" name="operating_date" value="' . bakery_route_load_h($date) . '">'
        . '<input type="hidden" name="date" value="' . bakery_route_load_h($date) . '">'
        . '<button type="submit" class="pan-carry-remove">' . bakery_route_load_h($label) . '</button>'
        . '</form>';
}

/**
 * @param array<string, mixed>|null $user
 */
function bakery_route_day_flags_html(PDO $db, string $date, ?array $user = null, int $onlyDriverId = 0): string
{
    return bakery_route_load_warning_html($db, $date, $user, $onlyDriverId)
        . bakery_pan_carry_board_html($db, $date, $user);
}
