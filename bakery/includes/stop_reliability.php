<?php
/**
 * Stop-reliability flags for the Driver Manager view.
 *
 * One compact panel on Route Manager (Driver Manager home), Driver Assignment,
 * and Route Health. Managers and administrators see it. Drivers do not.
 *
 * A real delivery is a clean After photo, the same mark as
 * bakery_route_stop_has_after_photo() in includes/route_stop_proof.php.
 * A Before photo is arrival. A receipt is not an After photo.
 *
 * Hold next order is not here. There is no existing hold-next field.
 * standing_fill_blocked is one dated order, and customer_delivery_skips
 * is a new row for a chosen date. That stays a follow-up.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/route_stop_proof.php';

const BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER = 4;
const BAKERY_STOP_RELIABILITY_MISS_MIN = 2;
const BAKERY_STOP_RELIABILITY_LOOKBACK_DAYS = 60;
const BAKERY_STOP_RELIABILITY_FORWARD_DAYS = 14;
const BAKERY_STOP_RELIABILITY_NOTE_MIN = 3;
const BAKERY_STOP_RELIABILITY_NOTE_MAX = 240;

function bakery_stop_reliability_can_view(?array $user): bool
{
    if ($user === null) {
        return false;
    }
    $role = strtolower(trim((string)($user['role_slug'] ?? '')));
    return $role === 'administrator' || $role === 'manager';
}

function bakery_stop_reliability_today(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
}

function bakery_stop_reliability_schema_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'stop_reliability_actions');
}

function bakery_stop_reliability_notes_are_skip(string $notes): bool
{
    return preg_match('/(^|\n)\s*Skipped:/i', $notes) === 1;
}

/**
 * @param array<int, mixed> $photos
 */
