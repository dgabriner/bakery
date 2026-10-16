<?php
/**
 * Open the next sell day's dual survey (lock stores + set order) for HQ and
 * each active driver. CLI cron only. Page views must keep using the existing
 * lookup helpers and must not call these functions.
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
