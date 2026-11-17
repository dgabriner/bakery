<?php
/**
 * Bake-day crew and durable work leads for Production Manager.
 *
 * The usual crew is whoever punched in on at least three of the last eight
 * same weekdays. Stored rows are only a manager's add or remove for one date.
 * Hours always come from time_clock_punches.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_manager_dashboard.php';
require_once __DIR__ . '/time_clock.php';

function bakery_production_day_crew_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done || !function_exists('table_exists')) {
        return;
    }
    if (table_exists($db, 'production_day_crew') && table_exists($db, 'production_work_leads')) {
        $done = true;
        return;
    }
    $path = dirname(__DIR__) . '/database/schema/116_production_day_crew.sql';
    if (!is_file($path)) {
        return;
    }
    if (!function_exists('bakery_run_sql_file_safe')) {
        require_once __DIR__ . '/schema_sql.php';
    }
    bakery_run_sql_file_safe($db, $path);
    $done = table_exists($db, 'production_day_crew') && table_exists($db, 'production_work_leads');
}

/**
 * @return list<string>
 */
function bakery_production_crew_sample_dates(string $bakeDate, int $weeks = 8): array
{
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    $weeks = max(1, min(8, $weeks));
    $dates = [];
    for ($i = 1; $i <= $weeks; $i++) {
        $dates[] = date('Y-m-d', strtotime($bakeDate . ' -' . (7 * $i) . ' days'));
    }
    return $dates;
}

/**
 * @return array<int, true>
 */
function bakery_production_crew_pinned_users(PDO $db): array
{
    bakery_production_day_crew_ensure_schema($db);
    if (!table_exists($db, 'production_day_crew')) {
        return [];
    }
    $ids = [];
    $stmt = $db->query('SELECT DISTINCT user_id FROM production_day_crew');
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [] as $id) {
        $ids[(int)$id] = true;
    }
    if (table_exists($db, 'production_work_leads')) {
        $leadStmt = $db->query('SELECT DISTINCT user_id FROM production_work_leads');
        foreach ($leadStmt ? $leadStmt->fetchAll(PDO::FETCH_COLUMN) : [] as $id) {
            $ids[(int)$id] = true;
        }
    }
    return $ids;
}

function bakery_production_crew_is_kitchen_role(string $role): bool
{
    return $role === 'baker';
}

/**
 * Bakers, leads, and anyone ever pinned, with at least three punches
 * on the last eight same weekdays. Drivers stay out unless pinned or a lead.
 *
 * @return array<int, array{user_id:int,display_name:string,role_slug:string,days:int}>
 */
function bakery_production_crew_suggest(PDO $db, string $bakeDate): array
{
    if (!bakery_time_clock_ready($db)) {
        return [];
    }
    $dates = bakery_production_crew_sample_dates($bakeDate, 8);
    $start = $dates[count($dates) - 1] . ' 00:00:00';
    $end = date('Y-m-d', strtotime($dates[0] . ' +1 day')) . ' 00:00:00';
    $wanted = array_fill_keys($dates, true);
    $stmt = $db->prepare(
        'SELECT p.user_id, DATE(p.clock_in_at) AS punch_date, u.display_name, r.slug AS role_slug
         FROM time_clock_punches p
         JOIN users u ON u.id = p.user_id AND u.is_active = 1
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE p.clock_in_at >= ? AND p.clock_in_at < ?'
    );
    $stmt->execute([$start, $end]);
    $pinned = bakery_production_crew_pinned_users($db);
    $days = [];
    $meta = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $punchDate = substr((string)$row['punch_date'], 0, 10);
        if (!isset($wanted[$punchDate])) {
            continue;
        }
        $userId = (int)$row['user_id'];
        $role = (string)($row['role_slug'] ?? '');
        if (!bakery_production_crew_is_kitchen_role($role) && !isset($pinned[$userId])) {
            continue;
        }
        $days[$userId][$punchDate] = true;
        $meta[$userId] = [
            'user_id' => $userId,
            'display_name' => (string)$row['display_name'],
            'role_slug' => $role,
        ];
    }
    $suggested = [];
    foreach ($days as $userId => $punched) {
        $count = count($punched);
        if ($count < 3 || !isset($meta[$userId])) {
            continue;
        }
        $meta[$userId]['days'] = $count;
        $suggested[$userId] = $meta[$userId];
    }
    return $suggested;
}

/**
 * @return array{date:string,people:list<array<string,mixed>>}
 */
