<?php
/**
 * Overnight cron run stamps.
 *
 * The database row in operational_events is the heartbeat the morning board
 * reads. storage/cron/<name>.json is a same-host copy. A missing file is not
 * a missed run when the database stamp or tomorrow's dated orders exist.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_timeline.php';

function bakery_cron_timezone(): DateTimeZone
{
    return new DateTimeZone('America/Los_Angeles');
}

function bakery_cron_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', bakery_cron_timezone());
}

function bakery_cron_format_pt(?string $iso): string
{
    $raw = trim((string)$iso);
    if ($raw === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))
            ->setTimezone(bakery_cron_timezone())
            ->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return '';
    }
}

function bakery_cron_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cron';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log('cron heartbeat directory not writable: ' . $dir);
    }
    return $dir;
}

function bakery_cron_normalize_name(string $name): string
{
    $clean = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower(trim($name)));
    return $clean !== null && $clean !== '' ? $clean : 'cron';
}

/**
 * @param array<string,mixed> $meta
 * @return array{last_run_at:string,outcome:string,name:string,age_hours:?float,meta:array<string,mixed>}
 */
function bakery_cron_record_run(?PDO $db, string $name, string $outcome, array $meta = [], ?DateTimeImmutable $at = null): array
{
    $name = bakery_cron_normalize_name($name);
    $outcome = strtolower(trim($outcome));
    if ($outcome === '') {
        $outcome = 'unknown';
    }
    $stamp = ($at ?? bakery_cron_now())->setTimezone(bakery_cron_timezone());
    $now = $stamp->format('c');
    $payload = [
        'name' => $name,
        'outcome' => $outcome,
        'last_run_at' => $now,
        'meta' => $meta,
    ];
    $path = bakery_cron_storage_dir() . '/' . $name . '.json';
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
        $tmp = $path . '.tmp';
        $written = @file_put_contents($tmp, $json . "\n", LOCK_EX);
        if ($written === false || !@rename($tmp, $path)) {
            error_log('cron heartbeat file not written: ' . $path);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }
    if ($db instanceof PDO && function_exists('bakery_record_operational_event')) {
        $eventId = bakery_record_operational_event($db, 'cron_run', 'Cron ' . $name . ' ' . $outcome, [
            'operational_date' => $stamp->format('Y-m-d'),
            'actor_role' => 'cron',
            'metadata' => array_merge([
                'name' => $name,
                'outcome' => $outcome,
                'last_run_at' => $now,
            ], $meta),
        ]);
        if ($eventId === null) {
            error_log('cron heartbeat was not stored in operational_events for ' . $name);
        }
    }
    $payload['age_hours'] = 0.0;
    return $payload;
}

/**
 * @return array<string,mixed>|null
 */
function bakery_cron_read_file(string $name): ?array
{
    $name = bakery_cron_normalize_name($name);
    $path = bakery_cron_storage_dir() . '/' . $name . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? $raw : null;
}

/**
 * @return array<string,mixed>|null
 */
