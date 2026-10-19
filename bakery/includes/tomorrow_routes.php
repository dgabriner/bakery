<?php
/**
 * Tomorrow's route board for a manager.
 * Reads dated assignments and standing stops. Writes only through
 * bakery_tomorrow_routes_apply, and only when the method is POST.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/surveys.php';
require_once __DIR__ . '/daily_order_generation.php';
require_once __DIR__ . '/delivery_skip.php';
require_once __DIR__ . '/cron_run.php';

function bakery_tomorrow_routes_default_date(?DateTimeImmutable $now = null): string
{
    $now = ($now ?? bakery_cron_now())->setTimezone(bakery_cron_timezone());
    return $now->modify('+1 day')->format('Y-m-d');
}

function bakery_tomorrow_routes_resolve_date(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return bakery_tomorrow_routes_default_date();
    }
    try {
        return bakery_survey_validate_ymd($raw);
    } catch (RuntimeException $e) {
        return bakery_tomorrow_routes_default_date();
    }
}

function bakery_tomorrow_routes_weekday(string $date): int
{
    return (int)(new DateTimeImmutable(bakery_survey_validate_ymd($date)))->format('N');
}

function bakery_tomorrow_routes_zone_label(string $zone): string
{
    $zone = trim($zone);
    if ($zone === '') {
        return bakery_t('tomorrow_routes.no_zone');
    }
    return $zone;
}

/**
 * @return array<int, true>
 */
function bakery_tomorrow_routes_assigned_customer_ids(PDO $db, string $date): array
{
    $stmt = $db->prepare(
        'SELECT dord.customer_id
         FROM daily_order_assignments doa
         JOIN daily_orders dord ON dord.id = doa.daily_order_id
         WHERE doa.delivery_date = ? AND dord.order_date = doa.delivery_date'
    );
    $stmt->execute([$date]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $customerId) {
        $ids[(int)$customerId] = true;
    }
    return $ids;
}

function bakery_tomorrow_routes_origin_sql(PDO $db): string
{
    if (!function_exists('bakery_sfb_ops_origin_clause')) {
        $originFile = __DIR__ . '/sfb_origin.php';
        if (is_file($originFile)) {
            require_once $originFile;
        }
    }
    if (!function_exists('bakery_sfb_ops_origin_clause')) {
        return '';
    }
    return bakery_sfb_ops_origin_clause('c', $db);
}

/**
 * @return list<array{customer_id:int,customer_name:string,zone:string,daily_order_id:int}>
 */