function bakery_stop_reliability_has_any_photo(array $photos): bool
{
    foreach ($photos as $photo) {
        if (is_array($photo)) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $stop
 */
function bakery_stop_reliability_is_closed(array $stop): bool
{
    $status = (string)($stop['delivery_status'] ?? '');
    if ($status === 'delivered') {
        return true;
    }
    $orderStatus = (string)($stop['order_status'] ?? '');
    if (in_array($orderStatus, ['delivered', 'invoiced'], true)) {
        return true;
    }
    $confirmed = trim((string)($stop['delivery_confirmed_at'] ?? ''));
    if ($confirmed !== '' && $confirmed !== '0000-00-00 00:00:00') {
        return true;
    }
    $actual = trim((string)($stop['actual_delivery_time'] ?? ''));
    return $actual !== '' && $actual !== '00:00:00';
}

/**
 * future and ignore are not scheduled misses. real clears the open streak.
 * break ends the streak without counting as a delivery. miss extends it.
 *
 * @param array<string, mixed> $stop
 */
function bakery_stop_reliability_stop_kind(array $stop, string $today): string
{
    $date = (string)($stop['date'] ?? '');
    if ($date === '' || $date >= $today) {
        return 'future';
    }
    if (empty($stop['on_route'])) {
        return 'ignore';
    }
    $photos = $stop['photos'] ?? [];
    if (!is_array($photos)) {
        $photos = [];
    }
    if (bakery_route_stop_has_after_photo($photos)) {
        return 'real';
    }
    $notes = (string)($stop['notes'] ?? '');
    $status = (string)($stop['delivery_status'] ?? 'pending');
    if (!empty($stop['skipped']) || $status === 'cancelled' || bakery_stop_reliability_notes_are_skip($notes)) {
        return 'break';
    }
    if (trim($notes) !== '') {
        return 'break';
    }
    if (bakery_stop_reliability_has_any_photo($photos)) {
        return 'break';
    }
    if (bakery_stop_reliability_is_closed($stop)) {
        return 'break';
    }
    if (in_array($status, ['failed', 'in_transit', 'rescheduled'], true)) {
        return 'break';
    }
    return 'miss';
}

/**
 * @param list<array<string, mixed>> $stops
 * @return array{dates:list<string>,last_real:?string,count:int}
 */
function bakery_stop_reliability_open_streak(array $stops, string $today): array
{
    usort($stops, static function (array $a, array $b): int {
        return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
    });
    $streak = [];
    $lastReal = null;
    foreach ($stops as $stop) {
        $kind = bakery_stop_reliability_stop_kind($stop, $today);
        if ($kind === 'future' || $kind === 'ignore') {
            continue;
        }
        if ($kind === 'real') {
            $lastReal = (string)$stop['date'];
            $streak = [];
            continue;
        }
        if ($kind === 'break') {
            $streak = [];
            continue;
        }
        if ($kind === 'miss') {
            $streak[] = (string)$stop['date'];
        }
    }
    return [
        'dates' => $streak,
        'last_real' => $lastReal,
        'count' => count($streak),
    ];
}

/**
 * @param list<array{date:string,pieces:int}> $orders
 * @param list<int> $standingDays ISO weekdays with a positive standing order
 * @return list<array{date:string,pieces:int}>
 */
function bakery_stop_reliability_off_standing_dates(array $orders, array $standingDays): array
{
    $days = [];
    foreach ($standingDays as $day) {
        $day = (int)$day;
        if ($day === 0) {
            $day = 7;
        }
        if ($day >= 1 && $day <= 7) {
            $days[$day] = true;
        }
    }
    $out = [];
    foreach ($orders as $order) {
        $date = (string)($order['date'] ?? '');
        $pieces = (int)($order['pieces'] ?? 0);
        if ($pieces <= 0 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            continue;
        }
        $weekday = function_exists('bakery_standing_day_from_date')
            ? (int)bakery_standing_day_from_date($date)
            : (int)date('N', strtotime($date));
        if (isset($days[$weekday])) {
            continue;
        }
        $out[] = ['date' => $date, 'pieces' => $pieces];
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp($a['date'], $b['date']);
    });
    return $out;
}

/**
 * @param list<string> $missDates
 * @param list<array{date:string,pieces:int}> $offDates
 */
function bakery_stop_reliability_fingerprint(array $missDates, array $offDates): string
{
    $off = [];
    foreach ($offDates as $row) {
        $off[] = (string)$row['date'] . ':' . (int)$row['pieces'];
    }
    return sha1(implode(',', $missDates) . '|' . implode(',', $off));
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function bakery_stop_reliability_sort(array $rows): array
{
    usort($rows, static function (array $a, array $b): int {
        $count = (int)($b['consecutive'] ?? 0) <=> (int)($a['consecutive'] ?? 0);
        if ($count !== 0) {
            return $count;
        }
        $recent = strcmp((string)($b['recency'] ?? ''), (string)($a['recency'] ?? ''));
        if ($recent !== 0) {
            return $recent;
        }
        return strcasecmp((string)($a['store'] ?? ''), (string)($b['store'] ?? ''));
    });
    return $rows;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array{visible:list<array<string, mixed>>,hidden:list<array<string, mixed>>}
 */
function bakery_stop_reliability_collapse(array $rows, ?int $limit = null): array
{
    $limit = $limit ?? BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER;
    if ($limit < 1) {
        $limit = BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER;
    }
    return [
        'visible' => array_slice($rows, 0, $limit),
        'hidden' => array_slice($rows, $limit),
    ];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed> $action
 */
function bakery_stop_reliability_action_hides(array $row, array $action, string $today): bool
{
    $kind = (string)($action['action'] ?? '');
    if ($kind === 'snooze') {
        $until = substr(trim((string)($action['snooze_until'] ?? '')), 0, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) === 1 && $today < $until;
    }
    if ($kind === 'acknowledge') {
        return (string)($action['flag_fingerprint'] ?? '') === (string)($row['fingerprint'] ?? '');
    }
    return false;
}

/**
 * @param list<array<string, mixed>> $assignments
 * @return array{delivery_status:string,notes:string,driver:string,actual_delivery_time:?string,on_route:bool}
 */
function bakery_stop_reliability_merge_assignments(array $assignments): array
{
    $notes = [];
    $driver = '';
    $status = 'pending';
    $actual = null;
    $rank = [
        'pending' => 0,
        'rescheduled' => 1,
        'in_transit' => 2,
        'failed' => 3,
        'cancelled' => 4,
        'delivered' => 5,
    ];
    foreach ($assignments as $row) {
        if (!is_array($row)) {
            continue;
        }
        $note = trim((string)($row['notes'] ?? ''));
        if ($note !== '') {
            $notes[] = $note;
        }
        $name = trim((string)($row['driver_name'] ?? ''));
        $next = (string)($row['delivery_status'] ?? 'pending');
        if ($next === '') {
            $next = 'pending';
        }
        if (($rank[$next] ?? 0) >= ($rank[$status] ?? 0)) {
            $status = $next;
            if ($name !== '') {
                $driver = $name;
            }
        } elseif ($driver === '' && $name !== '') {
            $driver = $name;
        }
        $time = trim((string)($row['actual_delivery_time'] ?? ''));
        if ($time !== '') {
            $actual = $time;
        }
    }
    return [
        'delivery_status' => $status,
        'notes' => implode("\n", $notes),
        'driver' => $driver,
        'actual_delivery_time' => $actual,
        'on_route' => $assignments !== [],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_stop_reliability_load(PDO $db, string $today): array
{
    $today = bakery_stop_reliability_valid_date($today);
    $from = (new DateTimeImmutable($today))->modify('-' . BAKERY_STOP_RELIABILITY_LOOKBACK_DAYS . ' days')->format('Y-m-d');
    $to = (new DateTimeImmutable($today))->modify('+' . BAKERY_STOP_RELIABILITY_FORWARD_DAYS . ' days')->format('Y-m-d');
    if (!function_exists('table_exists') || !table_exists($db, 'daily_orders') || !table_exists($db, 'customers')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $confirmed = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'do.delivery_confirmed_at'
        : 'NULL';
    $pieces = function_exists('table_exists') && table_exists($db, 'daily_order_items')
        ? '(SELECT COALESCE(SUM(doi.quantity), 0) FROM daily_order_items doi WHERE doi.daily_order_id = do.id)'
        : '0';
    $orderStmt = $db->prepare(
        "SELECT do.id, do.customer_id, do.order_date, do.status AS order_status,
                {$confirmed} AS delivery_confirmed_at,
                c.name AS store_name, c.address,
                {$pieces} AS pieces
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id AND c.is_active = 1
         WHERE do.order_date BETWEEN ? AND ?
         {$origin}"
    );
    $orderStmt->execute([$from, $to]);
    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($orders === []) {
        return [];
    }

    $assignmentsByOrder = [];
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'drivers')) {
        $notes = function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes')
            ? 'doa.notes'
            : 'NULL';
        $assignStmt = $db->prepare(
            "SELECT doa.daily_order_id, doa.delivery_status, doa.actual_delivery_time,
                    {$notes} AS notes, d.name AS driver_name
             FROM daily_order_assignments doa
             JOIN drivers d ON d.id = doa.driver_id
             WHERE doa.delivery_date BETWEEN ? AND ?"
        );
        $assignStmt->execute([$from, $to]);
        foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignmentsByOrder[(int)$row['daily_order_id']][] = $row;
        }
    }

    $photosByStop = [];
    if (table_exists($db, 'driver_photos')) {
        $photoStmt = $db->prepare(
            'SELECT customer_id, delivery_date, filename, file_path, photo_type
             FROM driver_photos
             WHERE delivery_date BETWEEN ? AND ?'
        );
        $photoStmt->execute([$from, $to]);
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $key = (int)$photo['customer_id'] . '|' . substr((string)$photo['delivery_date'], 0, 10);
            $photosByStop[$key][] = $photo;
        }
    }

    $customerIds = [];
    foreach ($orders as $order) {
        $customerIds[(int)$order['customer_id']] = true;
    }
    $standingByCustomer = bakery_stop_reliability_standing_days($db, array_keys($customerIds));
    $skips = bakery_stop_reliability_skips($db, array_keys($customerIds), $from, $to);

    $byCustomer = [];
    foreach ($orders as $order) {
        $customerId = (int)$order['customer_id'];
        $date = substr((string)$order['order_date'], 0, 10);
        $merged = bakery_stop_reliability_merge_assignments($assignmentsByOrder[(int)$order['id']] ?? []);
        if (!isset($byCustomer[$customerId])) {
            $byCustomer[$customerId] = [
                'customer_id' => $customerId,
                'store' => (string)$order['store_name'],
                'address' => (string)($order['address'] ?? ''),
                'stops' => [],
                'orders' => [],
            ];
        }
        $byCustomer[$customerId]['orders'][] = [
            'date' => $date,
            'pieces' => (int)$order['pieces'],
        ];
        $byCustomer[$customerId]['stops'][] = [
            'date' => $date,
            'on_route' => $merged['on_route'],
            'delivery_status' => $merged['delivery_status'],
            'order_status' => (string)$order['order_status'],
            'delivery_confirmed_at' => $order['delivery_confirmed_at'],
            'actual_delivery_time' => $merged['actual_delivery_time'],
            'notes' => $merged['notes'],
            'skipped' => isset($skips[$customerId . '|' . $date]),
            'photos' => $photosByStop[$customerId . '|' . $date] ?? [],
            'driver' => $merged['driver'],
        ];
    }

    $rows = [];
    foreach ($byCustomer as $customer) {
        $streak = bakery_stop_reliability_open_streak($customer['stops'], $today);
        $off = bakery_stop_reliability_off_standing_dates(
            $customer['orders'],
            $standingByCustomer[(int)$customer['customer_id']] ?? []
        );
        if ($streak['count'] < BAKERY_STOP_RELIABILITY_MISS_MIN && $off === []) {
            continue;
        }
        $driver = '';
        $missSet = array_fill_keys($streak['dates'], true);
        foreach ($customer['stops'] as $stop) {
            if (isset($missSet[(string)$stop['date']]) && (string)$stop['driver'] !== '') {
                $driver = (string)$stop['driver'];
            }
        }
        if ($driver === '') {
            foreach ($customer['stops'] as $stop) {
                if ((string)$stop['driver'] !== '') {
                    $driver = (string)$stop['driver'];
                }
            }
        }
        $recency = '';
        foreach (array_merge($streak['dates'], array_column($off, 'date')) as $date) {
            if ($date > $recency) {
                $recency = $date;
            }
        }
        $rows[] = [
            'customer_id' => (int)$customer['customer_id'],
            'store' => (string)$customer['store'],
            'address' => (string)$customer['address'],
            'driver' => $driver,
            'consecutive' => (int)$streak['count'],
            'miss_dates' => $streak['dates'],
            'last_real' => $streak['last_real'],
            'off_dates' => $off,
            'recency' => $recency,
            'fingerprint' => bakery_stop_reliability_fingerprint($streak['dates'], $off),
        ];
    }
    return $rows;
}

/**
 * @param list<int> $customerIds
 * @return array<int, list<int>>
 */
function bakery_stop_reliability_standing_days(PDO $db, array $customerIds): array
{
    $customerIds = array_values(array_filter(array_map('intval', $customerIds), static function (int $id): bool {
        return $id > 0;
    }));
    if ($customerIds === [] || !function_exists('table_exists') || !table_exists($db, 'standing_orders')) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($customerIds), '?'));
    $stmt = $db->prepare(
        "SELECT customer_id, day_of_week
         FROM standing_orders
         WHERE quantity > 0 AND customer_id IN ($marks)"
    );
    $stmt->execute($customerIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $day = (int)$row['day_of_week'];
        if ($day === 0) {
            $day = 7;
        }
        $out[(int)$row['customer_id']][$day] = $day;
    }
    $lists = [];
    foreach ($out as $customerId => $days) {
        $lists[$customerId] = array_values($days);
    }
    return $lists;
}

/**
 * @param list<int> $customerIds
 * @return array<string, true>
 */
function bakery_stop_reliability_skips(PDO $db, array $customerIds, string $from, string $to): array
{
    $customerIds = array_values(array_filter(array_map('intval', $customerIds), static function (int $id): bool {
        return $id > 0;
    }));
    if ($customerIds === [] || !function_exists('table_exists') || !table_exists($db, 'customer_delivery_skips')) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($customerIds), '?'));
    $stmt = $db->prepare(
        "SELECT customer_id, skip_date
         FROM customer_delivery_skips
         WHERE skip_date BETWEEN ? AND ? AND customer_id IN ($marks)"
    );
    $stmt->execute(array_merge([$from, $to], $customerIds));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['customer_id'] . '|' . substr((string)$row['skip_date'], 0, 10)] = true;
    }
    return $out;
}