function bakery_production_crew_for_day(PDO $db, string $bakeDate): array
{
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    bakery_production_day_crew_ensure_schema($db);
    $suggested = bakery_production_crew_suggest($db, $bakeDate);
    $pins = [];
    if (table_exists($db, 'production_day_crew')) {
        $pinStmt = $db->prepare(
            'SELECT c.user_id, c.pin, u.display_name, r.slug AS role_slug
             FROM production_day_crew c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN roles r ON r.id = u.role_id
             WHERE c.bake_date = ?'
        );
        $pinStmt->execute([$bakeDate]);
        foreach ($pinStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pins[(int)$row['user_id']] = $row;
        }
    }
    $pinnedEver = bakery_production_crew_pinned_users($db);
    $expected = $suggested;
    foreach ($pins as $userId => $pin) {
        if ((string)$pin['pin'] === 'remove') {
            unset($expected[$userId]);
            continue;
        }
        $expected[$userId] = [
            'user_id' => $userId,
            'display_name' => (string)$pin['display_name'],
            'role_slug' => (string)($pin['role_slug'] ?? ''),
            'days' => (int)($suggested[$userId]['days'] ?? 0),
        ];
    }

    $punches = [];
    foreach (bakery_time_clock_punches_for_day($db, $bakeDate) as $punch) {
        $punches[(int)$punch['user_id']] = $punch;
    }

    $people = [];
    $seen = [];
    $addPerson = static function (array $meta, bool $expectedRow) use (&$people, &$seen, $punches, $pins): void {
        $userId = (int)$meta['user_id'];
        if (isset($seen[$userId])) {
            return;
        }
        $seen[$userId] = true;
        $punch = $punches[$userId] ?? null;
        $minutes = 0;
        $in = null;
        $out = null;
        $flags = [];
        if (is_array($punch)) {
            $in = (string)$punch['clock_in_at'];
            $out = isset($punch['clock_out_at']) && $punch['clock_out_at'] !== ''
                ? (string)$punch['clock_out_at']
                : null;
            $minutes = bakery_time_clock_minutes($in, $out);
            $flags = bakery_time_clock_flags($punch);
        }
        $people[] = [
            'user_id' => $userId,
            'display_name' => (string)$meta['display_name'],
            'role_slug' => (string)($meta['role_slug'] ?? ''),
            'expected' => $expectedRow,
            'extra' => !$expectedRow,
            'punched' => $punch !== null,
            'clock_in_at' => $in,
            'clock_out_at' => $out,
            'minutes' => $minutes,
            'hours_label' => $punch !== null ? bakery_time_clock_format_duration($minutes) : '',
            'flags' => $flags,
            'pin' => isset($pins[$userId]) ? (string)$pins[$userId]['pin'] : null,
        ];
    };

    foreach ($expected as $meta) {
        $addPerson($meta, true);
    }
    foreach ($punches as $userId => $punch) {
        if (isset($expected[$userId])) {
            continue;
        }
        $role = (string)($punch['role_slug'] ?? '');
        if (!bakery_production_crew_is_kitchen_role($role) && !isset($pinnedEver[$userId])) {
            continue;
        }
        $addPerson([
            'user_id' => $userId,
            'display_name' => (string)($punch['display_name'] ?? ''),
            'role_slug' => $role,
        ], false);
    }

    usort($people, static function (array $a, array $b): int {
        if ($a['expected'] !== $b['expected']) {
            return $a['expected'] ? -1 : 1;
        }
        return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
    });

    return [
        'date' => $bakeDate,
        'people' => $people,
    ];
}