function bakery_tomorrow_routes_unassigned(PDO $db, string $date): array
{
    $origin = bakery_tomorrow_routes_origin_sql($db);
    $stmt = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, COALESCE(c.zone, '') AS zone,
                dord.id AS daily_order_id
         FROM daily_orders dord
         JOIN customers c ON c.id = dord.customer_id
         WHERE dord.order_date = ?
           AND c.is_active = 1
           {$origin}
           AND NOT EXISTS (
               SELECT 1 FROM daily_order_assignments doa
               WHERE doa.daily_order_id = dord.id AND doa.delivery_date = dord.order_date
           )
         ORDER BY c.name"
    );
    $stmt->execute([$date]);
    return bakery_tomorrow_routes_cast_customers($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Standing order for the weekday, no standing route that day, and no dated order.
 *
 * @return list<array{customer_id:int,customer_name:string,zone:string,daily_order_id:int}>
 */
function bakery_tomorrow_routes_orphans(PDO $db, string $date): array
{
    $date = bakery_survey_validate_ymd($date);
    $weekday = bakery_tomorrow_routes_weekday($date);
    $origin = bakery_tomorrow_routes_origin_sql($db);
    $stmt = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, COALESCE(c.zone, '') AS zone,
                0 AS daily_order_id
         FROM customers c
         WHERE c.is_active = 1
           {$origin}
           AND EXISTS (
               SELECT 1 FROM standing_orders so
               WHERE so.customer_id = c.id
                 AND so.quantity > 0
                 AND CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END = ?
           )
           AND NOT EXISTS (
               SELECT 1 FROM standing_routes sr
               WHERE sr.customer_id = c.id
                 AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
           )
           AND NOT EXISTS (
               SELECT 1 FROM daily_orders dord
               WHERE dord.customer_id = c.id AND dord.order_date = ?
           )
         ORDER BY c.name"
    );
    $stmt->execute([$weekday, $weekday, $date]);
    return bakery_tomorrow_routes_cast_customers($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Standing stops taken off a driver without creating an order, grouped by zone.
 *
 * @return array<string, list<array{customer_id:int,customer_name:string,zone:string,daily_order_id:int}>>
 */
function bakery_tomorrow_routes_suggestions(PDO $db, string $date): array
{
    if (!table_exists($db, 'dated_route_removals')) {
        return [];
    }
    $origin = bakery_tomorrow_routes_origin_sql($db);
    $stmt = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, COALESCE(c.zone, '') AS zone,
                0 AS daily_order_id
         FROM dated_route_removals r
         JOIN customers c ON c.id = r.customer_id
         WHERE r.delivery_date = ?
           AND c.is_active = 1
           {$origin}
           AND NOT EXISTS (
               SELECT 1 FROM daily_orders dord
               WHERE dord.customer_id = c.id AND dord.order_date = r.delivery_date
           )
         ORDER BY c.zone, c.name"
    );
    $stmt->execute([$date]);
    $grouped = [];
    foreach (bakery_tomorrow_routes_cast_customers($stmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
        $grouped[bakery_tomorrow_routes_zone_label((string)$row['zone'])][] = $row;
    }
    return $grouped;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array{customer_id:int,customer_name:string,zone:string,daily_order_id:int}>
 */
function bakery_tomorrow_routes_cast_customers(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $customerId = (int)($row['customer_id'] ?? 0);
        if ($customerId <= 0) {
            continue;
        }
        $out[] = [
            'customer_id' => $customerId,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'zone' => (string)($row['zone'] ?? ''),
            'daily_order_id' => (int)($row['daily_order_id'] ?? 0),
        ];
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_tomorrow_routes_driver_stops(PDO $db, int $driverId, string $date): array
{
    $date = bakery_survey_validate_ymd($date);
    $origin = bakery_tomorrow_routes_origin_sql($db);
    $stmt = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, COALESCE(c.zone, '') AS zone,
                dord.id AS daily_order_id, doa.delivery_status, doa.route_order
         FROM daily_order_assignments doa
         JOIN daily_orders dord ON dord.id = doa.daily_order_id AND dord.order_date = doa.delivery_date
         JOIN customers c ON c.id = dord.customer_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
           AND c.is_active = 1
           {$origin}
         ORDER BY doa.route_order, c.name"
    );
    $stmt->execute([$driverId, $date]);
    $stops = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        $seen[$customerId] = true;
        $status = (string)($row['delivery_status'] ?? 'pending');
        $stops[] = [
            'customer_id' => $customerId,
            'customer_name' => (string)$row['customer_name'],
            'zone' => (string)$row['zone'],
            'daily_order_id' => (int)$row['daily_order_id'],
            'delivery_status' => $status,
            'skipped' => $status === 'cancelled',
            'locked' => in_array($status, ['delivered', 'in_transit'], true),
            'route_order' => (int)$row['route_order'],
        ];
    }

    $weekday = bakery_tomorrow_routes_weekday($date);
    $assigned = bakery_tomorrow_routes_assigned_customer_ids($db, $date);
    $removed = function_exists('bakery_dated_route_removed_customer_ids')
        ? bakery_dated_route_removed_customer_ids($db, $date)
        : [];
    $standing = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, COALESCE(c.zone, '') AS zone,
                sr.route_order
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id
         WHERE sr.driver_id = ?
           AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
           AND c.is_active = 1
           {$origin}
         ORDER BY sr.route_order, c.name"
    );
    $standing->execute([$driverId, $weekday]);
    foreach ($standing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        if ($customerId <= 0 || isset($seen[$customerId]) || isset($assigned[$customerId]) || isset($removed[$customerId])) {
            continue;
        }
        $stops[] = [
            'customer_id' => $customerId,
            'customer_name' => (string)$row['customer_name'],
            'zone' => (string)$row['zone'],
            'daily_order_id' => 0,
            'delivery_status' => 'standing',
            'skipped' => false,
            'locked' => false,
            'route_order' => (int)($row['route_order'] ?? 0),
        ];
    }
    return $stops;
}

/**
 * @param list<array<string, mixed>> $stops
 * @return array<string, list<array<string, mixed>>>
 */
function bakery_tomorrow_routes_group_zones(array $stops): array
{
    $zones = [];
    foreach ($stops as $stop) {
        $zones[bakery_tomorrow_routes_zone_label((string)($stop['zone'] ?? ''))][] = $stop;
    }
    return $zones;
}

/**
 * @return array<string, mixed>
 */
function bakery_tomorrow_routes_board(PDO $db, string $date): array
{
    $date = bakery_survey_validate_ymd($date);
    $choices = bakery_survey_driver_choices($db);
    $drivers = [];
    foreach ($choices as $choice) {
        $driverId = (int)($choice['id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $stops = bakery_tomorrow_routes_driver_stops($db, $driverId, $date);
        $drivers[] = [
            'id' => $driverId,
            'name' => (string)($choice['name'] ?? ''),
            'stops' => $stops,
            'zones' => bakery_tomorrow_routes_group_zones($stops),
        ];
    }
    return [
        'date' => $date,
        'is_tomorrow' => $date === bakery_tomorrow_routes_default_date(),
        'unassigned' => bakery_tomorrow_routes_unassigned($db, $date),
        'orphans' => bakery_tomorrow_routes_orphans($db, $date),
        'suggestions' => bakery_tomorrow_routes_suggestions($db, $date),
        'drivers' => $drivers,
        'driver_choices' => $choices,
    ];
}

/**
 * Dated stops for one driver, plus standing stops nobody has taken and nobody removed.
 * Same rows Daily Route day view renders.
 *
 * @return list<array<string, mixed>>
 */
function bakery_daily_route_day_rows(PDO $db, int $driverId, string $date): array
{
    if ($driverId <= 0) {
        return [];
    }
    $date = bakery_survey_validate_ymd($date);
    $weekday = bakery_tomorrow_routes_weekday($date);
    $origin = bakery_tomorrow_routes_origin_sql($db);
    $stmt = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, c.address, c.phone, c.email,
                c.deliver_after, c.deliver_by, c.delivery_time AS stop_duration,
                doa.route_order AS standing_route_order,
                doa.scheduled_delivery_time AS scheduled_time,
                doa.estimated_delivery_time AS estimated_time,
                COALESCE(doa.scheduled_delivery_time, doa.estimated_delivery_time, dord.delivery_time) AS route_time,
                dord.id AS daily_order_id
         FROM daily_order_assignments doa
         JOIN daily_orders dord ON dord.id = doa.daily_order_id AND dord.order_date = doa.delivery_date
         JOIN customers c ON c.id = dord.customer_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
           AND doa.delivery_status <> 'cancelled'
           AND c.is_active = 1
           {$origin}
         ORDER BY doa.route_order, c.name"
    );
    $stmt->execute([$driverId, $date]);
    $rows = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        $seen[$customerId] = true;
        $rows[] = bakery_daily_route_shape_row($db, $row, $weekday);
    }

    $assigned = bakery_tomorrow_routes_assigned_customer_ids($db, $date);
    $removed = function_exists('bakery_dated_route_removed_customer_ids')
        ? bakery_dated_route_removed_customer_ids($db, $date)
        : [];
    $standing = $db->prepare(
        "SELECT c.id AS customer_id, c.name AS customer_name, c.address, c.phone, c.email,
                c.deliver_after, c.deliver_by, c.delivery_time AS stop_duration,
                sr.route_order AS standing_route_order,
                NULL AS scheduled_time, NULL AS estimated_time, NULL AS route_time,
                0 AS daily_order_id
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id
         WHERE sr.driver_id = ?
           AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
           AND c.is_active = 1
           {$origin}
         ORDER BY sr.route_order, c.name"
    );
    $standing->execute([$driverId, $weekday]);
    foreach ($standing->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)$row['customer_id'];
        if ($customerId <= 0 || isset($seen[$customerId]) || isset($assigned[$customerId]) || isset($removed[$customerId])) {
            continue;
        }
        $rows[] = bakery_daily_route_shape_row($db, $row, $weekday);
    }
    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function bakery_daily_route_shape_row(PDO $db, array $row, int $weekday): array
{
    $customerId = (int)($row['customer_id'] ?? 0);
    $dailyOrderId = (int)($row['daily_order_id'] ?? 0);
    $row['orders'] = bakery_daily_route_orders_blob($db, $customerId, $dailyOrderId, $weekday);
    return $row;
}

function bakery_daily_route_orders_blob(PDO $db, int $customerId, int $dailyOrderId, int $weekday): string
{
    if ($dailyOrderId > 0) {
        $stmt = $db->prepare(
            "SELECT COALESCE(dt.name, 'Unclassified') AS dough, p.name AS product, doi.quantity
             FROM daily_order_items doi
             JOIN products p ON p.id = doi.product_id
             LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
             WHERE doi.daily_order_id = ? AND doi.quantity > 0
             ORDER BY dough, p.name"
        );
        $stmt->execute([$dailyOrderId]);
        $blob = bakery_daily_route_orders_from_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        if ($blob !== '') {
            return $blob;
        }
    }
    $stmt = $db->prepare(
        "SELECT COALESCE(dt.name, 'Unclassified') AS dough, p.name AS product, so.quantity
         FROM standing_orders so
         JOIN products p ON p.id = so.product_id
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         WHERE so.customer_id = ?
           AND CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END = ?
           AND so.quantity > 0
         ORDER BY dough, p.name"
    );
    $stmt->execute([$customerId, $weekday]);
    return bakery_daily_route_orders_from_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_daily_route_orders_from_rows(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $parts[] = (string)$row['dough'] . '|' . (string)$row['product'] . ' (' . (int)$row['quantity'] . ')';
    }
    return implode('||', $parts);
}

/**
 * @return array{ok:bool,code:string}
 */
function bakery_tomorrow_routes_apply(PDO $db, string $method, array $post, string $date = ''): array
{
    if (strtoupper($method) !== 'POST') {
        return ['ok' => false, 'code' => 'read_only'];
    }
    $action = (string)($post['action'] ?? '');
    $rawDate = $date !== '' ? $date : (string)($post['date'] ?? '');
    try {
        $date = bakery_survey_validate_ymd($rawDate);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'code' => 'bad_date'];
    }
    $customerId = (int)($post['customer_id'] ?? 0);
    $driverId = (int)($post['driver_id'] ?? 0);
    switch ($action) {
        case 'add':
        case 'move':
            return bakery_tomorrow_routes_assign($db, $driverId, $date, $customerId);
        case 'skip':
            return bakery_tomorrow_routes_skip(
                $db,
                $date,
                (int)($post['daily_order_id'] ?? 0),
                $customerId,
                (string)($post['reason'] ?? ''),
                $driverId
            );
        case 'driver_off':
            return bakery_tomorrow_routes_driver_off($db, $driverId, $date);
        default:
            return ['ok' => false, 'code' => 'unknown_action'];
    }
}

