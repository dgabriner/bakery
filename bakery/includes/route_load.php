<?php
/**
 * Projected route time for admins and managers.
 *
 * Projected finish = route start + each stop's service minutes + drive minutes
 * from the previous stop. The first stop has no previous drive.
 *
 * Service minutes: learned median from the arrival photo to the delivery photo
 * or close. Closes under the bulk threshold (default 2 minutes) are ignored,
 * then outliers are trimmed. A manager override wins over history. Otherwise
 * the configurable default (20 minutes).
 *
 * Drive minutes: learned median from the previous close to the next arrival
 * once the sample count reaches the minimum. Else a straight-line estimate
 * from stored latitude and longitude at the configured speed. Else the
 * default (15 minutes). No map API and no secret.
 *
 * A route is flagged when the finish is after the limit (default 5:00 PM)
 * or the stop count is over the limit (default 15). The flag never edits
 * the route. Carried stops are ordinary assignments, so they count.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/audit_log.php';

/**
 * @return array{
 *   finish_after:string,
 *   max_stops:int,
 *   default_service_minutes:int,
 *   default_drive_minutes:int,
 *   min_drive_samples:int,
 *   min_service_samples:int,
 *   route_start:string,
 *   drive_speed_mph:float,
 *   outlier_trim_percent:int,
 *   bulk_close_minutes:int,
 *   lookback_days:int
 * }
 */
function bakery_route_load_defaults(): array
{
    return [
        'finish_after' => '17:00:00',
        'max_stops' => 15,
        'default_service_minutes' => 20,
        'default_drive_minutes' => 15,
        'min_drive_samples' => 3,
        'min_service_samples' => 1,
        'route_start' => '08:00:00',
        'drive_speed_mph' => 15.0,
        'outlier_trim_percent' => 10,
        'bulk_close_minutes' => 2,
        'lookback_days' => 90,
    ];
}

function bakery_route_load_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'route_load_settings');
}

/**
 * @return array<string, mixed>
 */
function bakery_route_load_settings(PDO $db): array
{
    $settings = bakery_route_load_defaults();
    if (!bakery_route_load_ready($db)) {
        return $settings;
    }
    $row = $db->query('SELECT * FROM route_load_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $settings;
    }
    $settings['finish_after'] = bakery_route_load_normalize_time((string)($row['finish_after'] ?? ''), '17:00:00');
    $settings['max_stops'] = max(1, (int)($row['max_stops'] ?? 15));
    $settings['default_service_minutes'] = max(1, (int)($row['default_service_minutes'] ?? 20));
    $settings['default_drive_minutes'] = max(1, (int)($row['default_drive_minutes'] ?? 15));
    $settings['min_drive_samples'] = max(1, (int)($row['min_drive_samples'] ?? 3));
    $settings['min_service_samples'] = max(1, (int)($row['min_service_samples'] ?? 1));
    $settings['route_start'] = bakery_route_load_normalize_time((string)($row['route_start'] ?? ''), '08:00:00');
    $settings['drive_speed_mph'] = max(1.0, (float)($row['drive_speed_mph'] ?? 15));
    $settings['outlier_trim_percent'] = max(0, min(40, (int)($row['outlier_trim_percent'] ?? 10)));
    $settings['bulk_close_minutes'] = max(1, (int)($row['bulk_close_minutes'] ?? 2));
    $settings['lookback_days'] = max(7, min(365, (int)($row['lookback_days'] ?? 90)));
    return $settings;
}

function bakery_route_load_normalize_time(string $value, string $fallback): string
{
    $value = trim($value);
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $match) !== 1) {
        return $fallback;
    }
    $hour = (int)$match[1];
    $minute = (int)$match[2];
    $second = isset($match[3]) ? (int)$match[3] : 0;
    if ($hour > 23 || $minute > 59 || $second > 59) {
        return $fallback;
    }
    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

function bakery_route_load_clock_minutes(string $time): int
{
    $time = bakery_route_load_normalize_time($time, '00:00:00');
    $parts = explode(':', $time);
    return ((int)$parts[0] * 60) + (int)$parts[1];
}

/**
 * Clock like 10:30 PM. Same shape in every language so the warning line
 * can match "termina ~10:30 PM".
 */
function bakery_route_load_format_clock(int $minutesFromMidnight): string
{
    $day = intdiv($minutesFromMidnight, 1440);
    $mins = $minutesFromMidnight % 1440;
    if ($mins < 0) {
        $mins += 1440;
        $day -= 1;
    }
    $hour = intdiv($mins, 60);
    $minute = $mins % 60;
    $suffix = $hour >= 12 ? 'PM' : 'AM';
    $hour12 = $hour % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }
    $label = $hour12 . ':' . str_pad((string)$minute, 2, '0', STR_PAD_LEFT) . ' ' . $suffix;
    if ($day > 0) {
        $label .= ' +' . $day;
    }
    return $label;
}

function bakery_route_load_minutes_between(?string $start, ?string $end): ?int
{
    $start = trim((string)$start);
    $end = trim((string)$end);
    if ($start === '' || $end === '') {
        return null;
    }
    $from = strtotime($start);
    $to = strtotime($end);
    if ($from === false || $to === false || $to < $from) {
        return null;
    }
    return (int)floor(($to - $from) / 60);
}

