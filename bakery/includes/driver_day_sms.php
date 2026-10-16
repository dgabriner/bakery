<?php
/**
 * Spanish clipboard text for one driver's dated stops.
 * Reads existing survey links. Does not send, and does not mint surveys.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/i18n.php';

/**
 * @return array<string, string>
 */
function bakery_driver_sms_catalog(): array
{
    static $catalog = null;
    if ($catalog === null) {
        $catalog = bakery_load_lang_catalog('es');
    }
    return $catalog;
}

function bakery_driver_sms_phrase(string $key, array $params = []): string
{
    $text = (string)(bakery_driver_sms_catalog()[$key] ?? '');
    foreach ($params as $name => $value) {
        $text = str_replace(':' . $name, (string)$value, $text);
    }
    return $text;
}

function bakery_driver_sms_date_label(string $ymd): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    if (!$dt || $dt->format('Y-m-d') !== $ymd) {
        return $ymd;
    }
    $keys = [
        1 => 'day.monday',
        2 => 'day.tuesday',
        3 => 'day.wednesday',
        4 => 'day.thursday',
        5 => 'day.friday',
        6 => 'day.saturday',
        7 => 'day.sunday',
    ];
    $iso = (int)$dt->format('N');
    $catalog = bakery_driver_sms_catalog();
    $weekday = trim((string)($catalog[$keys[$iso]] ?? ''));
    if (function_exists('mb_strtolower')) {
        $weekday = mb_strtolower($weekday, 'UTF-8');
    } else {
        $weekday = strtolower($weekday);
    }
    $months = [
        1 => 'enero',
        2 => 'febrero',
        3 => 'marzo',
        4 => 'abril',
        5 => 'mayo',
        6 => 'junio',
        7 => 'julio',
        8 => 'agosto',
        9 => 'septiembre',
        10 => 'octubre',
        11 => 'noviembre',
        12 => 'diciembre',
    ];
    $month = $months[(int)$dt->format('n')] ?? '';
    return trim($weekday . ' ' . $dt->format('j') . ' de ' . $month);
}

/**
 * @param array{driver_name?:string,date?:string,stops?:list<string>,ajustes_url?:string} $input
 */
function bakery_driver_day_sms_body(array $input): string
{
    $name = trim((string)($input['driver_name'] ?? ''));
    if ($name === '') {
        $name = 'conductor';
    }
    $date = trim((string)($input['date'] ?? ''));
    $dated = bakery_driver_sms_date_label($date);
    $lines = [
        bakery_driver_sms_phrase('driver_sms.greeting', ['name' => $name]),
        bakery_driver_sms_phrase('driver_sms.stops_heading', ['date' => $dated]),
    ];
    $stops = isset($input['stops']) && is_array($input['stops']) ? $input['stops'] : [];
    $numbered = [];
    foreach ($stops as $stop) {
        $store = trim((string)$stop);
        if ($store === '') {
            continue;
        }
        $numbered[] = $store;
    }
    if ($numbered === []) {
        $lines[] = bakery_driver_sms_phrase('driver_sms.no_stops');
    } else {
        foreach ($numbered as $index => $store) {
            $lines[] = bakery_driver_sms_phrase('driver_sms.stop_line', [
                'n' => (string)($index + 1),
                'date' => $dated,
                'store' => $store,
            ]);
        }
    }
    $url = trim((string)($input['ajustes_url'] ?? ''));
    if ($url !== '') {
        $lines[] = bakery_driver_sms_phrase('driver_sms.ajustes', ['url' => $url]);
    } else {
        $lines[] = bakery_driver_sms_phrase('driver_sms.ajustes_missing');
    }
    $lines[] = bakery_driver_sms_phrase('driver_sms.skip_add');
    return implode("\n", $lines);
}

/**
 * One clipboard block per driver who has a dated stop. Looks up survey links.
 *
 * @return list<array{driver_id:int,driver_name:string,body:string}>
 */
function bakery_driver_day_sms_blocks(PDO $db, string $date): array
{
    if (!function_exists('route_manager_fetch_deliveries')) {
        require_once __DIR__ . '/route_manager.php';
    }
    if (!function_exists('bakery_survey_dual_hub_existing')) {
        require_once __DIR__ . '/surveys.php';
    }
    $date = bakery_survey_validate_ymd($date);
    $blocks = [];
    foreach (route_manager_fetch_deliveries($db, $date) as $driver) {
        if (!is_array($driver)) {
            continue;
        }
        $driverId = (int)($driver['id'] ?? 0);
        if ($driverId <= 0) {
            continue;
        }
        $stops = [];
        foreach ($driver['deliveries'] ?? [] as $delivery) {
            if (!is_array($delivery)) {
                continue;
            }
            if ((string)($delivery['delivery_status'] ?? '') === 'cancelled') {
                continue;
            }
            $store = trim((string)($delivery['customer_name'] ?? ''));
            if ($store !== '') {
                $stops[] = $store;
            }
        }
        if ($stops === []) {
            continue;
        }
        $url = '';
        if (function_exists('bakery_surveys_ready') && bakery_surveys_ready($db)) {
            $hub = bakery_survey_dual_hub_existing($db, $driverId, $date);
            $url = trim((string)($hub['verify_url'] ?? ''));
        }
        $blocks[] = [
            'driver_id' => $driverId,
            'driver_name' => (string)($driver['name'] ?? ''),
            'body' => bakery_driver_day_sms_body([
                'driver_name' => (string)($driver['name'] ?? ''),
                'date' => $date,
                'stops' => $stops,
                'ajustes_url' => $url,
            ]),
        ];
    }
    return $blocks;
}