/**
 * @return array{ok:bool,code:string}
 */
function bakery_tomorrow_routes_assign(PDO $db, int $driverId, string $date, int $customerId): array
{
    $result = bakery_survey_store_verify_assign_customer($db, $driverId, $date, $customerId);
    if (empty($result['ok'])) {
        return ['ok' => false, 'code' => (string)($result['code'] ?? 'assign_failed')];
    }
    if (function_exists('bakery_dated_route_removal_clear')) {
        bakery_dated_route_removal_clear($db, $date, $customerId);
    }
    return ['ok' => true, 'code' => (string)($result['code'] ?? 'added')];
}

/**
 * @return array{ok:bool,code:string}
 */
function bakery_tomorrow_routes_skip(
    PDO $db,
    string $date,
    int $dailyOrderId,
    int $customerId,
    string $reason,
    int $driverId
): array {
    if ($dailyOrderId <= 0 && $customerId > 0) {
        $stmt = $db->prepare('SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1');
        $stmt->execute([$customerId, $date]);
        $dailyOrderId = (int)$stmt->fetchColumn();
    }
    if ($dailyOrderId <= 0) {
        if ($customerId <= 0) {
            return ['ok' => false, 'code' => 'bad_ids'];
        }
        if (function_exists('bakery_dated_route_removals_ready')) {
            bakery_dated_route_removals_ready($db);
        }
        bakery_dated_route_removal_record($db, $date, $customerId, $driverId);
        return ['ok' => true, 'code' => 'tombstone'];
    }
    $reason = trim($reason);
    if ($reason === '') {
        $reason = 'Saltar';
    }
    try {
        bakery_skip_delivery_stop($db, $dailyOrderId, $reason);
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'skip_failed'];
    }
    return ['ok' => true, 'code' => 'skipped'];
}