function bakery_production_crew_pin(PDO $db, string $bakeDate, int $userId, string $pin, int $pinnedBy): bool
{
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    if ($userId <= 0 || !in_array($pin, ['add', 'remove'], true)) {
        return false;
    }
    bakery_production_day_crew_ensure_schema($db);
    $exists = $db->prepare('SELECT id FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
    $exists->execute([$userId]);
    if (!(int)$exists->fetchColumn()) {
        return false;
    }
    $stmt = $db->prepare(
        'INSERT INTO production_day_crew (bake_date, user_id, pin, pinned_by_user_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE pin = VALUES(pin), pinned_by_user_id = VALUES(pinned_by_user_id), pinned_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$bakeDate, $userId, $pin, $pinnedBy > 0 ? $pinnedBy : null]);
    return true;
}

function bakery_production_crew_clear(PDO $db, string $bakeDate, int $userId): void
{
    $bakeDate = bakery_pmd_resolve_bake_date($bakeDate);
    if ($userId <= 0) {
        return;
    }
    bakery_production_day_crew_ensure_schema($db);
    $stmt = $db->prepare('DELETE FROM production_day_crew WHERE bake_date = ? AND user_id = ?');
    $stmt->execute([$bakeDate, $userId]);
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_production_leads(PDO $db): array
{
    bakery_production_day_crew_ensure_schema($db);
    if (!table_exists($db, 'production_work_leads')) {
        return [];
    }
    $stmt = $db->query(
        'SELECT l.id, l.user_id, l.product_line_id, l.dough_type_id, l.customer_id,
                u.display_name, pl.name AS product_line_name, dt.name AS dough_type_name,
                c.name AS customer_name
         FROM production_work_leads l
         JOIN users u ON u.id = l.user_id
         LEFT JOIN product_lines pl ON pl.id = l.product_line_id
         LEFT JOIN dough_types dt ON dt.id = l.dough_type_id
         LEFT JOIN customers c ON c.id = l.customer_id
         ORDER BY u.display_name, l.id'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
}

/**
 * @param list<array<string,mixed>> $leads
 * @return list<string>
 */
function bakery_production_lead_names_for_dough(array $leads, $doughTypeId, $productLineId): array
{
    $names = [];
    $doughTypeId = (int)$doughTypeId;
    $productLineId = (int)$productLineId;
    foreach ($leads as $lead) {
        if ((int)($lead['customer_id'] ?? 0) > 0) {
            continue;
        }
        $leadDough = (int)($lead['dough_type_id'] ?? 0);
        $leadLine = (int)($lead['product_line_id'] ?? 0);
        if ($leadDough > 0 && $leadDough === $doughTypeId) {
            $names[] = (string)$lead['display_name'];
            continue;
        }
        if ($leadDough === 0 && $leadLine > 0 && $leadLine === $productLineId) {
            $names[] = (string)$lead['display_name'];
        }
    }
    return array_values(array_unique($names));
}

/**
 * @param list<array<string,mixed>> $leads
 * @return list<string>
 */
function bakery_production_lead_names_for_destination(array $leads, int $customerId, $productLineId, $doughTypeId): array
{
    $names = [];
    $productLineId = (int)$productLineId;
    $doughTypeId = (int)$doughTypeId;
    foreach ($leads as $lead) {
        if ((int)($lead['customer_id'] ?? 0) !== $customerId) {
            continue;
        }
        $leadLine = (int)($lead['product_line_id'] ?? 0);
        $leadDough = (int)($lead['dough_type_id'] ?? 0);
        if ($leadLine > 0 && $leadLine !== $productLineId) {
            continue;
        }
        if ($leadDough > 0 && $leadDough !== $doughTypeId) {
            continue;
        }
        $names[] = (string)$lead['display_name'];
    }
    return array_values(array_unique($names));
}

/**
 * @param array<string,mixed> $board
 * @param list<array<string,mixed>> $leads
 */
function bakery_production_apply_leads(array &$board, array $leads): void
{
    if (!isset($board['doughs']) || !is_array($board['doughs'])) {
        return;
    }
    foreach ($board['doughs'] as &$dough) {
        $dough['leads'] = bakery_production_lead_names_for_dough(
            $leads,
            $dough['dough_type_id'] ?? 0,
            $dough['product_line_id'] ?? 0
        );
        foreach ($dough['products'] as &$product) {
            if (!isset($product['destinations']) || !is_array($product['destinations'])) {
                continue;
            }
            foreach ($product['destinations'] as &$dest) {
                $dest['leads'] = bakery_production_lead_names_for_destination(
                    $leads,
                    (int)($dest['customer_id'] ?? 0),
                    $dough['product_line_id'] ?? 0,
                    $dough['dough_type_id'] ?? 0
                );
            }
            unset($dest);
        }
        unset($product);
    }
    unset($dough);
}

/**
 * @return list<array{id:int,display_name:string,role_slug:string}>
 */
function bakery_production_staff_options(PDO $db): array
{
    $stmt = $db->query(
        'SELECT u.id, u.display_name, r.slug AS role_slug
         FROM users u
         LEFT JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1
         ORDER BY u.display_name'
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'display_name' => (string)$row['display_name'],
            'role_slug' => (string)($row['role_slug'] ?? ''),
        ];
    }
    return $out;
}

/**
 * @return array{staff:list<array<string,mixed>>,lines:list<array<string,mixed>>,doughs:list<array<string,mixed>>,customers:list<array<string,mixed>>}
 */
function bakery_production_lead_options(PDO $db): array
{
    $lines = [];
    if (function_exists('table_exists') && table_exists($db, 'product_lines')) {
        $stmt = $db->query('SELECT id, name FROM product_lines ORDER BY name');
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $lines[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
    }
    $doughs = [];
    if (function_exists('table_exists') && table_exists($db, 'dough_types')) {
        $stmt = $db->query(
            'SELECT dt.id, dt.name, pl.name AS line_name
             FROM dough_types dt
             LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
             ORDER BY pl.name, dt.name'
        );
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $doughs[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'line_name' => (string)($row['line_name'] ?? ''),
            ];
        }
    }
    $customers = [];
    if (function_exists('table_exists') && table_exists($db, 'customers')) {
        $stmt = $db->query(
            'SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name LIMIT 500'
        );
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $customers[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
        }
    }
    return [
        'staff' => bakery_production_staff_options($db),
        'lines' => $lines,
        'doughs' => $doughs,
        'customers' => $customers,
    ];
}

function bakery_production_lead_save(
    PDO $db,
    int $userId,
    ?int $productLineId,
    ?int $doughTypeId,
    ?int $customerId,
    int $createdBy
): bool {
    if ($userId <= 0) {
        return false;
    }
    $productLineId = $productLineId > 0 ? $productLineId : null;
    $doughTypeId = $doughTypeId > 0 ? $doughTypeId : null;
    $customerId = $customerId > 0 ? $customerId : null;
    if ($productLineId === null && $doughTypeId === null && $customerId === null) {
        return false;
    }
    bakery_production_day_crew_ensure_schema($db);
    foreach (bakery_production_leads($db) as $lead) {
        $sameUser = (int)$lead['user_id'] === $userId;
        $sameLine = (int)($lead['product_line_id'] ?? 0) === (int)$productLineId;
        $sameDough = (int)($lead['dough_type_id'] ?? 0) === (int)$doughTypeId;
        $sameCustomer = (int)($lead['customer_id'] ?? 0) === (int)$customerId;
        if ($sameUser && $sameLine && $sameDough && $sameCustomer) {
            return true;
        }
    }
    $stmt = $db->prepare(
        'INSERT INTO production_work_leads (user_id, product_line_id, dough_type_id, customer_id, created_by_user_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $userId,
        $productLineId,
        $doughTypeId,
        $customerId,
        $createdBy > 0 ? $createdBy : null,
    ]);
    return true;
}

function bakery_production_lead_delete(PDO $db, int $leadId): void
{
    if ($leadId <= 0) {
        return;
    }
    bakery_production_day_crew_ensure_schema($db);
    $stmt = $db->prepare('DELETE FROM production_work_leads WHERE id = ?');
    $stmt->execute([$leadId]);
}

function bakery_production_day_handle_post(PDO $db): void
{
    if (!function_exists('bakery_require_role')) {
        require_once __DIR__ . '/auth.php';
    }
    bakery_require_role(['administrator', 'manager']);
    if (function_exists('bakery_require_csrf')) {
        bakery_require_csrf();
    }
    $action = (string)($_POST['action'] ?? '');
    $date = bakery_pmd_resolve_bake_date((string)($_POST['date'] ?? ''));
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $by = (int)($user['id'] ?? 0);
    $leadError = '';
    if ($action === 'crew_add' || $action === 'crew_remove') {
        bakery_production_crew_pin(
            $db,
            $date,
            (int)($_POST['user_id'] ?? 0),
            $action === 'crew_add' ? 'add' : 'remove',
            $by
        );
    } elseif ($action === 'crew_clear') {
        bakery_production_crew_clear($db, $date, (int)($_POST['user_id'] ?? 0));
    } elseif ($action === 'lead_add') {
        $saved = bakery_production_lead_save(
            $db,
            (int)($_POST['user_id'] ?? 0),
            (int)($_POST['product_line_id'] ?? 0) ?: null,
            (int)($_POST['dough_type_id'] ?? 0) ?: null,
            (int)($_POST['customer_id'] ?? 0) ?: null,
            $by
        );
        if (!$saved) {
            $leadError = 'scope';
        }
    } elseif ($action === 'lead_delete') {
        bakery_production_lead_delete($db, (int)($_POST['lead_id'] ?? 0));
    }
    $query = ['date' => $date, 'view' => 'batches'];
    if ((string)($_POST['expand'] ?? '') === '1' || $action === 'lead_add' || $action === 'lead_delete') {
        $query['expand'] = '1';
    }
    if ($leadError !== '') {
        $query['lead_error'] = $leadError;
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    header('Location: ' . $base . 'production_manager.php?' . http_build_query($query));
    exit;
}