/**
 * Drop the lowest and highest slice once there are enough points to trim.
 *
 * @param list<int|float> $values
 * @return list<float>
 */
function bakery_route_load_trim_outliers(array $values, int $trimPercent): array
{
    $nums = [];
    foreach ($values as $value) {
        if (is_numeric($value)) {
            $nums[] = (float)$value;
        }
    }
    sort($nums, SORT_NUMERIC);
    $count = count($nums);
    if ($trimPercent <= 0 || $count < 5) {
        return $nums;
    }
    $drop = (int)floor($count * $trimPercent / 100);
    if ($drop < 1) {
        $drop = 1;
    }
    if (($drop * 2) >= $count || ($count - ($drop * 2)) < 3) {
        return $nums;
    }
    return array_values(array_slice($nums, $drop, $count - (2 * $drop)));
}

/**
 * @param list<int|float> $values
 */
function bakery_route_load_median(array $values): ?float
{
    $nums = bakery_route_load_trim_outliers($values, 0);
    $count = count($nums);
    if ($count === 0) {
        return null;
    }
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return $nums[$mid];
    }
    return ($nums[$mid - 1] + $nums[$mid]) / 2.0;
}

/**
 * Ignore bulk-stamped closes under the threshold, then trim outliers.
 *
 * @param list<int|float> $samples
 * @return list<float>
 */
function bakery_route_load_service_samples(array $samples, int $bulkUnder, int $trimPercent): array
{
    $kept = [];
    foreach ($samples as $sample) {
        if (!is_numeric($sample)) {
            continue;
        }
        $minutes = (float)$sample;
        if ($minutes < $bulkUnder) {
            continue;
        }
        $kept[] = $minutes;
    }
    return bakery_route_load_trim_outliers($kept, $trimPercent);
}

/**
 * A manager override wins. History is next. The default is last.
 *
 * @param list<int|float> $samples
 * @return array{minutes:int,source:string,samples:int}
 */
function bakery_route_load_service_choice(
    array $samples,
    ?int $override,
    int $defaultMinutes,
    int $minSamples,
    int $trimPercent,
    int $bulkUnder
): array {
    $trimmed = bakery_route_load_service_samples($samples, $bulkUnder, $trimPercent);
    $count = count($trimmed);
    if ($override !== null && $override > 0) {
        return ['minutes' => $override, 'source' => 'override', 'samples' => $count];
    }
    if ($count >= max(1, $minSamples)) {
        $median = bakery_route_load_median($trimmed);
        if ($median !== null) {
            return ['minutes' => max(1, (int)round($median)), 'source' => 'history', 'samples' => $count];
        }
    }
    return ['minutes' => max(1, $defaultMinutes), 'source' => 'default', 'samples' => $count];
}

/**
 * History once enough samples exist. Else straight-line minutes. Else default.
 *
 * @param list<int|float> $samples
 * @return array{minutes:int,source:string,samples:int}
 */
function bakery_route_load_drive_choice(
    array $samples,
    int $minSamples,
    int $trimPercent,
    int $bulkUnder,
    ?int $straightMinutes,
    int $defaultMinutes
): array {
    $trimmed = bakery_route_load_service_samples($samples, $bulkUnder, $trimPercent);
    $count = count($trimmed);
    if ($count >= max(1, $minSamples)) {
        $median = bakery_route_load_median($trimmed);
        if ($median !== null) {
            return ['minutes' => max(1, (int)round($median)), 'source' => 'history', 'samples' => $count];
        }
    }
    if ($straightMinutes !== null && $straightMinutes > 0) {
        return ['minutes' => $straightMinutes, 'source' => 'distance', 'samples' => $count];
    }
    return ['minutes' => max(1, $defaultMinutes), 'source' => 'default', 'samples' => $count];
}

/**
 * Stored median already had bulk closes and outliers removed.
 *
 * @param array{median:float,samples:int}|null $stat
 * @return array{minutes:int,source:string,samples:int}
 */
function bakery_route_load_stored_service(?array $stat, ?int $override, int $defaultMinutes, int $minSamples): array
{
    $samples = is_array($stat) ? (int)$stat['samples'] : 0;
    if ($override !== null && $override > 0) {
        return ['minutes' => $override, 'source' => 'override', 'samples' => $samples];
    }
    if ($samples >= max(1, $minSamples) && is_array($stat)) {
        return [
            'minutes' => max(1, (int)round((float)$stat['median'])),
            'source' => 'history',
            'samples' => $samples,
        ];
    }
    return ['minutes' => max(1, $defaultMinutes), 'source' => 'default', 'samples' => $samples];
}

/**
 * @param array{median:float,samples:int}|null $stat
 * @return array{minutes:int,source:string,samples:int}
 */
function bakery_route_load_stored_drive(?array $stat, int $minSamples, ?int $straightMinutes, int $defaultMinutes): array
{
    $samples = is_array($stat) ? (int)$stat['samples'] : 0;
    if ($samples >= max(1, $minSamples) && is_array($stat)) {
        return [
            'minutes' => max(1, (int)round((float)$stat['median'])),
            'source' => 'history',
            'samples' => $samples,
        ];
    }
    if ($straightMinutes !== null && $straightMinutes > 0) {
        return ['minutes' => $straightMinutes, 'source' => 'distance', 'samples' => $samples];
    }
    return ['minutes' => max(1, $defaultMinutes), 'source' => 'default', 'samples' => $samples];
}