/**
 * @return array{ok:bool,code:string}
 */
function bakery_tomorrow_routes_driver_off(PDO $db, int $driverId, string $date): array
{
    if ($driverId <= 0) {
        return ['ok' => false, 'code' => 'bad_ids'];
    }
    $date = bakery_survey_validate_ymd($date);
    $weekday = bakery_tomorrow_routes_weekday($date);
    if (function_exists('bakery_dated_route_removals_ready')) {
        bakery_dated_route_removals_ready($db);
    }
    $pending = $db->prepare(
        "SELECT dord.customer_id, doa.daily_order_id, doa.delivery_status
         FROM daily_order_assignments doa
         JOIN daily_orders dord ON dord.id = doa.daily_order_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ?"
    );
    $pending->execute([$driverId, $date]);
    foreach ($pending->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)($row['delivery_status'] ?? 'pending');
        if (in_array($status, ['delivered', 'in_transit'], true)) {
            continue;
        }
        bakery_survey_store_verify_unassign_customer(
            $db,
            $driverId,
            $date,
            (int)$row['customer_id'],
            (int)$row['daily_order_id']
        );
    }

    $origin = bakery_tomorrow_routes_origin_sql($db);
    $standing = $db->prepare(
        "SELECT sr.customer_id
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id
         WHERE sr.driver_id = ?
           AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
           AND c.is_active = 1
           {$origin}"
    );
    $standing->execute([$driverId, $weekday]);
    $assigned = bakery_tomorrow_routes_assigned_customer_ids($db, $date);
    $removed = bakery_dated_route_removed_customer_ids($db, $date);
    foreach ($standing->fetchAll(PDO::FETCH_COLUMN) as $customerId) {
        $customerId = (int)$customerId;
        if ($customerId <= 0 || isset($assigned[$customerId]) || isset($removed[$customerId])) {
            continue;
        }
        bakery_dated_route_removal_record($db, $date, $customerId, $driverId);
    }
    return ['ok' => true, 'code' => 'driver_off'];
}