/**
 * @return array<int, array<string, mixed>>
 */
function bakery_stop_reliability_latest_actions(PDO $db): array
{
    if (!bakery_stop_reliability_schema_ready($db)) {
        return [];
    }
    $stmt = $db->query(
        'SELECT a.customer_id, a.action, a.note, a.snooze_until, a.flag_fingerprint, a.user_id, a.created_at
         FROM stop_reliability_actions a
         INNER JOIN (
            SELECT customer_id, MAX(id) AS id
            FROM stop_reliability_actions
            GROUP BY customer_id
         ) latest ON latest.id = a.id'
    );
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['customer_id']] = $row;
    }
    return $out;
}

/**
 * @return array{visible:list<array<string, mixed>>,hidden:list<array<string, mixed>>,snoozed:int,schema_ready:bool,allowed:bool,today:string}
 */
function bakery_stop_reliability_board(PDO $db, string $today, ?array $user = null): array
{
    $today = bakery_stop_reliability_valid_date($today);
    $empty = [
        'visible' => [],
        'hidden' => [],
        'snoozed' => 0,
        'schema_ready' => bakery_stop_reliability_schema_ready($db),
        'allowed' => false,
        'today' => $today,
    ];
    if (!bakery_stop_reliability_can_view($user)) {
        return $empty;
    }
    $rows = bakery_stop_reliability_sort(bakery_stop_reliability_load($db, $today));
    $actions = bakery_stop_reliability_latest_actions($db);
    $open = [];
    $snoozed = 0;
    foreach ($rows as $row) {
        $action = $actions[(int)$row['customer_id']] ?? null;
        if (is_array($action) && bakery_stop_reliability_action_hides($row, $action, $today)) {
            $snoozed++;
            continue;
        }
        $open[] = $row;
    }
    $collapsed = bakery_stop_reliability_collapse($open);
    return [
        'visible' => $collapsed['visible'],
        'hidden' => $collapsed['hidden'],
        'snoozed' => $snoozed,
        'schema_ready' => bakery_stop_reliability_schema_ready($db),
        'allowed' => true,
        'today' => $today,
    ];
}

