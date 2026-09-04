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
 * Defaults to every weekday including Sunday (Sat night → Sunday sell day).
 *
 * @param list<int> $deliveryWeekdays ISO-8601 weekdays (1=Mon … 7=Sun)
 */
function bakery_survey_next_delivery_date(string $fromDate, array $deliveryWeekdays = [1, 2, 3, 4, 5, 6, 7]): string
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
        $days = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true];
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
        if (array_key_exists('zone', $store)) {
            $row['zone'] = trim((string)$store['zone']);
        }
        if (isset($assignedLookup[$id])) {
            $assigned[] = $row;
        } else {
            $other[] = $row;
        }
    }
    return ['assigned' => $assigned, 'other' => $other];
}

/**
 * Group stores by delivery zone for phone UI. Empty zone uses $emptyLabel.
 *
 * @param list<array{id:int,name:string,zone?:string}> $stores
 * @return array<string, list<array{id:int,name:string,zone?:string}>>
 */
function bakery_survey_store_verify_group_by_zone(array $stores, string $emptyLabel = 'No zone'): array
{
    $groups = [];
    foreach ($stores as $store) {
        $id = (int)($store['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $zone = bakery_survey_store_verify_zone_key($store, $emptyLabel);
        if (!isset($groups[$zone])) {
            $groups[$zone] = [];
        }
        $groups[$zone][] = $store;
    }
    uksort($groups, static function (string $a, string $b) use ($emptyLabel): int {
        if ($a === $emptyLabel) {
            return 1;
        }
        if ($b === $emptyLabel) {
            return -1;
        }
        return strnatcasecmp($a, $b);
    });
    return $groups;
}

/**
 * Display zone for a store row (empty → $emptyLabel). Used by UI + move placement.
 *
 * @param array{zone?:string} $store
 */
function bakery_survey_store_verify_zone_key(array $store, string $emptyLabel = 'No zone'): string
{
    $zone = trim((string)($store['zone'] ?? ''));
    return $zone !== '' ? $zone : $emptyLabel;
}

/**
 * Prefer an explicit Y-m-d request over the default next-delivery day.
 */
function bakery_survey_store_verify_resolve_date(string $defaultDate, ?string $requestedDate): string
{
    $defaultDate = bakery_survey_validate_ymd($defaultDate);
    $requested = trim((string)$requestedDate);
    if ($requested === '') {
        return $defaultDate;
    }
    return bakery_survey_validate_ymd($requested);
}

/**
 * Survey-only reassign: move store ids onto to_driver ON set; drop from others.
 *
 * @param array<int, list<int>> $postedByDriver
 * @param list<array{store_id?:int,to_driver_id?:int}> $moves
 * @return array<int, list<int>>
 */
function bakery_survey_store_verify_apply_moves(array $postedByDriver, array $moves): array
{
    $out = [];
    foreach ($postedByDriver as $driverId => $ids) {
        $clean = [];
        foreach ((array)$ids as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $clean[$id] = true;
            }
        }
        $out[(int)$driverId] = array_keys($clean);
    }
    foreach ($moves as $move) {
        $storeId = (int)($move['store_id'] ?? 0);
        $toDriver = (int)($move['to_driver_id'] ?? 0);
        if ($storeId <= 0 || $toDriver <= 0) {
            continue;
        }
        foreach ($out as $driverId => $ids) {
            $out[$driverId] = array_values(array_filter(
                $ids,
                static fn(int $id): bool => $id !== $storeId
            ));
        }
        if (!isset($out[$toDriver])) {
            $out[$toDriver] = [];
        }
        if (!in_array($storeId, $out[$toDriver], true)) {
            $out[$toDriver][] = $storeId;
        }
    }
    return $out;
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
 * Defaults: assigned = ON, other = OFF. Diffs vs that baseline become
 * `added` (other turned on) and `dropped` (assigned turned off).
 *
 * @param list<int> $onIds
 * @param list<array{id:int,name:string}> $assigned
 * @param list<array{id:int,name:string}> $other
 * @return array{on:list<array{id:int,name:string}>,off:list<array{id:int,name:string}>,added:list<array{id:int,name:string}>,dropped:list<array{id:int,name:string}>,assigned_off_count:int}
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
    $dropped = [];
    foreach ($assigned as $store) {
        $id = (int)($store['id'] ?? 0);
        $name = trim((string)($store['name'] ?? ''));
        if ($id <= 0 || isset($onLookup[$id])) {
            continue;
        }
        $dropped[] = ['id' => $id, 'name' => $name !== '' ? $name : ('#' . $id)];
    }
    $added = [];
    foreach ($other as $store) {
        $id = (int)($store['id'] ?? 0);
        $name = trim((string)($store['name'] ?? ''));
        if ($id <= 0 || !isset($onLookup[$id])) {
            continue;
        }
        $added[] = ['id' => $id, 'name' => $name !== '' ? $name : ('#' . $id)];
    }
    return [
        'on' => $on,
        'off' => $off,
        'added' => $added,
        'dropped' => $dropped,
        'assigned_off_count' => count($dropped),
    ];
}

/**
 * One store name per line for HQ SMS (readable on a phone).
 *
 * @param list<array{name?:string}> $stores
 * @return list<string>
 */
function bakery_survey_store_verify_sms_name_lines(array $stores, string $prefix = ''): array
{
    $lines = [];
    foreach ($stores as $store) {
        $name = trim((string)($store['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $lines[] = $prefix . $name;
    }
    return $lines;
}

/**
 * ON list + Changed (+ added / - dropped) block for one driver.
 *
 * @param array{on?:list,added?:list,dropped?:list} $group
 * @return list<string>
 */
function bakery_survey_store_verify_sms_driver_lines(array $group): array
{
    $lines = ['ON:'];
    $onLines = bakery_survey_store_verify_sms_name_lines($group['on'] ?? []);
    if ($onLines === []) {
        $lines[] = '(none)';
    } else {
        foreach ($onLines as $line) {
            $lines[] = $line;
        }
    }
    $lines[] = 'Changed:';
    $addedLines = bakery_survey_store_verify_sms_name_lines($group['added'] ?? [], '+ ');
    $droppedLines = bakery_survey_store_verify_sms_name_lines($group['dropped'] ?? [], '- ');
    if ($addedLines === [] && $droppedLines === []) {
        $lines[] = '(none)';
    } else {
        foreach ($addedLines as $line) {
            $lines[] = $line;
        }
        foreach ($droppedLines as $line) {
            $lines[] = $line;
        }
    }
    return $lines;
}

/**
 * SMS for HQ. English on purpose — shared ops inbox, not the driver UI.
 * Stores are one-per-line; Changed lists adds (+) and drops (−) vs assigned defaults.
 *
 * @param array{driver_name?:string,delivery_date?:string,on?:list,added?:list,dropped?:list,drivers?:list,assigned_off_count?:int} $choice
 */
function bakery_survey_store_verify_sms_body(array $choice): string
{
    $date = trim((string)($choice['delivery_date'] ?? ''));
    $groups = isset($choice['drivers']) && is_array($choice['drivers']) ? $choice['drivers'] : [];
    if (count($groups) > 1) {
        $lines = ['HQ ' . $date];
        foreach ($groups as $group) {
            $driver = trim((string)($group['driver_name'] ?? ''));
            if ($driver === '') {
                $driver = 'Driver';
            }
            $lines[] = $driver;
            foreach (bakery_survey_store_verify_sms_driver_lines($group) as $line) {
                $lines[] = $line;
            }
        }
        $body = implode("\n", $lines);
    } else {
        $driver = trim((string)($choice['driver_name'] ?? 'Driver'));
        if ($driver === '') {
            $driver = 'Driver';
        }
        $block = $choice;
        if (count($groups) === 1) {
            $block = $groups[0];
            $singleDriver = trim((string)($block['driver_name'] ?? ''));
            if ($singleDriver !== '') {
                $driver = $singleDriver;
            }
        }
        $lines = array_merge(
            [$driver . ' ' . $date],
            bakery_survey_store_verify_sms_driver_lines($block)
        );
        $body = implode("\n", $lines);
    }
    // Multi-segment SMS is fine for the HQ inbox; keep a soft ceiling.
    if (strlen($body) > 1500) {
        $body = substr($body, 0, 1497) . '...';
    }
    return $body;
}

/**
 * Posted HQ toggles keyed by driver id → on/off per driver.
 *
 * @param array<int|string, list<int>> $postedByDriver
 * @param list<array{driver_id:int,driver_name:string,assigned:list,other:list}> $groups
 * @return array{drivers:list<array<string,mixed>>,on:list<array{id:int,name:string}>,off:list<array{id:int,name:string}>,assigned_off_count:int,driver_name:string}
 */
function bakery_survey_store_verify_collect_hq(array $postedByDriver, array $groups): array
{
    $drivers = [];
    $allOn = [];
    $allOff = [];
    $assignedOff = 0;
    foreach ($groups as $group) {
        $driverId = (int)($group['driver_id'] ?? 0);
        $posted = [];
        if (isset($postedByDriver[$driverId]) && is_array($postedByDriver[$driverId])) {
            $posted = $postedByDriver[$driverId];
        } elseif (isset($postedByDriver[(string)$driverId]) && is_array($postedByDriver[(string)$driverId])) {
            $posted = $postedByDriver[(string)$driverId];
        }
        $choice = bakery_survey_store_verify_collect(
            $posted,
            $group['assigned'] ?? [],
            $group['other'] ?? []
        );
        $drivers[] = [
            'driver_id' => $driverId,
            'driver_name' => (string)($group['driver_name'] ?? ''),
            'on' => $choice['on'],
            'off' => $choice['off'],
            'added' => $choice['added'],
            'dropped' => $choice['dropped'],
            'assigned_off_count' => $choice['assigned_off_count'],
        ];
        foreach ($choice['on'] as $store) {
            $allOn[] = $store;
        }
        foreach ($choice['off'] as $store) {
            $allOff[] = $store;
        }
        $assignedOff += (int)$choice['assigned_off_count'];
    }
    return [
        'drivers' => $drivers,
        'on' => $allOn,
        'off' => $allOff,
        'assigned_off_count' => $assignedOff,
        'driver_name' => 'HQ',
    ];
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
    $added = [];
    foreach ($fields['added'] ?? [] as $store) {
        $added[] = [
            'id' => (int)($store['id'] ?? 0),
            'name' => (string)($store['name'] ?? ''),
        ];
    }
    $dropped = [];
    foreach ($fields['dropped'] ?? [] as $store) {
        $dropped[] = [
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
        'added' => $added,
        'dropped' => $dropped,
        'assigned_off_count' => (int)($fields['assigned_off_count'] ?? count($dropped)),
        'drivers' => isset($fields['drivers']) && is_array($fields['drivers']) ? $fields['drivers'] : [],
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
    $list = $list !== [] ? $list : [1, 2, 3, 4, 5, 6, 7];
    if (!in_array(7, $list, true)) {
        $list[] = 7;
        sort($list);
    }
    return $list;
}

/** Open store_verify / route_review tokens are the auth — no staff PIN. */
function bakery_survey_token_allows_public(?array $survey): bool
{
    if (!is_array($survey) || $survey === []) {
        return false;
    }
    $kind = (string)($survey['kind'] ?? '');
    $status = (string)($survey['status'] ?? '');
    return $status === 'open' && in_array($kind, ['store_verify', 'route_review', 'route_order'], true);
}

function bakery_survey_page_needs_login(string $token, array $survey): bool
{
    if (trim($token) === '') {
        return true;
    }
    return !bakery_survey_token_allows_public($survey);
}

function bakery_survey_page_needs_identity(array $survey): bool
{
    return !bakery_survey_token_allows_public($survey);
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
 *   assigned:list<array{id:int,name:string,zone?:string}>,
 *   other:list<array{id:int,name:string,zone?:string}>
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
    $zoneSelect = 'c.id, c.name, COALESCE(c.zone, \'\') AS zone';

    if (function_exists('table_exists') && table_exists($db, 'daily_order_assignments')) {
        $stmt = $db->prepare(
            "SELECT DISTINCT {$zoneSelect}
             FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             JOIN customers c ON c.id = do.customer_id AND c.is_active = 1
             {$origin}
             WHERE doa.driver_id = ? AND doa.delivery_date = ?
               AND do.order_date = doa.delivery_date
             ORDER BY CAST(doa.route_order AS UNSIGNED), c.name"
        );
        $stmt->execute([$driverId, $deliveryDate]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($assignedIds[$id])) {
                continue;
            }
            $assignedIds[$id] = true;
            $assignedRows[] = [
                'id' => $id,
                'name' => (string)$row['name'],
                'zone' => (string)($row['zone'] ?? ''),
            ];
        }
    }

    // Hypothesis: dated assignments win when present; otherwise standing
    // routes for this driver's sell/delivery weekday (not bake day).
    if ($assignedRows === [] && function_exists('table_exists') && table_exists($db, 'standing_routes')) {
        $weekday = function_exists('bakery_standing_day_from_date')
            ? (int)bakery_standing_day_from_date($deliveryDate)
            : (int)date('N', strtotime($deliveryDate));
        $stmt = $db->prepare(
            "SELECT DISTINCT {$zoneSelect}
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
            $assignedRows[] = [
                'id' => $id,
                'name' => (string)$row['name'],
                'zone' => (string)($row['zone'] ?? ''),
            ];
        }
    }

    $other = [];
    if (function_exists('table_exists') && table_exists($db, 'customers')) {
        $deliveryBits = [];
        if (table_exists($db, 'standing_routes')) {
            $deliveryBits[] = 'EXISTS (SELECT 1 FROM standing_routes sr0 WHERE sr0.customer_id = c.id)';
        }
        if (table_exists($db, 'standing_orders')) {
            $deliveryBits[] = 'EXISTS (SELECT 1 FROM standing_orders so0 WHERE so0.customer_id = c.id)';
        }
        if (table_exists($db, 'daily_orders')) {
            $deliveryBits[] = 'EXISTS (SELECT 1 FROM daily_orders do0 WHERE do0.customer_id = c.id AND do0.order_date = '
                . $db->quote($deliveryDate) . ')';
        }
        $deliveryClause = $deliveryBits !== []
            ? (' AND (' . implode(' OR ', $deliveryBits) . ')')
            : '';
        $sql = "SELECT {$zoneSelect}
                FROM customers c
                WHERE c.is_active = 1
                {$origin}
                {$deliveryClause}
                ORDER BY c.name";
        foreach ($db->query($sql) as $row) {
            $id = (int)$row['id'];
            if ($id <= 0 || isset($assignedIds[$id])) {
                continue;
            }
            $other[] = [
                'id' => $id,
                'name' => (string)$row['name'],
                'zone' => (string)($row['zone'] ?? ''),
            ];
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
 * Stores that have delivery work on the HQ snapshot but are not assigned
 * to any driver yet — the night-before coverage holes.
 *
 * @param list<array{assigned?:list,other?:list}> $hqGroups
 * @return list<array{id:int,name:string,zone?:string}>
 */
function bakery_survey_store_verify_unassigned_stores(array $hqGroups): array
{
    $assignedAnywhere = [];
    $universe = [];
    foreach ($hqGroups as $group) {
        foreach ($group['assigned'] ?? [] as $store) {
            $id = (int)($store['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $assignedAnywhere[$id] = true;
            $universe[$id] = $store;
        }
        foreach ($group['other'] ?? [] as $store) {
            $id = (int)($store['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $universe[$id] = $store;
        }
    }
    $gaps = [];
    foreach ($universe as $id => $store) {
        if (!isset($assignedAnywhere[$id])) {
            $gaps[] = $store;
        }
    }
    usort($gaps, static function (array $a, array $b): int {
        return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return $gaps;
}

/**
 * Drivers with zero assigned stores on the HQ snapshot.
 *
 * @param list<array{driver_id?:int,driver_name?:string,assigned?:list}> $hqGroups
 * @return list<array{driver_id:int,driver_name:string}>
 */
function bakery_survey_store_verify_empty_drivers(array $hqGroups): array
{
    $empty = [];
    foreach ($hqGroups as $group) {
        $id = (int)($group['driver_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (($group['assigned'] ?? []) === []) {
            $empty[] = [
                'driver_id' => $id,
                'driver_name' => (string)($group['driver_name'] ?? ''),
            ];
        }
    }
    return $empty;
}

/**
 * Combined HQ snapshot: every active driver, assigned first, other stores below.
 *
 * @return list<array{driver_id:int,driver_name:string,delivery_date:string,assigned:list,other:list}>
 */
function bakery_survey_store_verify_hq_data(PDO $db, string $deliveryDate): array
{
    $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    $drivers = [];
    if (function_exists('bakery_get_drivers')) {
        $drivers = bakery_get_drivers($db, false);
    } elseif (function_exists('table_exists') && table_exists($db, 'drivers')) {
        $sql = 'SELECT id, name FROM drivers';
        if (function_exists('bakery_drivers_support_archive_column') && bakery_drivers_support_archive_column($db)) {
            $sql .= ' WHERE archived = 0';
        }
        $sql .= ' ORDER BY name ASC';
        $drivers = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    $out = [];
    foreach ($drivers as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[] = bakery_survey_store_verify_data($db, $id, $deliveryDate);
    }
    return $out;
}

/**
 * Persist the verify snapshot, apply dated route changes for the delivery day,
 * then SMS HQ. Log always wins over SMS; route apply best-effort with errors
 * recorded on the result (standing routes are never rewritten).
 *
 * @param array<string,mixed> $fields
 * @return array{payload:array<string,mixed>,sms:array<string,mixed>,sms_ok:bool,recorded:bool,route:array<string,mixed>}
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
        if (function_exists('bakery_survey_find_by_id') && function_exists('bakery_survey_track_submit')) {
            $surveyRow = bakery_survey_find_by_id($db, $surveyId);
            if ($surveyRow) {
                bakery_survey_track_submit(
                    $db,
                    $surveyRow,
                    'store_verify',
                    (int)($fields['staff_user_id'] ?? 0) ?: null,
                    (int)($fields['driver_id'] ?? 0) ?: null
                );
            }
        }
    } elseif ($surveyId > 0 && function_exists('table_exists') && table_exists($db, 'survey_responses')) {
        $stmt = $db->prepare(
            'INSERT INTO survey_responses (survey_id, action, response, respondent)
             VALUES (?,?,?,?)'
        );
        $stmt->execute([$surveyId, 'store_verify', $json, $payload['driver_name']]);
        $recorded = true;
    }

    $routeGroups = isset($payload['drivers']) && is_array($payload['drivers']) ? $payload['drivers'] : [];
    if ($routeGroups === [] && (int)($payload['driver_id'] ?? 0) > 0) {
        $routeGroups = [[
            'driver_id' => (int)$payload['driver_id'],
            'driver_name' => (string)($payload['driver_name'] ?? ''),
            'on' => $payload['on'] ?? [],
            'added' => $payload['added'] ?? [],
            'dropped' => $payload['dropped'] ?? [],
        ]];
    }
    $route = bakery_survey_store_verify_apply_routes(
        $db,
        (string)($payload['delivery_date'] ?? ''),
        $routeGroups
    );

    $body = bakery_survey_store_verify_sms_body($payload);
    $routeLine = bakery_survey_store_verify_sms_route_line($route);
    if ($routeLine !== '') {
        $body .= "\n" . $routeLine;
        if (strlen($body) > 1500) {
            $body = substr($body, 0, 1497) . '...';
        }
    }
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
        'route' => $route,
    ];
}

/**
 * Desired ON set vs current dated stops → assign / unassign lists.
 * Locked stops (delivered / in_transit) are blocked, not unassigned.
 *
 * @param list<int> $desiredOnIds
 * @param array<int, array{customer_id?:int,daily_order_id?:int,delivery_status?:string}> $currentByCustomerId
 * @return array{
 *   assign:list<int>,
 *   unassign:list<array{customer_id:int,daily_order_id:int,delivery_status:string}>,
 *   blocked:list<array{customer_id:int,daily_order_id:int,delivery_status:string}>
 * }
 */
function bakery_survey_store_verify_route_reconcile(array $desiredOnIds, array $currentByCustomerId): array
{
    $desired = [];
    foreach ($desiredOnIds as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $desired[$id] = true;
        }
    }
    $assign = [];
    foreach (array_keys($desired) as $customerId) {
        if (!isset($currentByCustomerId[$customerId])) {
            $assign[] = (int)$customerId;
        }
    }
    $unassign = [];
    $blocked = [];
    foreach ($currentByCustomerId as $customerId => $row) {
        $customerId = (int)$customerId;
        if ($customerId <= 0 || isset($desired[$customerId])) {
            continue;
        }
        $status = (string)($row['delivery_status'] ?? 'pending');
        $entry = [
            'customer_id' => $customerId,
            'daily_order_id' => (int)($row['daily_order_id'] ?? 0),
            'delivery_status' => $status,
        ];
        if (in_array($status, ['delivered', 'in_transit'], true)) {
            $blocked[] = $entry;
            continue;
        }
        $unassign[] = $entry;
    }
    return [
        'assign' => $assign,
        'unassign' => $unassign,
        'blocked' => $blocked,
    ];
}

/** One-line SMS trailer summarizing dated-route apply. */
function bakery_survey_store_verify_sms_route_line(array $route): string
{
    if (($route['skipped'] ?? '') === 'past_date') {
        return 'Route: not changed (past date)';
    }
    if (($route['skipped'] ?? '') === 'invalid_date') {
        return 'Route: not changed (bad date)';
    }
    $assigned = (int)($route['assigned'] ?? 0);
    $removed = (int)($route['removed'] ?? 0);
    $moved = (int)($route['moved'] ?? 0);
    $errors = count($route['errors'] ?? []);
    if ($assigned === 0 && $removed === 0 && $moved === 0 && $errors === 0) {
        return 'Route: unchanged';
    }
    $parts = [];
    if ($assigned > 0) {
        $parts[] = '+' . $assigned;
    }
    if ($moved > 0) {
        $parts[] = 'moved ' . $moved;
    }
    if ($removed > 0) {
        $parts[] = '-' . $removed;
    }
    if ($errors > 0) {
        $parts[] = $errors . ' error' . ($errors === 1 ? '' : 's');
    }
    return 'Route updated: ' . implode(', ', $parts);
}

/**
 * Dated assignments for one driver on a delivery day, keyed by customer id.
 *
 * @return array<int, array{customer_id:int,daily_order_id:int,assignment_id:int,delivery_status:string}>
 */
function bakery_survey_store_verify_driver_dated_stops(PDO $db, int $driverId, string $deliveryDate): array
{
    if ($driverId <= 0 || !function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT doa.id AS assignment_id, doa.daily_order_id, doa.delivery_status, do.customer_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ?
           AND do.order_date = doa.delivery_date'
    );
    $stmt->execute([$driverId, $deliveryDate]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cid = (int)($row['customer_id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $out[$cid] = [
            'customer_id' => $cid,
            'daily_order_id' => (int)($row['daily_order_id'] ?? 0),
            'assignment_id' => (int)($row['assignment_id'] ?? 0),
            'delivery_status' => (string)($row['delivery_status'] ?? 'pending'),
        ];
    }
    return $out;
}

/**
 * Put a customer on this driver's dated route (create or take from another).
 * Token-authorized survey path — does not call bakery_require_role.
 *
 * @return array{ok:bool,code:string,moved:bool}
 */
function bakery_survey_store_verify_assign_customer(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $customerId
): array {
    if ($driverId <= 0 || $customerId <= 0) {
        return ['ok' => false, 'code' => 'bad_ids', 'moved' => false];
    }
    if (!function_exists('bakery_customer_ensure_daily_order')) {
        $mutations = __DIR__ . '/customer_order_mutations.php';
        if (is_readable($mutations)) {
            require_once $mutations;
        }
    }
    if (!function_exists('bakery_driver_plan_insert_assignment')) {
        $assign = __DIR__ . '/driver_assignments.php';
        if (is_readable($assign)) {
            require_once $assign;
        }
    }
    if (!function_exists('bakery_customer_ensure_daily_order') || !function_exists('bakery_driver_plan_insert_assignment')) {
        return ['ok' => false, 'code' => 'helpers_missing', 'moved' => false];
    }

    $custStmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND is_active = 1 LIMIT 1');
    $custStmt->execute([$customerId]);
    $customer = $custStmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        return ['ok' => false, 'code' => 'customer_missing', 'moved' => false];
    }

    $db->beginTransaction();
    try {
        $existingStmt = $db->prepare(
            'SELECT doa.id, doa.daily_order_id, doa.driver_id, doa.delivery_status, doa.scheduled_delivery_time
             FROM daily_order_assignments doa
             WHERE doa.delivery_date = ?
               AND doa.daily_order_id IN (
                   SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ?
               )
             FOR UPDATE'
        );
        $existingStmt->execute([$deliveryDate, $customerId, $deliveryDate]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $assignedDriverId = (int)$existing['driver_id'];
            $status = (string)($existing['delivery_status'] ?? 'pending');
            if ($assignedDriverId === $driverId) {
                $db->commit();
                return ['ok' => true, 'code' => 'already_on_route', 'moved' => false];
            }
            if (in_array($status, ['delivered', 'in_transit'], true)) {
                $db->rollBack();
                return ['ok' => false, 'code' => 'locked_' . $status, 'moved' => false];
            }
            $scheduled = $existing['scheduled_delivery_time'] ?? null;
            $dailyOrderId = function_exists('bakery_customer_ensure_daily_order')
                ? (int)bakery_customer_ensure_daily_order($db, $customer, $deliveryDate)
                : (int)$existing['daily_order_id'];
            if (function_exists('bakery_customer_fill_empty_dated_order_from_standard')) {
                bakery_customer_fill_empty_dated_order_from_standard($db, $dailyOrderId, $deliveryDate, $customer);
            }
            $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$existing['id']]);
            bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, $scheduled);
            if (function_exists('bakery_driver_renumber_route_orders')) {
                bakery_driver_renumber_route_orders($db, [$assignedDriverId, $driverId], $deliveryDate);
            }
            $db->commit();
            if (function_exists('bakery_driver_plan_record_add')) {
                bakery_driver_plan_record_add(
                    $db,
                    $driverId,
                    $deliveryDate,
                    $customerId,
                    (string)($customer['name'] ?? ''),
                    '',
                    true,
                    ['source' => 'store_verify_survey']
                );
            }
            return ['ok' => true, 'code' => 'moved', 'moved' => true];
        }

        $dailyOrderId = (int)bakery_customer_ensure_daily_order($db, $customer, $deliveryDate);
        if (function_exists('bakery_customer_fill_empty_dated_order_from_standard')) {
            bakery_customer_fill_empty_dated_order_from_standard($db, $dailyOrderId, $deliveryDate, $customer);
        }
        bakery_driver_plan_insert_assignment($db, $driverId, $deliveryDate, $dailyOrderId, null);
        $db->commit();
        if (function_exists('bakery_driver_plan_record_add')) {
            bakery_driver_plan_record_add(
                $db,
                $driverId,
                $deliveryDate,
                $customerId,
                (string)($customer['name'] ?? ''),
                '',
                false,
                ['source' => 'store_verify_survey']
            );
        }
        return ['ok' => true, 'code' => 'added', 'moved' => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('store_verify assign: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'exception', 'moved' => false];
    }
}

/**
 * Remove a pending dated stop for this driver+customer+date.
 *
 * @return array{ok:bool,code:string}
 */
function bakery_survey_store_verify_unassign_customer(
    PDO $db,
    int $driverId,
    string $deliveryDate,
    int $customerId,
    int $dailyOrderId = 0
): array {
    if ($driverId <= 0 || $customerId <= 0) {
        return ['ok' => false, 'code' => 'bad_ids'];
    }
    if (!function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return ['ok' => false, 'code' => 'no_table'];
    }
    if (!function_exists('bakery_driver_renumber_route_orders')) {
        $assign = __DIR__ . '/driver_assignments.php';
        if (is_readable($assign)) {
            require_once $assign;
        }
    }

    $db->beginTransaction();
    try {
        if ($dailyOrderId > 0) {
            $stmt = $db->prepare(
                'SELECT id, daily_order_id, delivery_status
                 FROM daily_order_assignments
                 WHERE daily_order_id = ? AND driver_id = ? AND delivery_date = ?
                 FOR UPDATE'
            );
            $stmt->execute([$dailyOrderId, $driverId, $deliveryDate]);
        } else {
            $stmt = $db->prepare(
                'SELECT doa.id, doa.daily_order_id, doa.delivery_status
                 FROM daily_order_assignments doa
                 JOIN daily_orders do ON do.id = doa.daily_order_id
                 WHERE doa.driver_id = ? AND doa.delivery_date = ?
                   AND do.customer_id = ? AND do.order_date = doa.delivery_date
                 FOR UPDATE'
            );
            $stmt->execute([$driverId, $deliveryDate, $customerId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $db->commit();
            return ['ok' => true, 'code' => 'already_gone'];
        }
        $status = (string)($row['delivery_status'] ?? 'pending');
        if (in_array($status, ['delivered', 'in_transit'], true)) {
            $db->rollBack();
            return ['ok' => false, 'code' => 'locked_' . $status];
        }
        $orderId = (int)$row['daily_order_id'];
        $db->prepare('DELETE FROM daily_order_assignments WHERE id = ?')->execute([(int)$row['id']]);
        if (function_exists('bakery_driver_renumber_route_orders')) {
            bakery_driver_renumber_route_orders($db, [$driverId], $deliveryDate);
        }
        $db->prepare(
            'UPDATE daily_orders do
             SET do.driver_id = NULL
             WHERE do.id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM daily_order_assignments doa
                   WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
               )'
        )->execute([$orderId, $deliveryDate]);
        $db->commit();
        return ['ok' => true, 'code' => 'removed'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('store_verify unassign: ' . $e->getMessage());
        return ['ok' => false, 'code' => 'exception'];
    }
}

/**
 * Reconcile each driver's desired ON list onto dated assignments for the day.
 * Does not rewrite standing_routes.
 *
 * @param list<array{driver_id?:int,on?:list}> $groups
 * @return array{ok:bool,assigned:int,removed:int,moved:int,blocked:int,errors:list<string>,skipped?:string}
 */
function bakery_survey_store_verify_apply_routes(PDO $db, string $deliveryDate, array $groups): array
{
    $result = [
        'ok' => true,
        'assigned' => 0,
        'removed' => 0,
        'moved' => 0,
        'blocked' => 0,
        'errors' => [],
    ];
    try {
        $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    } catch (RuntimeException $e) {
        $result['ok'] = false;
        $result['skipped'] = 'invalid_date';
        $result['errors'][] = $e->getMessage();
        return $result;
    }
    if ($deliveryDate < date('Y-m-d')) {
        $result['ok'] = false;
        $result['skipped'] = 'past_date';
        return $result;
    }

    // Pass 1: ensure every desired ON store is on that driver's dated route.
    foreach ($groups as $group) {
        $driverId = (int)($group['driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $desiredIds = [];
        foreach ($group['on'] ?? [] as $store) {
            $id = (int)($store['id'] ?? $store);
            if ($id > 0) {
                $desiredIds[] = $id;
            }
        }
        $current = bakery_survey_store_verify_driver_dated_stops($db, $driverId, $deliveryDate);
        $plan = bakery_survey_store_verify_route_reconcile($desiredIds, $current);
        foreach ($plan['assign'] as $customerId) {
            $op = bakery_survey_store_verify_assign_customer($db, $driverId, $deliveryDate, $customerId);
            if (!empty($op['ok'])) {
                if (!empty($op['moved'])) {
                    $result['moved']++;
                } elseif (($op['code'] ?? '') === 'added') {
                    $result['assigned']++;
                }
            } else {
                $result['ok'] = false;
                $result['errors'][] = 'assign customer ' . $customerId . ' → driver ' . $driverId . ': ' . ($op['code'] ?? 'fail');
            }
        }
    }

    // Pass 2: drop dated stops no longer in each driver's ON set.
    foreach ($groups as $group) {
        $driverId = (int)($group['driver_id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $desiredIds = [];
        foreach ($group['on'] ?? [] as $store) {
            $id = (int)($store['id'] ?? $store);
            if ($id > 0) {
                $desiredIds[] = $id;
            }
        }
        $current = bakery_survey_store_verify_driver_dated_stops($db, $driverId, $deliveryDate);
        $plan = bakery_survey_store_verify_route_reconcile($desiredIds, $current);
        $result['blocked'] += count($plan['blocked']);
        foreach ($plan['unassign'] as $row) {
            $op = bakery_survey_store_verify_unassign_customer(
                $db,
                $driverId,
                $deliveryDate,
                (int)$row['customer_id'],
                (int)$row['daily_order_id']
            );
            if (!empty($op['ok']) && ($op['code'] ?? '') === 'removed') {
                $result['removed']++;
            } elseif (empty($op['ok'])) {
                $result['ok'] = false;
                $result['errors'][] = 'unassign customer ' . (int)$row['customer_id']
                    . ' ← driver ' . $driverId . ': ' . ($op['code'] ?? 'fail');
            }
        }
    }

    return $result;
}