function bakery_tomorrow_routes_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bakery_tomorrow_routes_action(string $date): string
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    return $base . 'tomorrow_routes.php?date=' . rawurlencode($date);
}

function bakery_tomorrow_routes_driver_options(array $choices, int $exceptId = 0): string
{
    $html = '<option value="">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.pick_driver')) . '</option>';
    foreach ($choices as $choice) {
        if (!is_array($choice)) {
            continue;
        }
        $id = (int)($choice['id'] ?? 0);
        if ($id <= 0 || $id === $exceptId) {
            continue;
        }
        $html .= '<option value="' . $id . '">' . bakery_tomorrow_routes_h((string)($choice['name'] ?? '')) . '</option>';
    }
    return $html;
}

/**
 * @param array{customer_id:int,customer_name:string,zone:string,daily_order_id:int} $customer
 * @param list<array<string, mixed>> $choices
 */
function bakery_tomorrow_routes_add_form(string $date, array $customer, array $choices): string
{
    $customerId = (int)$customer['customer_id'];
    $selectId = 'tr-add-' . $customerId;
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    return '<form method="post" action="' . bakery_tomorrow_routes_h(bakery_tomorrow_routes_action($date)) . '">'
        . $csrf
        . '<input type="hidden" name="action" value="add">'
        . '<input type="hidden" name="date" value="' . bakery_tomorrow_routes_h($date) . '">'
        . '<input type="hidden" name="customer_id" value="' . $customerId . '">'
        . '<fieldset class="tr-actions">'
        . '<legend>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.add')) . '</legend>'
        . '<label for="' . $selectId . '">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.pick_driver')) . '</label>'
        . '<select id="' . $selectId . '" name="driver_id" required>'
        . bakery_tomorrow_routes_driver_options($choices)
        . '</select>'
        . '<button type="submit">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.save')) . '</button>'
        . '</fieldset></form>';
}