function bakery_stop_reliability_valid_date(string $value): string
{
    $value = substr(trim($value), 0, 10);
    $parsed = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$parsed || $parsed->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Invalid date');
    }
    return $value;
}

function bakery_stop_reliability_clean_note(string $note): string
{
    $note = trim($note);
    $length = function_exists('mb_strlen') ? mb_strlen($note) : strlen($note);
    if ($length < BAKERY_STOP_RELIABILITY_NOTE_MIN || $length > BAKERY_STOP_RELIABILITY_NOTE_MAX) {
        throw new InvalidArgumentException('note');
    }
    return $note;
}

/**
 * @return array<string, mixed>
 */
function bakery_stop_reliability_find_row(PDO $db, int $customerId, string $today): array
{
    foreach (bakery_stop_reliability_load($db, $today) as $row) {
        if ((int)$row['customer_id'] === $customerId) {
            return $row;
        }
    }
    throw new InvalidArgumentException('flag');
}

/**
 * @return array<string, mixed>
 */
function bakery_stop_reliability_acknowledge(PDO $db, int $customerId, int $userId, string $note, ?string $today = null): array
{
    $today = bakery_stop_reliability_valid_date($today ?? bakery_stop_reliability_today());
    $row = bakery_stop_reliability_find_row($db, $customerId, $today);
    return bakery_stop_reliability_insert_action($db, $row, $userId, 'acknowledge', $note, null);
}

