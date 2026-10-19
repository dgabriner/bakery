<?php
/**
 * Open the next sell day's dual survey (lock stores + set order) for HQ and
 * each active driver.
 *
 * bakery_survey_dual_open_for_date and bakery_survey_dual_open_next always
 * write. Pages must not call them. A GET that always inserts was the bug.
 * The 5 PM cron also missed 2026-10-14 and 2026-10-15: the script was not
 * in the tree yet, and the crontab line was still a YOUR_USER placeholder
 * with no heartbeat.
 *
 * bakery_survey_dual_open_if_due is the only function a page may call.
 * After 5 PM Pacific it opens the next sell day once, when the HQ surveys
 * are still missing. Before 5 PM it does not write. A second call does not
 * insert another row. The HQ pair is the lock, so the first call still
 * opens every driver through bakery_survey_dual_open_for_date.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/surveys.php';
require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/cron_run.php';

/**
 * @return array{delivery_date:string,opened:list<array{driver_id:int,verify_token:string,order_token:string}>}
 */
function bakery_survey_dual_open_for_date(PDO $db, string $deliveryDate, int $createdBy = 0): array
{
    if (!bakery_surveys_ready($db)) {
        throw new RuntimeException('Survey tables are not ready');
    }
    $deliveryDate = bakery_survey_validate_ymd($deliveryDate);
    $driverIds = [0];
    if (function_exists('bakery_get_drivers')) {
        foreach (bakery_get_drivers($db) as $driver) {
            $driverId = (int)($driver['id'] ?? 0);
            if ($driverId > 0) {
                $driverIds[] = $driverId;
            }
        }
    }
    $opened = [];
    foreach ($driverIds as $driverId) {
        $hub = bakery_survey_dual_hub_links($db, $driverId, $deliveryDate, $createdBy);
        $opened[] = [
            'driver_id' => $driverId,
            'verify_token' => (string)($hub['verify_token'] ?? ''),
            'order_token' => (string)($hub['order_token'] ?? ''),
        ];
    }
    return [
        'delivery_date' => $deliveryDate,
        'opened' => $opened,
    ];
}

/**
 * @return array{today:string,delivery_date:string,opened:list<array{driver_id:int,verify_token:string,order_token:string}>}
 */
function bakery_survey_dual_open_next(PDO $db, ?DateTimeImmutable $now = null, int $createdBy = 0): array
{
    $now = ($now ?? bakery_cron_now())->setTimezone(bakery_cron_timezone());
    $today = $now->format('Y-m-d');
    $next = bakery_survey_next_sell_date_from_db($db, $today);
    $opened = bakery_survey_dual_open_for_date($db, $next, $createdBy);
    $opened['today'] = $today;
    return $opened;
}

/**
 * Page-load fallback for the 5 PM Pacific cron.
 * Writes once, and only after 5 PM, when tomorrow's HQ surveys are missing.
 *
 * @return array{reason:string,wrote:bool,today:string,delivery_date:string}
 */
function bakery_survey_dual_open_if_due(PDO $db, ?DateTimeImmutable $now = null, int $createdBy = 0): array
{
    $now = ($now ?? bakery_cron_now())->setTimezone(bakery_cron_timezone());
    $today = $now->format('Y-m-d');
    $minutes = ((int)$now->format('G')) * 60 + (int)$now->format('i');
    if ($minutes < (17 * 60)) {
        return [
            'reason' => 'before_5pm',
            'wrote' => false,
            'today' => $today,
            'delivery_date' => '',
        ];
    }
    $next = bakery_survey_next_sell_date_from_db($db, $today);
    $verify = bakery_survey_find_store_verify($db, 0, $next);
    $order = bakery_survey_find_route_order($db, 0, $next);
    if ($verify !== [] && $order !== []) {
        return [
            'reason' => 'already_open',
            'wrote' => false,
            'today' => $today,
            'delivery_date' => $next,
        ];
    }
    $opened = bakery_survey_dual_open_for_date($db, $next, $createdBy);
    $opened['today'] = $today;
    $opened['reason'] = 'opened';
    $opened['wrote'] = true;
    return $opened;
}