function bakery_route_load_haversine_minutes(
    ?float $lat1,
    ?float $lng1,
    ?float $lat2,
    ?float $lng2,
    float $mph
): ?int {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null || $mph <= 0) {
        return null;
    }
    if (abs($lat1) < 0.000001 && abs($lng1) < 0.000001) {
        return null;
    }
    if (abs($lat2) < 0.000001 && abs($lng2) < 0.000001) {
        return null;
    }
    $earth = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $miles = $earth * (2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a))));
    if ($miles < 0.05) {
        return 1;
    }
    return max(1, (int)round($miles / $mph * 60));
}

/**
 * @param list<array{service:int,drive:int}> $stops
 * @return array{
 *   minutes:int,
 *   finish_minutes:int,
 *   finish_label:string,
 *   stop_count:int,
 *   over_time:bool,
 *   over_stops:bool,
 *   warn:bool
 * }
 */
function bakery_route_load_project(string $startTime, array $stops, string $finishAfter, int $maxStops): array
{
    $total = 0;
    foreach ($stops as $stop) {
        $total += max(0, (int)($stop['service'] ?? 0)) + max(0, (int)($stop['drive'] ?? 0));
    }
    $finish = bakery_route_load_clock_minutes($startTime) + $total;
    $limit = bakery_route_load_clock_minutes($finishAfter);
    $stopCount = count($stops);
    $overTime = $finish > $limit;
    $overStops = $stopCount > $maxStops;
    return [
        'minutes' => $total,
        'finish_minutes' => $finish,
        'finish_label' => bakery_route_load_format_clock($finish),
        'stop_count' => $stopCount,
        'over_time' => $overTime,
        'over_stops' => $overStops,
        'warn' => $overTime || $overStops,
    ];
}

function bakery_route_load_role_may_see_warning(?string $role): bool
{
    return $role === 'administrator' || $role === 'manager';
}

/**
 * @param array<string, mixed>|null $user
 */
function bakery_route_load_viewer_may_see_warning(?array $user = null): bool
{
    if ($user === null && function_exists('bakery_current_user')) {
        $current = bakery_current_user();
        $user = is_array($current) ? $current : null;
    }
    $role = is_array($user) ? (string)($user['role_slug'] ?? '') : '';
    return bakery_route_load_role_may_see_warning($role);
}

function bakery_route_load_text(string $key, array $params, string $fallback): string
{
    if (function_exists('bakery_t')) {
        $text = bakery_t($key, $params);
        if ($text !== '' && $text !== $key) {
            return $text;
        }
    }
    $text = $fallback;
    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string)$value, $text);
    }
    return $text;
}

function bakery_route_load_warning_line(string $name, int $stops, string $time): string
{
    $key = $stops === 1 ? 'route_load.warning_line_one' : 'route_load.warning_line';
    $fallback = $stops === 1
        ? ':name: 1 stop, finishes ~:time'
        : ':name: :stops stops, finishes ~:time';
    return bakery_route_load_text($key, [
        'name' => $name,
        'stops' => (string)$stops,
        'time' => $time,
    ], $fallback);
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function bakery_route_load_save_settings(PDO $db, array $input): array
{
    if (!bakery_route_load_ready($db)) {
        throw new RuntimeException('Route load settings are not installed.');
    }
    $current = bakery_route_load_settings($db);
    $next = $current;
    $next['finish_after'] = bakery_route_load_normalize_time((string)($input['finish_after'] ?? ''), $current['finish_after']);
    $next['route_start'] = bakery_route_load_normalize_time((string)($input['route_start'] ?? ''), $current['route_start']);
    $next['max_stops'] = max(1, min(200, (int)($input['max_stops'] ?? $current['max_stops'])));
    $next['default_service_minutes'] = max(1, min(180, (int)($input['default_service_minutes'] ?? $current['default_service_minutes'])));
    $next['default_drive_minutes'] = max(1, min(180, (int)($input['default_drive_minutes'] ?? $current['default_drive_minutes'])));
    $next['min_drive_samples'] = max(1, min(50, (int)($input['min_drive_samples'] ?? $current['min_drive_samples'])));
    $next['min_service_samples'] = max(1, min(50, (int)($input['min_service_samples'] ?? $current['min_service_samples'])));
    $next['drive_speed_mph'] = max(1.0, min(70.0, (float)($input['drive_speed_mph'] ?? $current['drive_speed_mph'])));
    $next['outlier_trim_percent'] = max(0, min(40, (int)($input['outlier_trim_percent'] ?? $current['outlier_trim_percent'])));
    $next['bulk_close_minutes'] = max(1, min(30, (int)($input['bulk_close_minutes'] ?? $current['bulk_close_minutes'])));
    $next['lookback_days'] = max(7, min(365, (int)($input['lookback_days'] ?? $current['lookback_days'])));
    $db->prepare(
        'INSERT INTO route_load_settings (
            id, finish_after, max_stops, default_service_minutes, default_drive_minutes,
            min_drive_samples, min_service_samples, route_start, drive_speed_mph,
            outlier_trim_percent, bulk_close_minutes, lookback_days
         ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            finish_after = VALUES(finish_after),
            max_stops = VALUES(max_stops),
            default_service_minutes = VALUES(default_service_minutes),
            default_drive_minutes = VALUES(default_drive_minutes),
            min_drive_samples = VALUES(min_drive_samples),
            min_service_samples = VALUES(min_service_samples),
            route_start = VALUES(route_start),
            drive_speed_mph = VALUES(drive_speed_mph),
            outlier_trim_percent = VALUES(outlier_trim_percent),
            bulk_close_minutes = VALUES(bulk_close_minutes),
            lookback_days = VALUES(lookback_days)'
    )->execute([
        $next['finish_after'],
        $next['max_stops'],
        $next['default_service_minutes'],
        $next['default_drive_minutes'],
        $next['min_drive_samples'],
        $next['min_service_samples'],
        $next['route_start'],
        $next['drive_speed_mph'],
        $next['outlier_trim_percent'],
        $next['bulk_close_minutes'],
        $next['lookback_days'],
    ]);
    bakery_audit_write(
        $db,
        'route_load_settings',
        1,
        'save',
        'limits',
        (string)$current['max_stops'] . ' @ ' . $current['finish_after'],
        (string)$next['max_stops'] . ' @ ' . $next['finish_after']
    );
    return bakery_route_load_settings($db);
}

function bakery_route_load_save_override(PDO $db, int $customerId, ?int $minutes): void
{
    if ($customerId <= 0 || !function_exists('table_exists') || !table_exists($db, 'route_service_overrides')) {
        throw new RuntimeException('Store service override is not installed.');
    }
    $old = null;
    $stmt = $db->prepare('SELECT minutes FROM route_service_overrides WHERE customer_id = ?');
    $stmt->execute([$customerId]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        $old = (string)(int)$existing;
    }
    if ($minutes === null || $minutes <= 0) {
        $db->prepare('DELETE FROM route_service_overrides WHERE customer_id = ?')->execute([$customerId]);
        $new = null;
    } else {
        $minutes = max(1, min(180, $minutes));
        $db->prepare(
            'INSERT INTO route_service_overrides (customer_id, minutes) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE minutes = VALUES(minutes)'
        )->execute([$customerId, $minutes]);
        $new = (string)$minutes;
    }
    bakery_audit_write($db, 'route_service_override', $customerId, 'save', 'minutes', $old, $new);
}

/**
 * @return array<int, int>
 */
function bakery_route_load_overrides(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'route_service_overrides')) {
        return [];
    }
    $map = [];
    foreach ($db->query('SELECT customer_id, minutes FROM route_service_overrides') as $row) {
        $map[(int)$row['customer_id']] = (int)$row['minutes'];
    }
    return $map;
}