function bakery_cron_last_run_from_db(PDO $db, string $name, ?string $outcome = null): ?array
{
    $name = bakery_cron_normalize_name($name);
    if (!function_exists('bakery_operational_events_ready') || !bakery_operational_events_ready($db)) {
        return null;
    }
    if ($outcome !== null && $outcome !== '') {
        $stmt = $db->prepare(
            "SELECT summary, metadata, occurred_at
             FROM operational_events
             WHERE event_type = 'cron_run' AND summary = ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['Cron ' . $name . ' ' . strtolower($outcome)]);
    } else {
        $stmt = $db->prepare(
            "SELECT summary, metadata, occurred_at
             FROM operational_events
             WHERE event_type = 'cron_run' AND summary LIKE ?
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['Cron ' . $name . ' %']);
    }
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $metaRaw = $row['metadata'] ?? null;
    if (is_string($metaRaw)) {
        $meta = json_decode($metaRaw, true);
    } elseif (is_array($metaRaw)) {
        $meta = $metaRaw;
    } else {
        $meta = [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $last = trim((string)($meta['last_run_at'] ?? ''));
    if ($last === '' && !empty($row['occurred_at'])) {
        try {
            $last = (new DateTimeImmutable((string)$row['occurred_at'], bakery_cron_timezone()))->format('c');
        } catch (Throwable $e) {
            $last = '';
        }
    }
    $foundOutcome = strtolower(trim((string)($meta['outcome'] ?? '')));
    if ($foundOutcome === '' && preg_match('/^Cron\s+\S+\s+(\S+)/', (string)$row['summary'], $match)) {
        $foundOutcome = strtolower($match[1]);
    }
    return [
        'name' => $name,
        'outcome' => $foundOutcome,
        'last_run_at' => $last,
        'meta' => $meta,
        'source' => 'database',
    ];
}

/**
 * @param array<string,mixed>|null $left
 * @param array<string,mixed>|null $right
 * @return array<string,mixed>|null
 */
function bakery_cron_pick_newer(?array $left, ?array $right): ?array
{
    if ($left === null) {
        return $right;
    }
    if ($right === null) {
        return $left;
    }
    $leftTs = strtotime((string)($left['last_run_at'] ?? '')) ?: 0;
    $rightTs = strtotime((string)($right['last_run_at'] ?? '')) ?: 0;
    return $rightTs > $leftTs ? $right : $left;
}

/** @return array<string,mixed>|null */
function bakery_cron_last_run(string $name, ?PDO $db = null): ?array
{
    $name = bakery_cron_normalize_name($name);
    $file = bakery_cron_read_file($name);
    $dbRow = $db instanceof PDO ? bakery_cron_last_run_from_db($db, $name) : null;
    return bakery_cron_pick_newer($file, $dbRow);
}

/** @return array<string,mixed>|null */
function bakery_cron_last_successful_run(string $name, ?PDO $db = null): ?array
{
    $name = bakery_cron_normalize_name($name);
    $file = bakery_cron_read_file($name);
    $fileOk = (is_array($file) && strtolower((string)($file['outcome'] ?? '')) === 'ok') ? $file : null;
    $dbOk = $db instanceof PDO ? bakery_cron_last_run_from_db($db, $name, 'ok') : null;
    return bakery_cron_pick_newer($fileOk, $dbOk);
}

function bakery_cron_age_hours(string $name, ?PDO $db = null): ?float
{
    $row = bakery_cron_last_run($name, $db);
    if (!$row) {
        return null;
    }
    $ts = strtotime((string)($row['last_run_at'] ?? ''));
    if ($ts === false) {
        return null;
    }
    return round(max(0, (time() - $ts) / 3600), 2);
}

function bakery_cron_is_stale(string $name, float $maxAgeHours = 26.0, ?PDO $db = null): bool
{
    $age = bakery_cron_age_hours($name, $db);
    return $age === null || $age > $maxAgeHours;
}

/**
 * 2:30 AM PT is the scheduled slot. It is due once 3:30 AM PT has passed,
 * so a run that is still in progress is not called missed.
 */
function bakery_demand_scheduler_required_slot(DateTimeImmutable $now): DateTimeImmutable
{
    $pt = $now->setTimezone(bakery_cron_timezone());
    $slot = $pt->setTime(2, 30, 0);
    $deadline = $pt->setTime(3, 30, 0);
    if ($pt < $deadline) {
        $slot = $slot->modify('-1 day');
    }
    return $slot;
}

function bakery_demand_scheduler_dated_order_count(PDO $db, string $date): int
{
    if (!function_exists('table_exists') || !table_exists($db, 'daily_orders')) {
        return 0;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE order_date = ?');
    $stmt->execute([$date]);
    return (int)$stmt->fetchColumn();
}

/**
 * Morning-board status for the overnight demand scheduler.
 *
 * Warn only when the last successful run is older than the slot that is
 * already due, and tomorrow has no dated orders.
 *
 * @return array{
 *   last_run_at:?string,
 *   last_run_pt:string,
 *   outcome:?string,
 *   missed:bool,
 *   warn:bool,
 *   tomorrow:string,
 *   tomorrow_order_count:int,
 *   required_slot:string
 * }
 */
function bakery_demand_scheduler_board_status(PDO $db, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? bakery_cron_now())->setTimezone(bakery_cron_timezone());
    $required = bakery_demand_scheduler_required_slot($now);
    $tomorrow = $now->modify('+1 day')->format('Y-m-d');
    $latest = bakery_cron_last_run('demand_scheduler', $db);
    $success = bakery_cron_last_successful_run('demand_scheduler', $db);
    $successAt = null;
    if (is_array($success) && trim((string)($success['last_run_at'] ?? '')) !== '') {
        try {
            $successAt = new DateTimeImmutable((string)$success['last_run_at']);
        } catch (Throwable $e) {
            $successAt = null;
        }
    }
    $ranSinceSlot = $successAt instanceof DateTimeImmutable && $successAt >= $required;
    $tomorrowCount = bakery_demand_scheduler_dated_order_count($db, $tomorrow);
    $lastIso = is_array($latest) ? trim((string)($latest['last_run_at'] ?? '')) : '';
    return [
        'last_run_at' => $lastIso !== '' ? $lastIso : null,
        'last_run_pt' => bakery_cron_format_pt($lastIso),
        'outcome' => is_array($latest) ? (string)($latest['outcome'] ?? '') : null,
        'missed' => !$ranSinceSlot,
        'warn' => !$ranSinceSlot && $tomorrowCount === 0,
        'tomorrow' => $tomorrow,
        'tomorrow_order_count' => $tomorrowCount,
        'required_slot' => $required->format('c'),
    ];
}

function bakery_demand_scheduler_status_line(array $status): string
{
    $when = trim((string)($status['last_run_pt'] ?? ''));
    if ($when !== '') {
        return function_exists('bakery_t')
            ? bakery_t('dashboard.cron_overnight_last_run', ['when' => $when])
            : ('Overnight generation last ran ' . $when . '.');
    }
    $key = !empty($status['warn'])
        ? 'dashboard.cron_overnight_never'
        : 'dashboard.cron_overnight_covered';
    $fallback = !empty($status['warn'])
        ? 'Overnight generation has not recorded a run.'
        : 'Overnight generation has not recorded a run. Tomorrow already has dated orders.';
    if (!function_exists('bakery_t')) {
        return $fallback;
    }
    $text = bakery_t($key);
    return $text === $key ? $fallback : $text;
}

function bakery_render_demand_scheduler_status(array $status): void
{
    $class = 'ops-cron-status' . (!empty($status['warn']) ? ' is-missed' : '');
    echo '<p class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" data-cron="demand_scheduler">'
        . htmlspecialchars(bakery_demand_scheduler_status_line($status), ENT_QUOTES, 'UTF-8')
        . '</p>';
}
