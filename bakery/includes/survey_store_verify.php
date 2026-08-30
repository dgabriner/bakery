<?php
/**
 * Driver store-verify: which stores this driver will cover on the next
 * sell/delivery day (not bake day). Pure helpers live here so CLI tests
 * can run without bakerysf_test. Page + SMS wiring stay on survey.php.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Headquarters SMS inbox already used by My Route / exception desk. */
function bakery_survey_hq_sms_number(): string
{
    return '+14155091210';
}

/**
 * Canonical Y-m-d or throw. Shared so tests and the page reject junk dates.
 */
function bakery_survey_validate_ymd(string $date): string
{
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new RuntimeException('Invalid date');
    }
    return $date;
}

/**
 * Soonest sell/delivery date after $fromDate whose weekday is a delivery day.
 * Defaults to Mon–Sat (Sunday is a typical Sour Flour bake, not a sell day).
 *
 * @param list<int> $deliveryWeekdays ISO-8601 weekdays (1=Mon … 7=Sun)
 */
function bakery_survey_next_delivery_date(string $fromDate, array $deliveryWeekdays = [1, 2, 3, 4, 5, 6]): string
{
    $fromDate = bakery_survey_validate_ymd($fromDate);
    $days = [];
    foreach ($deliveryWeekdays as $day) {
        $day = (int)$day;
        if ($day === 0) {
            $day = 7;
        }
        if ($day >= 1 && $day <= 7) {
            $days[$day] = true;
        }
    }
    if ($days === []) {
        $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true];
    }
    $cursor = new DateTime($fromDate);
    for ($i = 0; $i < 14; $i++) {
        $cursor->modify('+1 day');
        $weekday = (int)$cursor->format('N');
        if (isset($days[$weekday])) {
            return $cursor->format('Y-m-d');
        }
    }
    return $cursor->format('Y-m-d');
}

/**
 * @param list<array{id:int,name:string}> $stores
 * @param list<int> $assignedIds
 * @return array{assigned:list<array{id:int,name:string}>,other:list<array{id:int,name:string}>}
 */