/**
 * @param array<string, mixed> $stop
 * @param list<array<string, mixed>> $choices
 */
function bakery_tomorrow_routes_stop_forms(string $date, array $stop, array $choices, int $driverId): string
{
    if (!empty($stop['locked'])) {
        return '';
    }
    $customerId = (int)$stop['customer_id'];
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    $html = '';
    if (empty($stop['skipped'])) {
        $reasonId = 'tr-skip-' . $driverId . '-' . $customerId;
        $html .= '<form method="post" action="' . bakery_tomorrow_routes_h(bakery_tomorrow_routes_action($date)) . '">'
            . $csrf
            . '<input type="hidden" name="action" value="skip">'
            . '<input type="hidden" name="date" value="' . bakery_tomorrow_routes_h($date) . '">'
            . '<input type="hidden" name="customer_id" value="' . $customerId . '">'
            . '<input type="hidden" name="driver_id" value="' . $driverId . '">'
            . '<input type="hidden" name="daily_order_id" value="' . (int)($stop['daily_order_id'] ?? 0) . '">'
            . '<fieldset class="tr-actions">'
            . '<legend>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.skip')) . '</legend>'
            . '<label for="' . $reasonId . '">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.reason')) . '</label>'
            . '<input id="' . $reasonId . '" name="reason" type="text" maxlength="500">'
            . '<button type="submit">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.save')) . '</button>'
            . '</fieldset></form>';
    }
    $moveId = 'tr-move-' . $driverId . '-' . $customerId;
    $html .= '<form method="post" action="' . bakery_tomorrow_routes_h(bakery_tomorrow_routes_action($date)) . '">'
        . $csrf
        . '<input type="hidden" name="action" value="move">'
        . '<input type="hidden" name="date" value="' . bakery_tomorrow_routes_h($date) . '">'
        . '<input type="hidden" name="customer_id" value="' . $customerId . '">'
        . '<fieldset class="tr-actions">'
        . '<legend>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.move')) . '</legend>'
        . '<label for="' . $moveId . '">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.pick_driver')) . '</label>'
        . '<select id="' . $moveId . '" name="driver_id" required>'
        . bakery_tomorrow_routes_driver_options($choices, $driverId)
        . '</select>'
        . '<button type="submit">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.save')) . '</button>'
        . '</fieldset></form>';
    return $html;
}

/**
 * @param list<array{customer_id:int,customer_name:string,zone:string,daily_order_id:int}> $rows
 * @param list<array<string, mixed>> $choices
 */
function bakery_tomorrow_routes_loose_list(string $date, array $rows, array $choices): string
{
    $html = '';
    foreach ($rows as $row) {
        $html .= '<article class="tr-card">'
            . '<h3>' . bakery_tomorrow_routes_h((string)$row['customer_name']) . '</h3>'
            . '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.zone')) . ': '
            . bakery_tomorrow_routes_h(bakery_tomorrow_routes_zone_label((string)$row['zone'])) . '</p>'
            . bakery_tomorrow_routes_add_form($date, $row, $choices)
            . '</article>';
    }
    return $html;
}

/**
 * @param array<string, mixed> $board
 */
function bakery_tomorrow_routes_render_loose(array $board): string
{
    $date = (string)$board['date'];
    $choices = is_array($board['driver_choices'] ?? null) ? $board['driver_choices'] : [];
    $html = '';
    $unassigned = is_array($board['unassigned'] ?? null) ? $board['unassigned'] : [];
    $orphans = is_array($board['orphans'] ?? null) ? $board['orphans'] : [];
    $suggestions = is_array($board['suggestions'] ?? null) ? $board['suggestions'] : [];
    if ($unassigned !== []) {
        $html .= '<section class="tr-section" aria-labelledby="tr-unassigned">'
            . '<h2 id="tr-unassigned">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.unassigned_title')) . '</h2>'
            . bakery_tomorrow_routes_loose_list($date, $unassigned, $choices)
            . '</section>';
    }
    if ($orphans !== []) {
        $html .= '<section class="tr-section" aria-labelledby="tr-orphans">'
            . '<h2 id="tr-orphans">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.orphan_title')) . '</h2>'
            . '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.orphan_help')) . '</p>'
            . bakery_tomorrow_routes_loose_list($date, $orphans, $choices)
            . '</section>';
    }
    if ($suggestions !== []) {
        $html .= '<section class="tr-section" aria-labelledby="tr-suggestions">'
            . '<h2 id="tr-suggestions">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.suggestions')) . '</h2>';
        foreach ($suggestions as $zone => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $html .= '<h3>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.zone')) . ': '
                . bakery_tomorrow_routes_h((string)$zone) . '</h3>'
                . bakery_tomorrow_routes_loose_list($date, $rows, $choices);
        }
        $html .= '</section>';
    }
    if ($html === '') {
        $html = '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.none_open')) . '</p>';
    }
    return $html;
}

