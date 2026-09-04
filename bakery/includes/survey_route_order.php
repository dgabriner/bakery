<?php
/**
 * Route-order survey helpers (kind = route_order).
 * Tap-in-sequence ordering of dated movable stops; Save applies route_order.
 * Pure helpers are unit-tested without bakerysf_test.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Statuses that stay fixed at the front of the route and are not tappable. */
function bakery_survey_route_order_locked_statuses(): array
{
    return ['delivered', 'cancelled', 'in_transit'];
}

/**
 * Split assignment rows into locked (fixed front) vs movable (tap list).
 *
 * @param list<array{id?:int,daily_order_id?:int,route_order?:int,delivery_status?:string,customer_id?:int,name?:string}> $rows
 * @return array{locked:list<array>,movable:list<array>}
 */
function bakery_survey_route_order_partition(array $rows): array
{
    $lockedStatuses = bakery_survey_route_order_locked_statuses();
    $locked = [];
    $movable = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $status = (string)($row['delivery_status'] ?? 'pending');
        if (in_array($status, $lockedStatuses, true)) {
            $locked[] = $row;
        } else {
            $movable[] = $row;
        }
    }
    return ['locked' => $locked, 'movable' => $movable];
}

/**
 * Validate a full tap sequence: every movable daily_order_id exactly once.
 *
 * @param list<int> $orderedDailyOrderIds
 * @param list<array{daily_order_id?:int}> $movable
 * @return array{ok:bool,ordered:list<int>,error?:string}
 */
function bakery_survey_route_order_collect(array $orderedDailyOrderIds, array $movable): array
{
    $allowed = [];
    foreach ($movable as $row) {
        $id = (int)($row['daily_order_id'] ?? 0);
        if ($id > 0) {
            $allowed[$id] = true;
        }
    }
    $ordered = [];
    $seen = [];
    foreach ($orderedDailyOrderIds as $raw) {
        $id = (int)$raw;
        if ($id <= 0) {
            continue;
        }
        if (!isset($allowed[$id])) {
            return ['ok' => false, 'ordered' => [], 'error' => 'unknown_or_locked'];
        }
        if (isset($seen[$id])) {
            return ['ok' => false, 'ordered' => [], 'error' => 'duplicate'];
        }
        $seen[$id] = true;
        $ordered[] = $id;
    }
    if (count($ordered) !== count($allowed)) {
        return ['ok' => false, 'ordered' => $ordered, 'error' => 'incomplete'];
    }
    return ['ok' => true, 'ordered' => $ordered];
}

/**
 * Plan final route_order rows: locked first (stable), then ordered movable.
 *
 * @param list<array{id:int,daily_order_id:int,delivery_status?:string}> $locked
 * @param list<array{id:int,daily_order_id:int,delivery_status?:string}> $movable
 * @param list<int> $orderedDailyOrderIds full permutation of movable daily_order_ids
 * @return list<array{assignment_id:int,daily_order_id:int,route_order:int,delivery_status:string}>
 */
function bakery_survey_route_order_plan(array $locked, array $movable, array $orderedDailyOrderIds): array
{
    $byOrderId = [];
    foreach ($movable as $row) {
        $byOrderId[(int)$row['daily_order_id']] = $row;
    }
    $newMovable = [];
    foreach ($orderedDailyOrderIds as $orderId) {
        $orderId = (int)$orderId;
        if (!isset($byOrderId[$orderId])) {
            throw new InvalidArgumentException('ordered id not in movable set');
        }
        $newMovable[] = $byOrderId[$orderId];
    }
    if (count($newMovable) !== count($movable)) {
        throw new InvalidArgumentException('ordered list must cover all movable stops');
    }
    $out = [];
    $routeOrder = 1;
    foreach (array_merge($locked, $newMovable) as $row) {
        $out[] = [
            'assignment_id' => (int)$row['id'],
            'daily_order_id' => (int)$row['daily_order_id'],
            'route_order' => $routeOrder,
            'delivery_status' => (string)($row['delivery_status'] ?? 'pending'),
        ];
        $routeOrder++;
    }
    return $out;
}

/**
 * @param array{driver_name?:string,delivery_date?:string,stores?:list<array{name?:string}>} $payload
 */