/**
 * @return array<int, array{median:float,samples:int}>
 */
function bakery_route_load_service_stat_map(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'route_service_stats')) {
        return [];
    }
    $map = [];
    foreach ($db->query('SELECT customer_id, median_minutes, sample_count FROM route_service_stats') as $row) {
        $map[(int)$row['customer_id']] = [
            'median' => (float)$row['median_minutes'],
            'samples' => (int)$row['sample_count'],
        ];
    }
    return $map;
}

/**
 * @return array<string, array{median:float,samples:int}>
 */
function bakery_route_load_drive_stat_map(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'route_drive_stats')) {
        return [];
    }
    $map = [];
    foreach ($db->query('SELECT from_customer_id, to_customer_id, median_minutes, sample_count FROM route_drive_stats') as $row) {
        $key = (int)$row['from_customer_id'] . ':' . (int)$row['to_customer_id'];
        $map[$key] = [
            'median' => (float)$row['median_minutes'],
            'samples' => (int)$row['sample_count'],
        ];
    }
    return $map;
}

/**
 * @return array<int, string> driver_id => clock-in datetime
 */
function bakery_route_load_clock_ins(PDO $db, string $date): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'time_clock_punches') || !table_exists($db, 'users')) {
        return [];
    }
    if (!function_exists('column_exists') || !column_exists($db, 'users', 'driver_id')) {
        return [];
    }
    $start = $date . ' 00:00:00';
    $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $stmt = $db->prepare(
        'SELECT u.driver_id, MIN(p.clock_in_at) AS clock_in_at
         FROM time_clock_punches p
         INNER JOIN users u ON u.id = p.user_id
         WHERE u.driver_id IS NOT NULL AND u.driver_id > 0
           AND p.clock_in_at >= ? AND p.clock_in_at < ?
         GROUP BY u.driver_id'
    );
    $stmt->execute([$start, $end]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['driver_id']] = (string)$row['clock_in_at'];
    }
    return $map;
}

/**
 * @param array<string, mixed> $settings
 * @param array<int, int> $overrides
 * @param array<int, array{median:float,samples:int}> $serviceStats
 * @param array<string, array{median:float,samples:int}> $driveStats
 * @param list<array<string, mixed>> $rows ordered stops for one driver
 * @return array<string, mixed>
 */