/**
 * @return array<string, mixed>
 */
function bakery_stop_reliability_snooze(
    PDO $db,
    int $customerId,
    int $userId,
    string $note,
    string $until,
    ?string $today = null
): array {
    $today = bakery_stop_reliability_valid_date($today ?? bakery_stop_reliability_today());
    $until = bakery_stop_reliability_valid_date($until);
    if ($until <= $today) {
        throw new InvalidArgumentException('date');
    }
    $row = bakery_stop_reliability_find_row($db, $customerId, $today);
    return bakery_stop_reliability_insert_action($db, $row, $userId, 'snooze', $note, $until);
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function bakery_stop_reliability_insert_action(
    PDO $db,
    array $row,
    int $userId,
    string $action,
    string $note,
    ?string $until
): array {
    if (!bakery_stop_reliability_schema_ready($db)) {
        throw new RuntimeException('schema');
    }
    if ($userId <= 0 || (int)($row['customer_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('flag');
    }
    if ($action !== 'acknowledge' && $action !== 'snooze') {
        throw new InvalidArgumentException('flag');
    }
    $note = bakery_stop_reliability_clean_note($note);
    $stmt = $db->prepare(
        'INSERT INTO stop_reliability_actions
            (customer_id, action, note, snooze_until, flag_fingerprint, user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$row['customer_id'],
        $action,
        $note,
        $until,
        (string)$row['fingerprint'],
        $userId,
    ]);
    $id = (int)$db->lastInsertId();
    $saved = $db->prepare(
        'SELECT id, customer_id, action, note, snooze_until, flag_fingerprint, user_id, created_at
         FROM stop_reliability_actions WHERE id = ?'
    );
    $saved->execute([$id]);
    $record = $saved->fetch(PDO::FETCH_ASSOC);
    if (!is_array($record)) {
        throw new RuntimeException('schema');
    }
    return $record;
}

function bakery_stop_reliability_safe_return(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strpos($raw, '://') !== false || strpos($raw, '//') === 0 || strpos($raw, '..') !== false) {
        return 'route_manager.php';
    }
    $path = (string)(parse_url($raw, PHP_URL_PATH) ?? '');
    $query = parse_url($raw, PHP_URL_QUERY);
    $base = basename($path);
    $allowed = ['route_manager.php', 'route_health.php', 'driver_assignment.php', 'manager.php'];
    if (!in_array($base, $allowed, true)) {
        return 'route_manager.php';
    }
    return is_string($query) && $query !== '' ? $base . '?' . $query : $base;
}

function bakery_stop_reliability_handle_post(PDO $db): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action !== 'stop_reliability_acknowledge' && $action !== 'stop_reliability_snooze') {
        return;
    }
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    if (function_exists('bakery_require_role')) {
        bakery_require_role(['administrator', 'manager']);
    }
    $return = bakery_stop_reliability_safe_return((string)($_POST['return_to'] ?? 'route_manager.php'));
    $userId = function_exists('bakery_current_user') ? (int)(bakery_current_user()['id'] ?? 0) : 0;
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $note = (string)($_POST['note'] ?? '');
    try {
        if ($action === 'stop_reliability_acknowledge') {
            bakery_stop_reliability_acknowledge($db, $customerId, $userId, $note);
        } else {
            bakery_stop_reliability_snooze($db, $customerId, $userId, $note, (string)($_POST['snooze_until'] ?? ''));
        }
        $_SESSION['stop_reliability_notice'] = 'saved';
    } catch (InvalidArgumentException $e) {
        $key = $e->getMessage();
        $_SESSION['stop_reliability_error'] = in_array($key, ['note', 'date', 'flag'], true) ? $key : 'flag';
    } catch (RuntimeException $e) {
        $_SESSION['stop_reliability_error'] = 'schema';
    }
    header('Location: ' . $return);
    exit;
}