function bakery_survey_store_verify_partition(array $stores, array $assignedIds): array
{
    $assignedLookup = [];
    foreach ($assignedIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $assignedLookup[$id] = true;
        }
    }
    $assigned = [];
    $other = [];
    foreach ($stores as $store) {
        $id = (int)($store['id'] ?? 0);
        $name = trim((string)($store['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }
        $row = ['id' => $id, 'name' => $name];
        if (isset($assignedLookup[$id])) {
            $assigned[] = $row;
        } else {
            $other[] = $row;
        }
    }
    return ['assigned' => $assigned, 'other' => $other];
}

/**
 * Default ON set: assigned stores only.
 *
 * @param list<array{id:int,name:string}> $assigned
 * @param list<array{id:int,name:string}> $other
 * @return list<int>
 */
function bakery_survey_store_verify_default_on_ids(array $assigned, array $other): array
{
    unset($other);
    $ids = [];
    foreach ($assigned as $store) {
        $id = (int)($store['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

/**
 * Apply posted ON ids to assigned + other lists.
 *
 * @param list<int> $onIds
 * @param list<array{id:int,name:string}> $assigned
 * @param list<array{id:int,name:string}> $other
 * @return array{on:list<array{id:int,name:string}>,off:list<array{id:int,name:string}>,assigned_off_count:int}
 */
function bakery_survey_store_verify_collect(array $onIds, array $assigned, array $other): array
{
    $onLookup = [];
    foreach ($onIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $onLookup[$id] = true;
        }
    }
    $on = [];
    $off = [];
    $assignedOff = 0;
    foreach (array_merge($assigned, $other) as $store) {
        $id = (int)($store['id'] ?? 0);
        $name = trim((string)($store['name'] ?? ''));
        if ($id <= 0) {
            continue;
        }
        $row = ['id' => $id, 'name' => $name !== '' ? $name : ('#' . $id)];
        if (isset($onLookup[$id])) {
            $on[] = $row;
        } else {
            $off[] = $row;
        }
    }
    foreach ($assigned as $store) {
        $id = (int)($store['id'] ?? 0);
        if ($id > 0 && !isset($onLookup[$id])) {
            $assignedOff++;
        }
    }
    return [
        'on' => $on,
        'off' => $off,
        'assigned_off_count' => $assignedOff,
    ];
}

/**
 * Short SMS for HQ. English on purpose — the inbox is shared ops, not the driver UI.
 *
 * @param array{driver_name?:string,delivery_date?:string,on?:list<array{name?:string}>,assigned_off_count?:int} $choice
 */
function bakery_survey_store_verify_sms_body(array $choice): string
{
    $driver = trim((string)($choice['driver_name'] ?? 'Driver'));
    if ($driver === '') {
        $driver = 'Driver';
    }
    $date = trim((string)($choice['delivery_date'] ?? ''));
    $names = [];
    foreach ($choice['on'] ?? [] as $store) {
        $name = trim((string)($store['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    $onList = $names === [] ? '(none)' : implode(', ', $names);
    $assignedOff = (int)($choice['assigned_off_count'] ?? 0);
    $body = $driver . ' ' . $date . "\nON: " . $onList;
    if ($assignedOff > 0) {
        $body .= "\nAssigned off: " . $assignedOff;
    }
    if (strlen($body) > 320) {
        $body = substr($body, 0, 317) . '...';
    }
    return $body;
}

/**
 * Durable log shape (driver, timestamp, delivery date, stores on vs off).
 *
 * @param array<string,mixed> $fields
 * @return array<string,mixed>
 */
function bakery_survey_store_verify_log_payload(array $fields): array
{
    $on = [];
    foreach ($fields['on'] ?? [] as $store) {
        $on[] = [
            'id' => (int)($store['id'] ?? 0),
            'name' => (string)($store['name'] ?? ''),
        ];
    }
    $off = [];
    foreach ($fields['off'] ?? [] as $store) {
        $off[] = [
            'id' => (int)($store['id'] ?? 0),
            'name' => (string)($store['name'] ?? ''),
        ];
    }
    $created = trim((string)($fields['created_at'] ?? ''));
    if ($created === '') {
        $created = date('Y-m-d H:i:s');
    }
    return [
        'driver_id' => (int)($fields['driver_id'] ?? 0),
        'driver_name' => (string)($fields['driver_name'] ?? ''),
        'delivery_date' => (string)($fields['delivery_date'] ?? ''),
        'created_at' => $created,
        'on' => $on,
        'off' => $off,
        'assigned_off_count' => (int)($fields['assigned_off_count'] ?? 0),
    ];
}

/**
 * Weekdays that actually have standing route stops (sell/delivery days).
 *
 * @return list<int>
 */
function bakery_survey_delivery_weekdays(PDO $db): array
{
    $days = [];
    if (function_exists('table_exists') && table_exists($db, 'standing_routes')) {
        $sql = 'SELECT DISTINCT CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END AS dow
                FROM standing_routes sr
                JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1';
        if (function_exists('bakery_sfb_ops_origin_clause')) {
            $sql .= bakery_sfb_ops_origin_clause('c', $db);
        }
        foreach ($db->query($sql) as $row) {
            $dow = (int)$row['dow'];
            if ($dow >= 1 && $dow <= 7) {
                $days[$dow] = true;
            }
        }
    }
    if ($days === [] && function_exists('table_exists') && table_exists($db, 'standing_orders')) {
        $sql = 'SELECT DISTINCT CASE WHEN so.day_of_week = 0 THEN 7 ELSE so.day_of_week END AS dow
                FROM standing_orders so
                JOIN customers c ON c.id = so.customer_id AND c.is_active = 1';
        if (function_exists('bakery_sfb_ops_origin_clause')) {
            $sql .= bakery_sfb_ops_origin_clause('c', $db);
        }
        foreach ($db->query($sql) as $row) {
            $dow = (int)$row['dow'];
            if ($dow >= 1 && $dow <= 7) {
                $days[$dow] = true;
            }
        }
    }
    $list = array_map('intval', array_keys($days));
    sort($list);
    return $list !== [] ? $list : [1, 2, 3, 4, 5, 6];
}

/**
 * Assigned = dated assignments for this driver+date when any exist;
 * otherwise standing-route customers for that sell/delivery weekday.
 * Other = active delivery customers not on that assignment.
 *
 * @return array{
 *   driver_id:int,
 *   driver_name:string,
 *   delivery_date:string,
 *   assigned:list<array{id:int,name:string}>,
 *   other:list<array{id:int,name:string}>
 * }
 */
function bakery_survey_store_verify_data(PDO $db, int $driverId, string $deliveryDate): array
{
    $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    $driverName = '';
    if (function_exists('bakery_get_driver_by_id')) {
        $row = bakery_get_driver_by_id($db, $driverId);
        $driverName = $row ? (string)$row['name'] : '';
    } elseif (function_exists('table_exists') && table_exists($db, 'drivers')) {
        $stmt = $db->prepare('SELECT name FROM drivers WHERE id = ?');
        $stmt->execute([$driverId]);
        $driverName = (string)$stmt->fetchColumn();
    }

    $origin = function_exists('bakery_sfb_ops_origin_clause')
        ? bakery_sfb_ops_origin_clause('c', $db)
        : '';

    $assignedIds = [];
    $assignedRows = [];

    if (function_exists('table_exists') && table_exists($db, 'daily_order_assignments')) {
        $stmt = $db->prepare(
            "SELECT DISTINCT c.id, c.name
             FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             JOIN customers c ON c.id = do.customer_id AND c.is_active = 1
             {$origin}
             WHERE doa.driver_id = ? AND doa.delivery_date = ?
             ORDER BY CAST(doa.route_order AS UNSIGNED), c.name"
        );
        $stmt->execute([$driverId, $deliveryDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($assignedIds[$id])) {
                continue;
            }
            $assignedIds[$id] = true;
            $assignedRows[] = ['id' => $id, 'name' => (string)$row['name']];
        }
    }

    // Hypothesis: dated assignments win when present; otherwise standing
    // routes for this driver's sell/delivery weekday (not bake day).
    if ($assignedRows === [] && function_exists('table_exists') && table_exists($db, 'standing_routes')) {
        $weekday = function_exists('bakery_standing_day_from_date')
            ? (int)bakery_standing_day_from_date($deliveryDate)
            : (int)date('N', strtotime($deliveryDate));
        $stmt = $db->prepare(
            "SELECT DISTINCT c.id, c.name
             FROM standing_routes sr
             JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
             {$origin}
             WHERE sr.driver_id = ?
               AND CASE WHEN sr.day_of_week = 0 THEN 7 ELSE sr.day_of_week END = ?
             ORDER BY COALESCE(sr.route_order, 2147483647), c.name"
        );
        $stmt->execute([$driverId, $weekday]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($assignedIds[$id])) {
                continue;
            }
            $assignedIds[$id] = true;
            $assignedRows[] = ['id' => $id, 'name' => (string)$row['name']];
        }
    }

    $other = [];
    if (function_exists('table_exists') && table_exists($db, 'customers')) {
        $sql = "SELECT c.id, c.name
                FROM customers c
                WHERE c.is_active = 1
                {$origin}
                ORDER BY c.name";
        foreach ($db->query($sql) as $row) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($assignedIds[$id])) {
                continue;
            }
            $other[] = ['id' => $id, 'name' => (string)$row['name']];
        }
    }

    return [
        'driver_id' => $driverId,
        'driver_name' => $driverName,
        'delivery_date' => $deliveryDate,
        'assigned' => $assignedRows,
        'other' => $other,
    ];
}

/**
 * Persist the verify snapshot, then SMS HQ. Log always wins over SMS.
 *
 * @param array<string,mixed> $fields
 * @return array{payload:array<string,mixed>,sms:array<string,mixed>,sms_ok:bool,recorded:bool}
 */
function bakery_survey_store_verify_submit(PDO $db, array $fields): array
{
    $payload = bakery_survey_store_verify_log_payload($fields);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $json = '{}';
    }

    $surveyId = (int)($fields['survey_id'] ?? 0);
    $recorded = false;
    if ($surveyId > 0 && function_exists('bakery_survey_record_response')) {
        bakery_survey_record_response($db, [
            'survey_id' => $surveyId,
            'action' => 'store_verify',
            'respondent' => $payload['driver_name'],
            'response' => $json,
        ]);
        $recorded = true;
    } elseif ($surveyId > 0 && function_exists('table_exists') && table_exists($db, 'survey_responses')) {
        $stmt = $db->prepare(
            'INSERT INTO survey_responses (survey_id, action, response, respondent)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([$surveyId, 'store_verify', $json, $payload['driver_name']]);
        $recorded = true;
    }

    $body = bakery_survey_store_verify_sms_body($payload);
    error_log('survey store-verify SMS payload: ' . $body);

    $sms = [
        'ok' => false,
        'recorded_only' => false,
        'status' => 'failed',
        'error' => 'sms_helper_missing',
        'id' => 0,
    ];
    if (!function_exists('bakery_text_send')) {
        $textComms = dirname(__DIR__) . '/includes/text_comms.php';
        if (is_readable($textComms)) {
            require_once $textComms;
        }
    }
    if (function_exists('bakery_text_send')) {
        $sms = bakery_text_send($db, bakery_survey_hq_sms_number(), $body, [
            'staff_user_id' => (int)($fields['staff_user_id'] ?? 0) ?: null,
            'context_type' => 'driver',
            'context_id' => $surveyId > 0 ? $surveyId : null,
            'operating_date' => $payload['delivery_date'] !== '' ? $payload['delivery_date'] : date('Y-m-d'),
        ]);
        if (!empty($sms['id']) && $surveyId > 0 && function_exists('bakery_survey_record_response')) {
            bakery_survey_record_response($db, [
                'survey_id' => $surveyId,
                'text_message_id' => (int)$sms['id'],
                'action' => 'sent',
                'response' => !empty($sms['ok']) ? 'store_verify_sms' : 'store_verify_sms_logged',
            ]);
        }
    }

    $smsOk = !empty($sms['ok']) && empty($sms['recorded_only']);
    return [
        'payload' => $payload,
        'sms' => $sms,
        'sms_ok' => $smsOk,
        'recorded' => $recorded,
    ];
}
