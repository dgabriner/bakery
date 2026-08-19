<?php
/** Shared read models and coordination mutations for Manager Mode. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/delivery_recovery.php';

function bakery_manager_exception_key(array $exception, string $date): string
{
    $context = is_array($exception['context'] ?? null) ? $exception['context'] : [];
    ksort($context);
    return hash('sha256', json_encode([
        'date' => $date,
        'type' => (string)($exception['type'] ?? ''),
        'category' => (string)($exception['category'] ?? ''),
        'context' => $context,
    ], JSON_UNESCAPED_SLASHES));
}

function bakery_manager_exception_work_ready(PDO $db): bool
{
    return table_exists($db, 'manager_exception_work');
}

/** @return list<array<string,mixed>> */
function bakery_manager_assignable_users(PDO $db): array
{
    if (!table_exists($db, 'users') || !table_exists($db, 'roles')) {
        return [];
    }
    $stmt = $db->query(
        "SELECT u.id, u.display_name, u.email
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.is_active = 1 AND r.slug IN ('administrator', 'manager')
         ORDER BY u.display_name, u.email"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/** @return list<array<string,mixed>> */
function bakery_manager_enrich_exception_work(PDO $db, array $exceptions, string $date): array
{
    if ($exceptions === []) {
        return [];
    }
    $keys = [];
    foreach ($exceptions as $exception) {
        if (is_array($exception)) {
            $keys[] = bakery_manager_exception_key($exception, $date);
        }
    }
    $workByKey = [];
    if ($keys !== [] && bakery_manager_exception_work_ready($db)) {
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $stmt = $db->prepare("SELECT ew.*, assignee.display_name AS assigned_name, ack.display_name AS acknowledged_name, done.display_name AS completed_name
                              FROM manager_exception_work ew
                              LEFT JOIN users assignee ON assignee.id = ew.assigned_to_user_id
                              LEFT JOIN users ack ON ack.id = ew.acknowledged_by_user_id
                              LEFT JOIN users done ON done.id = ew.completed_by_user_id
                              WHERE ew.exception_key IN ({$marks})");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $workByKey[(string)$row['exception_key']] = $row;
        }
    }
    foreach ($exceptions as &$exception) {
        if (!is_array($exception)) {
            continue;
        }
        $key = bakery_manager_exception_key($exception, $date);
        $exception['work_key'] = $key;
        $exception['work'] = $workByKey[$key] ?? null;
    }
    unset($exception);
    return $exceptions;
}

function bakery_manager_exception_save(PDO $db, array $exception, string $date, array $input): void
{
    $isManager = function_exists('bakery_user_has_role') && bakery_user_has_role(['administrator', 'manager']);
    $isBaker = function_exists('bakery_user_has_role') && bakery_user_has_role(['baker']);
    if ($isManager) {
        bakery_require_role(['administrator', 'manager']);
    } elseif ($isBaker) {
        bakery_require_role(['baker']);
        if ((string)($exception['type'] ?? '') !== 'production_fg_shortfall') {
            throw new RuntimeException('Bakers can only flag finished-goods shortages');
        }
        $input['acknowledge'] = 1;
        $input['assigned_to_user_id'] = '';
        $input['due_at'] = '';
        $input['complete'] = 0;
        if (trim((string)($input['resolution_note'] ?? '')) === '') {
            throw new InvalidArgumentException('A note is required to flag a shortage');
        }
    } else {
        bakery_require_role(['administrator', 'manager']);
    }
    if (!bakery_manager_exception_work_ready($db)) {
        throw new RuntimeException('Exception work queue is not installed. Run local migrations first.');
    }
    $key = bakery_manager_exception_key($exception, $date);
    $assigned = (int)($input['assigned_to_user_id'] ?? 0) ?: null;
    $due = trim((string)($input['due_at'] ?? ''));
    if ($due !== '') {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $due);
        if (!$parsed || $parsed->format('Y-m-d\TH:i') !== $due) {
            throw new InvalidArgumentException('Due time must include a valid date and time');
        }
        $due = $parsed->format('Y-m-d H:i:s');
    } else {
        $due = null;
    }
    $note = bakery_delivery_recovery_note($input['resolution_note'] ?? '');
    $complete = !empty($input['complete']);
    if ($complete && $note === '') {
        throw new InvalidArgumentException('A resolution note is required before completion');
    }
    $actorId = (int)(bakery_current_user()['id'] ?? 0) ?: null;
    $ack = !empty($input['acknowledge']);
    $stmt = $db->prepare(
        'INSERT INTO manager_exception_work
         (exception_key, operating_date, exception_type, exception_category, acknowledged_at, acknowledged_by_user_id,
          assigned_to_user_id, due_at, resolution_note, completed_at, completed_by_user_id)
         VALUES (?, ?, ?, ?, IF(?, NOW(), NULL), IF(?, ?, NULL), ?, ?, ?, IF(?, NOW(), NULL), IF(?, ?, NULL))
         ON DUPLICATE KEY UPDATE
           acknowledged_at = IF(VALUES(acknowledged_at) IS NOT NULL, COALESCE(acknowledged_at, VALUES(acknowledged_at)), acknowledged_at),
           acknowledged_by_user_id = IF(VALUES(acknowledged_at) IS NOT NULL, COALESCE(acknowledged_by_user_id, VALUES(acknowledged_by_user_id)), acknowledged_by_user_id),
           assigned_to_user_id = VALUES(assigned_to_user_id), due_at = VALUES(due_at),
           resolution_note = VALUES(resolution_note),
           completed_at = IF(VALUES(completed_at) IS NOT NULL, VALUES(completed_at), completed_at),
           completed_by_user_id = IF(VALUES(completed_at) IS NOT NULL, VALUES(completed_by_user_id), completed_by_user_id)'
    );
    $stmt->execute([
        $key, $date, (string)($exception['type'] ?? 'general'), (string)($exception['category'] ?? 'general'),
        $ack ? 1 : 0, $ack ? 1 : 0, $actorId, $assigned, $due, $note,
        $complete ? 1 : 0, $complete ? 1 : 0, $actorId,
    ]);
    if ($complete && function_exists('bakery_record_operational_event')) {
        $ctx = is_array($exception['context'] ?? null) ? $exception['context'] : [];
        bakery_record_operational_event(
            $db,
            'manager_exception_work_completed',
            'Manager completed exception work: ' . (string)($exception['title'] ?? $exception['type'] ?? 'exception'),
            [
                'operational_date' => $date,
                'customer_id' => !empty($ctx['customer_id']) ? (int)$ctx['customer_id'] : null,
                'daily_order_id' => !empty($ctx['daily_order_id']) ? (int)$ctx['daily_order_id'] : null,
                'product_id' => !empty($ctx['product_id']) ? (int)$ctx['product_id'] : null,
                'driver_id' => !empty($ctx['driver_id']) ? (int)$ctx['driver_id'] : null,
                'metadata' => [
                    'exception_type' => (string)($exception['type'] ?? ''),
                    'exception_key' => $key,
                ],
            ]
        );
    }
}

/** @return array<string,mixed> */
function bakery_manager_route_plan(PDO $db, string $date): array
{
    $plan = ['unassigned_by_zone' => [], 'drivers' => [], 'unassigned_count' => 0, 'tight_window_count' => 0];
    if (!table_exists($db, 'daily_orders') || !table_exists($db, 'daily_order_assignments')) {
        return $plan;
    }
    $stmt = $db->prepare(
        "SELECT do.id AS daily_order_id, c.name AS customer_name, COALESCE(NULLIF(c.zone, ''), 'Unzoned') AS zone,
                c.deliver_after, c.deliver_by, COALESCE(c.delivery_time, 20) AS service_minutes
         FROM daily_orders do JOIN customers c ON c.id = do.customer_id
         " . bakery_sfb_ops_origin_clause('c', $db) . "
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id AND doa.delivery_date = ?
         WHERE do.order_date = ? AND doa.id IS NULL
         ORDER BY zone, c.deliver_by IS NULL, c.deliver_by, c.name"
    );
    $stmt->execute([$date, $date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $after = (string)($row['deliver_after'] ?? '');
        $by = (string)($row['deliver_by'] ?? '');
        $windowMinutes = null;
        if ($after !== '' && $by !== '') {
            $windowMinutes = (int)((strtotime($date . ' ' . $by) - strtotime($date . ' ' . $after)) / 60);
        }
        $tight = $windowMinutes !== null && $windowMinutes <= ((int)$row['service_minutes'] + 20);
        $row['window_pressure'] = $tight ? 'tight' : ($by !== '' ? 'deadline' : 'flexible');
        $row['window_label'] = $after !== '' || $by !== ''
            ? trim(($after !== '' ? 'After ' . substr($after, 0, 5) : '') . ($after !== '' && $by !== '' ? ' · ' : '') . ($by !== '' ? 'By ' . substr($by, 0, 5) : ''))
            : 'Flexible window';
        $zone = (string)$row['zone'];
        $plan['unassigned_by_zone'][$zone][] = $row;
        $plan['unassigned_count']++;
        $plan['tight_window_count'] += $tight ? 1 : 0;
    }

    $counts = [];
    $countStmt = $db->prepare("SELECT driver_id, COUNT(*) AS stop_count,
        SUM(delivery_status='in_transit') AS in_transit_count, SUM(delivery_status='failed') AS failed_count,
        SUM(delivery_status IN ('pending','in_transit')) AS open_count
        FROM daily_order_assignments WHERE delivery_date=? GROUP BY driver_id");
    $countStmt->execute([$date]);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int)$row['driver_id']] = $row;
    }
    $zoneStmt = $db->prepare("SELECT doa.driver_id, COALESCE(NULLIF(c.zone, ''), 'Unzoned') AS zone, COUNT(*) AS stop_count
        FROM daily_order_assignments doa JOIN daily_orders do ON do.id=doa.daily_order_id JOIN customers c ON c.id=do.customer_id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        WHERE doa.delivery_date=? GROUP BY doa.driver_id, COALESCE(NULLIF(c.zone, ''), 'Unzoned')");
    $zoneStmt->execute([$date]);
    $zoneCounts = [];
    foreach ($zoneStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $zoneCounts[(int)$row['driver_id']][(string)$row['zone']] = (int)$row['stop_count'];
    }
    foreach (bakery_get_drivers($db, false) as $driver) {
        $id = (int)$driver['id'];
        $count = $counts[$id] ?? [];
        $inTransit = (int)($count['in_transit_count'] ?? 0);
        $stops = (int)($count['stop_count'] ?? 0);
        $plan['drivers'][] = [
            'id' => $id, 'name' => (string)$driver['name'], 'stop_count' => $stops,
            'open_count' => (int)($count['open_count'] ?? 0), 'failed_count' => (int)($count['failed_count'] ?? 0),
            'availability' => $inTransit > 0 ? 'In transit — do not interrupt' : ($stops === 0 ? 'Available for route planning' : 'Planned route'),
            'capacity' => 'No configured stop capacity; review route count',
            'zone_counts' => $zoneCounts[$id] ?? [],
        ];
    }
    return $plan;
}

function bakery_manager_stage_metric(array $stage, string $key, $fallback = null)
{
    $metric = $stage['metrics'][$key] ?? null;
    return is_array($metric) && array_key_exists('value', $metric) ? $metric['value'] : $fallback;
}

/** Dated handoff board; values are derived from existing Daily Run/command-center inputs. */
function bakery_manager_handoff_board(PDO $db, string $date, array $commandCenter, array $dailyRun): array
{
    $stages = [];
    foreach ($commandCenter['stages'] ?? [] as $stage) {
        $stages[(string)($stage['key'] ?? '')] = $stage;
    }
    $runStages = [];
    foreach ($dailyRun['stages'] ?? [] as $stage) {
        $runStages[(string)($stage['key'] ?? '')] = $stage;
    }
    $required = (int)bakery_manager_stage_metric($stages['production'] ?? [], 'required_units', 0);
    $demand = $runStages['confirm_demand'] ?? [];
    $confirmation = $demand['confirmation']['confirmation'] ?? null;
    $planned = 0;
    if (table_exists($db, 'production_plan_items')) {
        $stmt = $db->prepare('SELECT COALESCE(SUM(planned_quantity), 0) FROM production_plan_items WHERE delivery_date=?');
        $stmt->execute([$date]);
        $planned = (int)$stmt->fetchColumn();
    }
    $packed = 0;
    if (table_exists($db, 'pack_progress')) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM pack_progress WHERE pack_date=?');
        $stmt->execute([$date]);
        $packed = (int)$stmt->fetchColumn();
    }
    $requiredLines = (int)bakery_manager_stage_metric($stages['pack'] ?? [], 'item_lines', 0);
    $loaded = 0;
    if (table_exists($db, 'driver_loads') && table_exists($db, 'driver_load_items')) {
        $stmt = $db->prepare('SELECT COALESCE(SUM(dli.loaded_quantity), 0) FROM driver_loads dl JOIN driver_load_items dli ON dli.driver_load_id=dl.id WHERE dl.delivery_date=?');
        $stmt->execute([$date]);
        $loaded = (int)$stmt->fetchColumn();
    }
    return [
        'demand_confirmed' => ['confirmed' => $confirmation !== null, 'units' => (int)($confirmation['units_count'] ?? $required), 'href' => $demand['href'] ?? bakery_ops_link_daily_orders($date, [], 'manager')],
        'production' => ['actual' => $planned, 'required' => $required, 'href' => ($runStages['production_plan']['href'] ?? bakery_ops_link_production($date, [], 'manager'))],
        'pack' => ['actual' => $packed, 'required' => $requiredLines, 'href' => ($runStages['pack']['href'] ?? bakery_ops_link_pack_list($date, [], 'manager'))],
        'load' => ['actual' => $loaded, 'required' => $required, 'href' => ($stages['load']['href'] ?? bakery_ops_link_driver_load($date, [], 'manager'))],
    ];
}