function bakery_survey_route_order_sms_body(array $payload): string
{
    $driver = trim((string)($payload['driver_name'] ?? 'Driver'));
    $date = trim((string)($payload['delivery_date'] ?? ''));
    $lines = ['Route order — ' . $driver . ($date !== '' ? (' — ' . $date) : '')];
    $n = 1;
    foreach ((array)($payload['stores'] ?? []) as $store) {
        if (!is_array($store)) {
            continue;
        }
        $name = trim((string)($store['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lines[] = $n . '. ' . $name;
        $n++;
        if (count($lines) > 40) {
            $lines[] = '…';
            break;
        }
    }
    $body = implode("\n", $lines);
    if (strlen($body) > 1500) {
        $body = substr($body, 0, 1497) . '...';
    }
    return $body;
}

/**
 * Load dated stops for one driver with customer names.
 *
 * @return array{
 *   driver_id:int,
 *   driver_name:string,
 *   delivery_date:string,
 *   locked:list<array>,
 *   movable:list<array>
 * }
 */
function bakery_survey_route_order_data(PDO $db, int $driverId, string $deliveryDate): array
{
    $deliveryDate = function_exists('bakery_survey_validate_ymd')
        ? bakery_survey_validate_ymd($deliveryDate)
        : $deliveryDate;
    $driverName = '';
    if (function_exists('bakery_get_driver_by_id')) {
        $row = bakery_get_driver_by_id($db, $driverId);
        $driverName = $row ? (string)$row['name'] : '';
    }
    $rows = [];
    if ($driverId > 0 && function_exists('table_exists') && table_exists($db, 'daily_order_assignments')) {
        $sql = 'SELECT doa.id, doa.daily_order_id, doa.route_order, doa.delivery_status,
                       do.customer_id, c.name
                FROM daily_order_assignments doa
                JOIN daily_orders do ON do.id = doa.daily_order_id
                JOIN customers c ON c.id = do.customer_id
                WHERE doa.driver_id = ? AND doa.delivery_date = ?
                ORDER BY doa.route_order, doa.id';
        $stmt = $db->prepare($sql);
        $stmt->execute([$driverId, $deliveryDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $part = bakery_survey_route_order_partition($rows);
    return [
        'driver_id' => $driverId,
        'driver_name' => $driverName,
        'delivery_date' => $deliveryDate,
        'locked' => $part['locked'],
        'movable' => $part['movable'],
    ];
}

/**
 * HQ: one group per active driver.
 *
 * @return list<array{driver_id:int,driver_name:string,locked:list,movable:list}>
 */
function bakery_survey_route_order_hq_data(PDO $db, string $deliveryDate): array
{
    $deliveryDate = function_exists('bakery_survey_validate_ymd')
        ? bakery_survey_validate_ymd($deliveryDate)
        : $deliveryDate;
    $drivers = function_exists('bakery_get_drivers') ? bakery_get_drivers($db, false) : [];
    $out = [];
    foreach ($drivers as $d) {
        $id = (int)($d['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $data = bakery_survey_route_order_data($db, $id, $deliveryDate);
        $out[] = [
            'driver_id' => $id,
            'driver_name' => $data['driver_name'] !== '' ? $data['driver_name'] : (string)($d['name'] ?? ('#' . $id)),
            'locked' => $data['locked'],
            'movable' => $data['movable'],
        ];
    }
    return $out;
}

/**
 * Token-safe apply: same rules as bakery_driver_reorder_remaining_stops, no role gate.
 *
 * @param list<int> $orderedDailyOrderIds
 * @return array{ok:bool,stops?:list,error?:string}
 */
function bakery_survey_route_order_apply(PDO $db, int $driverId, string $deliveryDate, array $orderedDailyOrderIds): array
{
    if ($driverId <= 0) {
        return ['ok' => false, 'error' => 'invalid_driver'];
    }
    if (function_exists('bakery_driver_validate_delivery_date')) {
        $deliveryDate = bakery_driver_validate_delivery_date($deliveryDate);
    }
    $collectProbe = bakery_survey_route_order_data($db, $driverId, $deliveryDate);
    $check = bakery_survey_route_order_collect($orderedDailyOrderIds, $collectProbe['movable']);
    if (!$check['ok']) {
        return ['ok' => false, 'error' => (string)($check['error'] ?? 'invalid_order')];
    }
    $orderedDailyOrderIds = $check['ordered'];
    $lockedStatuses = bakery_survey_route_order_locked_statuses();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT id, daily_order_id, route_order, delivery_status
             FROM daily_order_assignments
             WHERE driver_id = ? AND delivery_date = ?
             ORDER BY route_order, id
             FOR UPDATE'
        );
        $stmt->execute([$driverId, $deliveryDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'no_stops'];
        }
        $part = bakery_survey_route_order_partition($rows);
        $locked = $part['locked'];
        $movable = $part['movable'];
        if ($movable === []) {
            $db->rollBack();
            return ['ok' => false, 'error' => 'no_movable'];
        }
        $recheck = bakery_survey_route_order_collect($orderedDailyOrderIds, $movable);
        if (!$recheck['ok']) {
            $db->rollBack();
            return ['ok' => false, 'error' => (string)($recheck['error'] ?? 'invalid_order')];
        }
        $plan = bakery_survey_route_order_plan($locked, $movable, $recheck['ordered']);

        $db->prepare(
            'UPDATE daily_order_assignments
             SET route_order = -id
             WHERE driver_id = ? AND delivery_date = ?'
        )->execute([$driverId, $deliveryDate]);

        $updateStmt = $db->prepare('UPDATE daily_order_assignments SET route_order = ? WHERE id = ?');
        foreach ($plan as $row) {
            $updateStmt->execute([(int)$row['route_order'], (int)$row['assignment_id']]);
        }
        $db->commit();
        return ['ok' => true, 'stops' => $plan];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('survey route_order apply: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'apply_failed'];
    }
}

/**
 * @param array{
 *   survey_id?:int,
 *   driver_id:int,
 *   driver_name?:string,
 *   delivery_date:string,
 *   ordered_daily_order_ids:list<int>,
 *   stores?:list<array{name?:string}>,
 *   staff_user_id?:int
 * } $fields
 * @return array{ok:bool,apply?:array,sms_ok?:bool,recorded?:bool,error?:string}
 */
function bakery_survey_route_order_submit(PDO $db, array $fields): array
{
    $driverId = (int)($fields['driver_id'] ?? 0);
    $date = (string)($fields['delivery_date'] ?? '');
    $ordered = (array)($fields['ordered_daily_order_ids'] ?? []);
    $apply = bakery_survey_route_order_apply($db, $driverId, $date, $ordered);
    if (empty($apply['ok'])) {
        return ['ok' => false, 'apply' => $apply, 'error' => (string)($apply['error'] ?? 'apply_failed')];
    }

    $payload = [
        'driver_id' => $driverId,
        'driver_name' => (string)($fields['driver_name'] ?? ''),
        'delivery_date' => $date,
        'stores' => (array)($fields['stores'] ?? []),
        'ordered_daily_order_ids' => $ordered,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }
    $surveyId = (int)($fields['survey_id'] ?? 0);
    $recorded = false;
    if ($surveyId > 0 && function_exists('bakery_survey_record_response')) {
        bakery_survey_record_response($db, [
            'survey_id' => $surveyId,
            'action' => 'route_order',
            'respondent' => $payload['driver_name'],
            'response' => $json,
        ]);
        $recorded = true;
        if (function_exists('bakery_survey_find_by_id') && function_exists('bakery_survey_track_submit')) {
            $surveyRow = bakery_survey_find_by_id($db, $surveyId);
            if ($surveyRow) {
                bakery_survey_track_submit(
                    $db,
                    $surveyRow,
                    'route_order',
                    (int)($fields['staff_user_id'] ?? 0) ?: null,
                    $driverId > 0 ? $driverId : null
                );
            }
        }
    }

    $body = bakery_survey_route_order_sms_body($payload);
    $smsOk = false;
    if (function_exists('bakery_text_send') && function_exists('bakery_survey_hq_sms_number')) {
        $sms = bakery_text_send($db, bakery_survey_hq_sms_number(), $body, [
            'staff_user_id' => (int)($fields['staff_user_id'] ?? 0) ?: null,
            'context_type' => 'driver',
            'context_id' => $surveyId > 0 ? $surveyId : null,
            'operating_date' => $date !== '' ? $date : date('Y-m-d'),
        ]);
        $smsOk = !empty($sms['ok']) && empty($sms['recorded_only']);
    }

    return [
        'ok' => true,
        'apply' => $apply,
        'sms_ok' => $smsOk,
        'recorded' => $recorded,
    ];
}