/**
 * @return array{notice:string,error:string}
 */
function bakery_stop_reliability_take_flash(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return ['notice' => '', 'error' => ''];
    }
    $notice = (string)($_SESSION['stop_reliability_notice'] ?? '');
    $error = (string)($_SESSION['stop_reliability_error'] ?? '');
    unset($_SESSION['stop_reliability_notice'], $_SESSION['stop_reliability_error']);
    return ['notice' => $notice, 'error' => $error];
}

function bakery_stop_reliability_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bakery_stop_reliability_format_date(string $date): string
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$parsed || !function_exists('bakery_localized_date_label')) {
        return $date;
    }
    return bakery_localized_date_label($parsed);
}

/**
 * @param list<string> $dates
 */
function bakery_stop_reliability_format_dates(array $dates): string
{
    $labels = [];
    foreach ($dates as $date) {
        $labels[] = bakery_stop_reliability_format_date((string)$date);
    }
    return implode(', ', $labels);
}

/**
 * @param list<array{date:string,pieces:int}> $rows
 */
function bakery_stop_reliability_format_off(array $rows): string
{
    $labels = [];
    foreach ($rows as $row) {
        $labels[] = bakery_stop_reliability_format_date((string)$row['date']) . ' (' . (int)$row['pieces'] . ')';
    }
    return implode(', ', $labels);
}