function bakery_route_load_project_rows(
    array $settings,
    array $overrides,
    array $serviceStats,
    array $driveStats,
    array $rows,
    string $startTime
): array {
    $stops = [];
    $serviceSamples = 0;
    $driveSamples = 0;
    $previousId = 0;
    foreach ($rows as $row) {
        $customerId = (int)($row['customer_id'] ?? 0);
        $stat = $serviceStats[$customerId] ?? null;
        $override = array_key_exists($customerId, $overrides) ? (int)$overrides[$customerId] : null;
        $service = bakery_route_load_stored_service(
            is_array($stat) ? $stat : null,
            $override,
            (int)$settings['default_service_minutes'],
            (int)$settings['min_service_samples']
        );
        if ($service['source'] === 'history') {
            $serviceSamples += (int)$service['samples'];
        }
        $straight = null;
        $driveStat = null;
        if ($previousId > 0) {
            $driveStat = $driveStats[$previousId . ':' . $customerId] ?? null;
            $straight = bakery_route_load_haversine_minutes(
                isset($row['prev_lat']) && $row['prev_lat'] !== null && $row['prev_lat'] !== '' ? (float)$row['prev_lat'] : null,
                isset($row['prev_lng']) && $row['prev_lng'] !== null && $row['prev_lng'] !== '' ? (float)$row['prev_lng'] : null,
                isset($row['latitude']) && $row['latitude'] !== null && $row['latitude'] !== '' ? (float)$row['latitude'] : null,
                isset($row['longitude']) && $row['longitude'] !== null && $row['longitude'] !== '' ? (float)$row['longitude'] : null,
                (float)$settings['drive_speed_mph']
            );
        }
        $drive = $previousId > 0
            ? bakery_route_load_stored_drive(
                is_array($driveStat) ? $driveStat : null,
                (int)$settings['min_drive_samples'],
                $straight,
                (int)$settings['default_drive_minutes']
            )
            : ['minutes' => 0, 'source' => 'first', 'samples' => 0];
        if ($drive['source'] === 'history') {
            $driveSamples += (int)$drive['samples'];
        }
        $stops[] = [
            'customer_id' => $customerId,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'daily_order_id' => (int)($row['daily_order_id'] ?? 0),
            'service' => (int)$service['minutes'],
            'service_source' => (string)$service['source'],
            'service_samples' => (int)$service['samples'],
            'drive' => (int)$drive['minutes'],
            'drive_source' => (string)$drive['source'],
            'drive_samples' => (int)$drive['samples'],
        ];
        $previousId = $customerId;
    }
    $projected = bakery_route_load_project(
        $startTime,
        $stops,
        (string)$settings['finish_after'],
        (int)$settings['max_stops']
    );
    $projected['stops'] = $stops;
    $projected['service_samples'] = $serviceSamples;
    $projected['drive_samples'] = $driveSamples;
    $projected['start'] = $startTime;
    return $projected;
}

/**
 * @return array{drivers:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
 */
function bakery_route_load_report(PDO $db, string $date, int $onlyDriverId = 0): array
{
    $empty = ['drivers' => [], 'warnings' => []];
    if (!function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return $empty;
    }
    $settings = bakery_route_load_settings($db);
    $sql = 'SELECT doa.driver_id, d.name AS driver_name, doa.route_order, doa.delivery_status,
                   do.id AS daily_order_id, do.customer_id, c.name AS customer_name,
                   c.latitude, c.longitude
            FROM daily_order_assignments doa
            JOIN daily_orders do ON do.id = doa.daily_order_id
            JOIN customers c ON c.id = do.customer_id
            JOIN drivers d ON d.id = doa.driver_id
            WHERE doa.delivery_date = ? AND doa.delivery_status <> \'cancelled\'';
    $params = [$date];
    if ($onlyDriverId > 0) {
        $sql .= ' AND doa.driver_id = ?';
        $params[] = $onlyDriverId;
    }
    $sql .= ' ORDER BY d.name, doa.driver_id, doa.route_order, doa.id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $byDriver = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDriver[(int)$row['driver_id']]['name'] = (string)$row['driver_name'];
        $byDriver[(int)$row['driver_id']]['rows'][] = $row;
    }
    $overrides = bakery_route_load_overrides($db);
    $serviceStats = bakery_route_load_service_stat_map($db);
    $driveStats = bakery_route_load_drive_stat_map($db);
    $clockIns = bakery_route_load_clock_ins($db, $date);
    $drivers = [];
    $warnings = [];
    foreach ($byDriver as $driverId => $bundle) {
        $rows = $bundle['rows'];
        $previous = null;
        foreach ($rows as $index => $row) {
            $rows[$index]['prev_lat'] = $previous['latitude'] ?? null;
            $rows[$index]['prev_lng'] = $previous['longitude'] ?? null;
            $previous = $row;
        }
        $start = (string)$settings['route_start'];
        $clock = $clockIns[$driverId] ?? '';
        if (strlen($clock) >= 16) {
            $start = bakery_route_load_normalize_time(substr($clock, 11, 8), $start);
        }
        $projected = bakery_route_load_project_rows($settings, $overrides, $serviceStats, $driveStats, $rows, $start);
        $projected['driver_id'] = $driverId;
        $projected['driver_name'] = (string)$bundle['name'];
        $projected['line'] = bakery_route_load_warning_line(
            (string)$bundle['name'],
            (int)$projected['stop_count'],
            (string)$projected['finish_label']
        );
        $drivers[] = $projected;
        if (!empty($projected['warn'])) {
            $warnings[] = $projected;
        }
    }
    return ['drivers' => $drivers, 'warnings' => $warnings];
}

function bakery_route_load_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, mixed>|null $user
 */
