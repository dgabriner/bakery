<?php
/**
 * Overnight cron run stamps — operational_events + storage/cron/<name>.json.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_cron_storage_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/cron';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * @param array<string,mixed> $meta
 * @return array{last_run_at:string,outcome:string,name:string,age_hours:?float}
 */
function bakery_cron_record_run(?PDO $db, string $name, string $outcome, array $meta = []): array
{
    $name = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower(trim($name))) ?: 'cron';
    $outcome = strtolower(trim($outcome));
    if ($outcome === '') {
        $outcome = 'unknown';
    }
    $now = date('c');
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
        @file_put_contents($tmp, $json . "\n", LOCK_EX);
        @rename($tmp, $path);
    }
    if ($db instanceof PDO && function_exists('bakery_record_operational_event')) {
        bakery_record_operational_event($db, 'cron_run', 'Cron ' . $name . ' ' . $outcome, [
            'operational_date' => date('Y-m-d'),
            'actor_role' => 'cron',
            'metadata' => array_merge([
                'name' => $name,
                'outcome' => $outcome,
                'last_run_at' => $now,
            ], $meta),
        ]);
    }
    $payload['age_hours'] = 0.0;
    return $payload;
}

/** @return array<string,mixed>|null */
function bakery_cron_last_run(string $name): ?array
{
    $name = preg_replace('/[^a-z0-9_\-]+/i', '_', strtolower(trim($name))) ?: 'cron';
    $path = bakery_cron_storage_dir() . '/' . $name . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = json_decode((string)file_get_contents($path), true);
    return is_array($raw) ? $raw : null;
}

function bakery_cron_age_hours(string $name): ?float
{
    $row = bakery_cron_last_run($name);
    if (!$row) {
        return null;
    }
    $ts = strtotime((string)($row['last_run_at'] ?? ''));
    if ($ts === false) {
        return null;
    }
    return round(max(0, (time() - $ts) / 3600), 2);
}

function bakery_cron_is_stale(string $name, float $maxAgeHours = 26.0): bool
{
    $age = bakery_cron_age_hours($name);
    return $age === null || $age > $maxAgeHours;
}