function bakery_tomorrow_routes_loose_html(PDO $db, string $date): string
{
    return bakery_tomorrow_routes_render_loose(bakery_tomorrow_routes_board($db, $date));
}

/**
 * @param array<string, mixed> $board
 */
function bakery_tomorrow_routes_render(array $board): string
{
    $date = (string)$board['date'];
    $choices = is_array($board['driver_choices'] ?? null) ? $board['driver_choices'] : [];
    $when = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $label = $when instanceof DateTimeImmutable && function_exists('bakery_localized_date_label')
        ? bakery_localized_date_label($when, true)
        : $date;
    $html = '<div class="tr-page">';
    if (!empty($board['saved'])) {
        $html .= '<p class="tr-saved" role="status">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.saved')) . '</p>';
    }
    if (!empty($board['is_tomorrow'])) {
        $html .= '<p class="tr-kicker">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.kicker')) . '</p>';
    }
    $html .= '<h1>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.title')) . '</h1>'
        . '<p class="tr-date">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.date_label'))
        . ' <time datetime="' . bakery_tomorrow_routes_h($date) . '">'
        . bakery_tomorrow_routes_h($label) . '</time></p>';
    $html .= bakery_tomorrow_routes_render_loose($board);
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    foreach ($board['drivers'] ?? [] as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $driverId = (int)($driver['id'] ?? 0);
        $name = (string)($driver['name'] ?? '');
        $html .= '<section class="tr-section" aria-labelledby="tr-driver-' . $driverId . '">'
            . '<h2 id="tr-driver-' . $driverId . '">'
            . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.driver_stops', ['name' => $name]))
            . '</h2>';
        $zones = is_array($driver['zones'] ?? null) ? $driver['zones'] : [];
        if ($zones === []) {
            $html .= '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.empty_driver')) . '</p>';
        }
        foreach ($zones as $zone => $stops) {
            $html .= '<h3>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.zone')) . ': '
                . bakery_tomorrow_routes_h((string)$zone) . '</h3>';
            if (!is_array($stops)) {
                continue;
            }
            foreach ($stops as $stop) {
                if (!is_array($stop)) {
                    continue;
                }
                $html .= '<article class="tr-card"><h4>'
                    . bakery_tomorrow_routes_h((string)($stop['customer_name'] ?? ''))
                    . '</h4>';
                if (!empty($stop['skipped'])) {
                    $html .= '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.skipped')) . '</p>';
                }
                $html .= bakery_tomorrow_routes_stop_forms($date, $stop, $choices, $driverId)
                    . '</article>';
            }
        }
        $html .= '<form method="post" action="' . bakery_tomorrow_routes_h(bakery_tomorrow_routes_action($date)) . '">'
            . $csrf
            . '<input type="hidden" name="action" value="driver_off">'
            . '<input type="hidden" name="date" value="' . bakery_tomorrow_routes_h($date) . '">'
            . '<input type="hidden" name="driver_id" value="' . $driverId . '">'
            . '<fieldset class="tr-actions">'
            . '<legend>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.driver_off', ['name' => $name])) . '</legend>'
            . '<p>' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.driver_off_help')) . '</p>'
            . '<button type="submit">' . bakery_tomorrow_routes_h(bakery_t('tomorrow_routes.save')) . '</button>'
            . '</fieldset></form></section>';
    }
    $html .= '</div>';
    return $html;
}