function bakery_route_load_warning_html(PDO $db, string $date, ?array $user = null, int $onlyDriverId = 0): string
{
    if (!bakery_route_load_viewer_may_see_warning($user)) {
        return '';
    }
    $report = bakery_route_load_report($db, $date, $onlyDriverId);
    if ($report['warnings'] === []) {
        return '';
    }
    $html = '<div class="route-load-warning ops-alert ops-alert-warning" role="status" data-route-load-warning="1">';
    foreach ($report['warnings'] as $warning) {
        $html .= '<p class="route-load-warning-line">' . bakery_route_load_h((string)$warning['line']) . '</p>';
        $html .= '<p class="route-load-warning-samples">' . bakery_route_load_h(bakery_route_load_text(
            'route_load.samples',
            [
                'service' => (string)(int)($warning['service_samples'] ?? 0),
                'drive' => (string)(int)($warning['drive_samples'] ?? 0),
            ],
            'service samples :service, drive samples :drive'
        )) . '</p>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Manager controls for the limits, defaults, and one store override.
 *
 * @param list<array{id:int,name:string}> $stores
 */
function bakery_route_load_settings_panel_html(PDO $db, string $date, array $stores, ?array $user = null): string
{
    if (!bakery_route_load_viewer_may_see_warning($user) || !bakery_route_load_ready($db)) {
        return '';
    }
    $settings = bakery_route_load_settings($db);
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    $h = static function (string $value): string {
        return bakery_route_load_h($value);
    };
    $label = static function (string $key, string $fallback) use ($h): string {
        return $h(bakery_route_load_text($key, [], $fallback));
    };
    $html = '<details class="route-load-settings">';
    $html .= '<summary>' . $label('route_load.settings_title', 'Route overload settings') . '</summary>';
    $html .= '<form method="post" class="route-load-settings-form">';
    $html .= $csrf;
    $html .= '<input type="hidden" name="action" value="save_route_load_settings">';
    $html .= '<input type="hidden" name="date" value="' . $h($date) . '">';
    $fields = [
        ['finish_after', 'time', substr((string)$settings['finish_after'], 0, 5), 'route_load.finish_after', 'Warn if the finish is after'],
        ['route_start', 'time', substr((string)$settings['route_start'], 0, 5), 'route_load.route_start', 'Route start when nobody has clocked in'],
        ['max_stops', 'number', (string)$settings['max_stops'], 'route_load.max_stops', 'Warn above this many stops'],
        ['default_service_minutes', 'number', (string)$settings['default_service_minutes'], 'route_load.default_service', 'Default minutes at a store'],
        ['default_drive_minutes', 'number', (string)$settings['default_drive_minutes'], 'route_load.default_drive', 'Default minutes between stores'],
        ['min_drive_samples', 'number', (string)$settings['min_drive_samples'], 'route_load.min_drive_samples', 'Drive samples before history is used'],
        ['min_service_samples', 'number', (string)$settings['min_service_samples'], 'route_load.min_service_samples', 'Service samples before history is used'],
        ['drive_speed_mph', 'number', (string)$settings['drive_speed_mph'], 'route_load.speed', 'Straight-line speed (mph)'],
        ['outlier_trim_percent', 'number', (string)$settings['outlier_trim_percent'], 'route_load.trim', 'Trim outliers (percent)'],
        ['bulk_close_minutes', 'number', (string)$settings['bulk_close_minutes'], 'route_load.bulk', 'Ignore closes under (minutes)'],
        ['lookback_days', 'number', (string)$settings['lookback_days'], 'route_load.lookback', 'Days of history'],
    ];
    foreach ($fields as $field) {
        $step = $field[0] === 'drive_speed_mph' ? ' step="0.1"' : '';
        $html .= '<label>' . $label($field[3], $field[4]);
        $html .= '<input type="' . $field[1] . '" name="' . $field[0] . '" value="' . $h($field[2]) . '"' . $step . '></label>';
    }
    $html .= '<button type="submit">' . $label('route_load.save', 'Save') . '</button>';
    $html .= '</form>';
    if ($stores !== [] && function_exists('table_exists') && table_exists($db, 'route_service_overrides')) {
        $overrides = bakery_route_load_overrides($db);
        $html .= '<form method="post" class="route-load-override-form">';
        $html .= $csrf;
        $html .= '<input type="hidden" name="action" value="save_route_service_override">';
        $html .= '<input type="hidden" name="date" value="' . $h($date) . '">';
        $html .= '<label>' . $label('route_load.override_store', 'Store');
        $html .= '<select name="customer_id">';
        foreach ($stores as $store) {
            $id = (int)$store['id'];
            $current = $overrides[$id] ?? '';
            $suffix = $current === '' ? '' : ' (' . $current . ')';
            $html .= '<option value="' . $id . '">' . $h((string)$store['name'] . $suffix) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label>' . $label('route_load.override', 'Fixed minutes at this store');
        $html .= '<input type="number" name="minutes" min="0" max="180" value="0"></label>';
        $html .= '<button type="submit">' . $label('route_load.save', 'Save') . '</button>';
        $html .= '<p class="route-load-override-hint">' . $label('route_load.override_clear', 'Use 0 to clear the fixed minutes. A fixed value wins over history.') . '</p>';
        $html .= '</form>';
    }
    $html .= '</details>';
    return $html;
}

function bakery_route_load_estimate_label(int $minutes, string $source, int $samples): string
{
    $sourceLabel = bakery_route_load_text(
        'route_load.source_' . $source,
        [],
        $source
    );
    return bakery_route_load_text('route_load.estimate', [
        'minutes' => (string)$minutes,
        'source' => $sourceLabel,
        'samples' => (string)$samples,
    ], ':minutes min (:source, :samples)');
}

/**
 * Idempotent rebuild from photo timestamps and delivery closes.
 *
 * @return array{service:int,drive:int}
 */
function bakery_route_load_rebuild(PDO $db, ?int $lookbackDays = null): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'route_service_stats') || !table_exists($db, 'driver_photos')) {
        return ['service' => 0, 'drive' => 0];
    }
    $settings = bakery_route_load_settings($db);
    $days = $lookbackDays ?? (int)$settings['lookback_days'];
    $since = (new DateTimeImmutable('today'))->modify('-' . max(1, $days) . ' days')->format('Y-m-d');
    $collected = bakery_route_load_collect_samples($db, $since, (int)$settings['bulk_close_minutes']);
    $serviceRows = bakery_route_load_summarize($collected['service'], (int)$settings['outlier_trim_percent'], (int)$settings['bulk_close_minutes']);
    $driveRows = bakery_route_load_summarize($collected['drive'], (int)$settings['outlier_trim_percent'], (int)$settings['bulk_close_minutes']);
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM route_service_stats');
        $db->exec('DELETE FROM route_drive_stats');
        $serviceStmt = $db->prepare(
            'INSERT INTO route_service_stats (customer_id, median_minutes, sample_count) VALUES (?, ?, ?)'
        );
        foreach ($serviceRows as $customerId => $row) {
            $serviceStmt->execute([(int)$customerId, $row['median'], $row['samples']]);
        }
        $driveStmt = $db->prepare(
            'INSERT INTO route_drive_stats (from_customer_id, to_customer_id, median_minutes, sample_count) VALUES (?, ?, ?, ?)'
        );
        foreach ($driveRows as $key => $row) {
            $parts = explode(':', (string)$key);
            if (count($parts) !== 2) {
                continue;
            }
            $driveStmt->execute([(int)$parts[0], (int)$parts[1], $row['median'], $row['samples']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return ['service' => count($serviceRows), 'drive' => count($driveRows)];
}

/**
 * Refresh one delivered stop without waiting for the nightly rebuild.
 */
function bakery_route_load_refresh_order(PDO $db, int $dailyOrderId): void
{
    if ($dailyOrderId <= 0 || $db->inTransaction()) {
        return;
    }
    if (!function_exists('table_exists') || !table_exists($db, 'route_service_stats')) {
        return;
    }
    $stmt = $db->prepare(
        'SELECT do.customer_id, doa.driver_id, doa.delivery_date
         FROM daily_orders do
         JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
         WHERE do.id = ?
         ORDER BY doa.id DESC
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }
    $settings = bakery_route_load_settings($db);
    $since = (new DateTimeImmutable('today'))->modify('-' . (int)$settings['lookback_days'] . ' days')->format('Y-m-d');
    $collected = bakery_route_load_collect_samples($db, $since, (int)$settings['bulk_close_minutes']);
    $customerId = (int)$row['customer_id'];
    $service = bakery_route_load_summarize(
        [$customerId => $collected['service'][$customerId] ?? []],
        (int)$settings['outlier_trim_percent'],
        (int)$settings['bulk_close_minutes']
    );
    $db->prepare('DELETE FROM route_service_stats WHERE customer_id = ?')->execute([$customerId]);
    if (isset($service[$customerId])) {
        $db->prepare(
            'INSERT INTO route_service_stats (customer_id, median_minutes, sample_count) VALUES (?, ?, ?)'
        )->execute([$customerId, $service[$customerId]['median'], $service[$customerId]['samples']]);
    }
    $driverId = (int)$row['driver_id'];
    $date = (string)$row['delivery_date'];
    $pairs = [];
    $prefix = $driverId . '|' . $date . '|';
    foreach ($collected['drive_day'] as $key => $samples) {
        $key = (string)$key;
        if (strpos($key, $prefix) !== 0) {
            continue;
        }
        $pair = substr($key, strlen($prefix));
        if (isset($collected['drive'][$pair])) {
            $pairs[$pair] = $collected['drive'][$pair];
        }
    }
    $summarized = bakery_route_load_summarize($pairs, (int)$settings['outlier_trim_percent'], (int)$settings['bulk_close_minutes']);
    $upsert = $db->prepare(
        'INSERT INTO route_drive_stats (from_customer_id, to_customer_id, median_minutes, sample_count)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE median_minutes = VALUES(median_minutes), sample_count = VALUES(sample_count)'
    );
    foreach ($summarized as $key => $stat) {
        $parts = explode(':', (string)$key);
        if (count($parts) !== 2) {
            continue;
        }
        $upsert->execute([(int)$parts[0], (int)$parts[1], $stat['median'], $stat['samples']]);
    }
}

/**
 * @param array<int|string, list<float>> $groups
 * @return array<int|string, array{median:float,samples:int}>
 */
function bakery_route_load_summarize(array $groups, int $trimPercent, int $bulkUnder): array
{
    $out = [];
    foreach ($groups as $key => $samples) {
        $trimmed = bakery_route_load_service_samples($samples, $bulkUnder, $trimPercent);
        if ($trimmed === []) {
            continue;
        }
        $median = bakery_route_load_median($trimmed);
        if ($median === null) {
            continue;
        }
        $out[$key] = ['median' => round($median, 2), 'samples' => count($trimmed)];
    }
    return $out;
}

/**
 * @return array{service:array<int,list<float>>,drive:array<string,list<float>>,drive_day:array<string,list<float>>}
 */
function bakery_route_load_collect_samples(PDO $db, string $since, int $bulkUnder): array
{
    $service = [];
    $drive = [];
    $driveDay = [];
    $photos = [];
    if (table_exists($db, 'driver_photos')) {
        $stmt = $db->prepare(
            'SELECT driver_id, customer_id, delivery_date, photo_type, created_at
             FROM driver_photos
             WHERE delivery_date >= ? AND customer_id IS NOT NULL
             ORDER BY driver_id, delivery_date, created_at, id'
        );
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (int)$row['driver_id'] . '|' . (string)$row['delivery_date'] . '|' . (int)$row['customer_id'];
            $photos[$key][] = $row;
        }
    }
    $closes = [];
    if (table_exists($db, 'daily_order_assignments') && table_exists($db, 'daily_orders')) {
        $confirmed = function_exists('column_exists') && column_exists($db, 'daily_orders', 'delivery_confirmed_at')
            ? 'do.delivery_confirmed_at'
            : 'NULL AS delivery_confirmed_at';
        $stmt = $db->prepare(
            "SELECT do.customer_id, doa.driver_id, doa.delivery_date, doa.delivery_status,
                    doa.actual_delivery_time, {$confirmed}
             FROM daily_order_assignments doa
             JOIN daily_orders do ON do.id = doa.daily_order_id
             WHERE doa.delivery_date >= ?"
        );
        $stmt->execute([$since]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (int)$row['driver_id'] . '|' . (string)$row['delivery_date'] . '|' . (int)$row['customer_id'];
            $closes[$key] = $row;
        }
    }
    $byRoute = [];
    $keys = array_unique(array_merge(array_keys($photos), array_keys($closes)));
    foreach ($keys as $key) {
        $parts = explode('|', (string)$key);
        if (count($parts) !== 3) {
            continue;
        }
        $visit = bakery_route_load_visit_times($photos[$key] ?? [], $closes[$key] ?? null);
        if ($visit['arrival'] === null && $visit['close'] === null) {
            continue;
        }
        $routeKey = $parts[0] . '|' . $parts[1];
        $byRoute[$routeKey][] = [
            'customer_id' => (int)$parts[2],
            'arrival' => $visit['arrival'],
            'close' => $visit['close'],
            'service' => $visit['service'],
        ];
        if ($visit['service'] !== null) {
            $service[(int)$parts[2]][] = (float)$visit['service'];
        }
    }
    foreach ($byRoute as $routeKey => $visits) {
        usort($visits, static function (array $a, array $b): int {
            $ta = strtotime((string)($a['arrival'] ?? $a['close'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['arrival'] ?? $b['close'] ?? '')) ?: 0;
            return $ta <=> $tb;
        });
        $previous = null;
        foreach ($visits as $visit) {
            if ($previous !== null && $previous['close'] !== null && $visit['arrival'] !== null) {
                $gap = bakery_route_load_minutes_between($previous['close'], $visit['arrival']);
                if ($gap !== null && $gap >= $bulkUnder) {
                    $pair = (int)$previous['customer_id'] . ':' . (int)$visit['customer_id'];
                    $drive[$pair][] = (float)$gap;
                    $driveDay[$routeKey . '|' . $pair][] = (float)$gap;
                }
            }
            $previous = $visit;
        }
    }
    return ['service' => $service, 'drive' => $drive, 'drive_day' => $driveDay];
}

/**
 * @param list<array<string, mixed>> $photos
 * @param array<string, mixed>|null $closeRow
 * @return array{arrival:?string,close:?string,service:?int}
 */
function bakery_route_load_visit_times(array $photos, ?array $closeRow): array
{
    $arrival = null;
    $photoClose = null;
    foreach ($photos as $photo) {
        $at = trim((string)($photo['created_at'] ?? ''));
        if ($at === '') {
            continue;
        }
        $type = (string)($photo['photo_type'] ?? '');
        if ($type === 'Before') {
            if ($arrival === null || (strtotime($at) ?: 0) < (strtotime($arrival) ?: 0)) {
                $arrival = $at;
            }
        } elseif ($type === 'After' || $type === 'Receipt') {
            if ($photoClose === null || (strtotime($at) ?: 0) < (strtotime($photoClose) ?: 0)) {
                $photoClose = $at;
            }
        }
    }
    $close = $photoClose;
    if ($close === null && is_array($closeRow)) {
        $confirmed = trim((string)($closeRow['delivery_confirmed_at'] ?? ''));
        if ($confirmed !== '') {
            $close = $confirmed;
        } elseif ((string)($closeRow['delivery_status'] ?? '') === 'delivered') {
            $clock = trim((string)($closeRow['actual_delivery_time'] ?? ''));
            $date = (string)($closeRow['delivery_date'] ?? '');
            if ($clock !== '' && $date !== '') {
                $close = $date . ' ' . $clock;
            }
        }
    }
    $service = bakery_route_load_minutes_between($arrival, $close);
    return ['arrival' => $arrival, 'close' => $close, 'service' => $service];
}