/**
 * @param array{visible:list<array<string, mixed>>,hidden:list<array<string, mixed>>,snoozed:int,schema_ready:bool,allowed:bool,today:string} $board
 */
function bakery_stop_reliability_render_board(array $board, string $returnTo): string
{
    if (empty($board['allowed'])) {
        return '';
    }
    $flash = bakery_stop_reliability_take_flash();
    $visible = $board['visible'] ?? [];
    $hidden = $board['hidden'] ?? [];
    $returnTo = bakery_stop_reliability_safe_return($returnTo);
    $h = 'bakery_stop_reliability_h';
    $html = '<section class="stop-reliability" data-collapse="' . (int)BAKERY_STOP_RELIABILITY_COLLAPSE_AFTER . '" aria-labelledby="stop-reliability-title">';
    $html .= '<h2 id="stop-reliability-title" class="stop-reliability__title">' . $h(bakery_t('stop_reliability.title')) . '</h2>';
    $html .= '<p class="stop-reliability__intro">' . $h(bakery_t('stop_reliability.intro')) . '</p>';
    if ($flash['notice'] === 'saved') {
        $html .= '<p class="stop-reliability__flash" role="status">' . $h(bakery_t('stop_reliability.saved')) . '</p>';
    }
    $errorKey = (string)$flash['error'];
    $errorMap = [
        'note' => 'stop_reliability.error_note',
        'date' => 'stop_reliability.error_date',
        'flag' => 'stop_reliability.error_flag',
        'schema' => 'stop_reliability.error_schema',
    ];
    if (isset($errorMap[$errorKey])) {
        $html .= '<p class="stop-reliability__flash is-error" role="alert">' . $h(bakery_t($errorMap[$errorKey])) . '</p>';
    }
    if ($visible === [] && $hidden === [] && (int)($board['snoozed'] ?? 0) === 0) {
        $html .= '<p class="stop-reliability__empty">' . $h(bakery_t('stop_reliability.empty')) . '</p>';
    }
    if ((int)($board['snoozed'] ?? 0) > 0) {
        $html .= '<p class="stop-reliability__hidden">' . $h(bakery_t('stop_reliability.hidden_count', [
            'count' => (int)$board['snoozed'],
        ])) . '</p>';
    }
    if (!empty($board['schema_ready']) === false && ($visible !== [] || $hidden !== [])) {
        $html .= '<p class="stop-reliability__flash is-error">' . $h(bakery_t('stop_reliability.error_schema')) . '</p>';
    }
    $html .= bakery_stop_reliability_render_rows($visible, $returnTo, !empty($board['schema_ready']));
    if ($hidden !== []) {
        $html .= '<details class="stop-reliability__extra">';
        $html .= '<summary class="stop-reliability__more">' . $h(bakery_t('stop_reliability.show_more', [
            'count' => count($hidden),
        ])) . '</summary>';
        $html .= bakery_stop_reliability_render_rows($hidden, $returnTo, !empty($board['schema_ready']));
        $html .= '</details>';
    }
    $html .= '</section>';
    return $html;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_stop_reliability_render_rows(array $rows, string $returnTo, bool $schemaReady): string
{
    if ($rows === []) {
        return '';
    }
    $h = 'bakery_stop_reliability_h';
    $html = '<ul class="stop-reliability__list">';
    foreach ($rows as $row) {
        $missDates = is_array($row['miss_dates'] ?? null) ? $row['miss_dates'] : [];
        $offDates = is_array($row['off_dates'] ?? null) ? $row['off_dates'] : [];
        $html .= '<li class="stop-reliability__row" data-customer="' . (int)$row['customer_id'] . '"';
        $html .= ' data-miss-dates="' . $h(implode(',', $missDates)) . '"';
        $html .= ' data-off-dates="' . $h(implode(',', array_map(static function ($off): string {
            return is_array($off) ? (string)($off['date'] ?? '') : '';
        }, $offDates))) . '"';
        $html .= ' data-last-real="' . $h((string)($row['last_real'] ?? '')) . '"';
        $html .= ' data-consecutive="' . (int)($row['consecutive'] ?? 0) . '">';
        $html .= '<p class="stop-reliability__store"><strong>' . $h((string)$row['store']) . '</strong></p>';
        if (trim((string)($row['address'] ?? '')) !== '') {
            $html .= '<p class="stop-reliability__address">' . $h((string)$row['address']) . '</p>';
        }
        if (trim((string)($row['driver'] ?? '')) !== '') {
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.driver', [
                'name' => (string)$row['driver'],
            ])) . '</p>';
        }
        if ((int)($row['consecutive'] ?? 0) >= BAKERY_STOP_RELIABILITY_MISS_MIN) {
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.misses', [
                'count' => (int)$row['consecutive'],
            ])) . '</p>';
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.miss_dates', [
                'dates' => bakery_stop_reliability_format_dates($missDates),
            ])) . '</p>';
        }
        $lastReal = (string)($row['last_real'] ?? '');
        if ($lastReal !== '') {
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.last_delivery', [
                'date' => bakery_stop_reliability_format_date($lastReal),
            ])) . '</p>';
        } elseif ((int)($row['consecutive'] ?? 0) >= BAKERY_STOP_RELIABILITY_MISS_MIN) {
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.last_delivery_none')) . '</p>';
        }
        if ($offDates !== []) {
            $html .= '<p class="stop-reliability__meta">' . $h(bakery_t('stop_reliability.off_standing')) . ' ';
            $html .= $h(bakery_t('stop_reliability.off_dates', [
                'dates' => bakery_stop_reliability_format_off($offDates),
            ])) . '</p>';
        }
        if ($schemaReady) {
            $html .= '<form class="stop-reliability__form" method="post">';
            $html .= function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
            $html .= '<input type="hidden" name="customer_id" value="' . (int)$row['customer_id'] . '">';
            $html .= '<input type="hidden" name="return_to" value="' . $h($returnTo) . '">';
            $html .= '<label class="stop-reliability__note">' . $h(bakery_t('stop_reliability.note'));
            $html .= '<input name="note" required minlength="' . (int)BAKERY_STOP_RELIABILITY_NOTE_MIN . '" maxlength="' . (int)BAKERY_STOP_RELIABILITY_NOTE_MAX . '" placeholder="' . $h(bakery_t('stop_reliability.note_placeholder')) . '">';
            $html .= '</label>';
            $html .= '<button class="sf-btn sf-btn--sm stop-reliability__btn" type="submit" name="action" value="stop_reliability_acknowledge">' . $h(bakery_t('stop_reliability.acknowledge')) . '</button>';
            $html .= '<label class="stop-reliability__until">' . $h(bakery_t('stop_reliability.snooze_until'));
            $html .= '<input type="date" name="snooze_until">';
            $html .= '</label>';
            $html .= '<button class="sf-btn sf-btn--outline sf-btn--sm stop-reliability__btn" type="submit" name="action" value="stop_reliability_snooze">' . $h(bakery_t('stop_reliability.snooze')) . '</button>';
            $html .= '</form>';
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

function bakery_stop_reliability_render(PDO $db, string $returnTo, ?array $user = null, ?string $today = null): string
{
    if ($user === null && function_exists('bakery_current_user')) {
        $user = bakery_current_user();
    }
    if (!bakery_stop_reliability_can_view($user)) {
        return '';
    }
    $today = $today ?? bakery_stop_reliability_today();
    $board = bakery_stop_reliability_board($db, $today, $user);
    return bakery_stop_reliability_render_board($board, $returnTo);
}
