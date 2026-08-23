<?php
/**
 * Bake-day cover windows — which production run feeds which delivery dates.
 *
 * Plans, inventory, and Daily Production stay keyed on the delivery date.
 * This helper only names the bakery's real bake calendar so Production Center
 * can show the cover window instead of treating every delivery day as its own bake.
 *
 * Daily family (Pan Dulce and other non–Sour Flour lines): produce Mon–Fri.
 * Friday's bake covers Saturday (including Markets), Sunday, and Monday.
 * Monday's bake is for Tuesday. Sunday deliveries are usually none; Sunday
 * production is minimal (not a typical pan dulce bake day).
 *
 * Sour Flour is a separate line: Tuesday and Friday for the following days'
 * deliveries, plus a Sunday run for Monday.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

const BAKERY_PRODUCTION_CADENCE_DAILY = 'daily';
const BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR = 'sour_flour';

/**
 * Bake weekday (1=Mon … 7=Sun) → delivery weekdays that run covers.
 *
 * @return array<string, array<int, list<int>>>
 */
function bakery_production_cadence_cover_map(): array
{
    return [
        BAKERY_PRODUCTION_CADENCE_DAILY => [
            1 => [2],
            2 => [3],
            3 => [4],
            4 => [5],
            5 => [6, 7, 1],
        ],
        BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR => [
            // Following days after Tuesday's run, until Friday's Sour Flour bake.
            2 => [3, 4, 5],
            // Following days after Friday's run. Monday is the Sunday special.
            5 => [6, 7],
            7 => [1],
        ],
    ];
}

function bakery_production_cadence_family(?string $productLineName): string
{
    $name = strtolower(trim((string)$productLineName));
    if ($name === 'sour flour' || strpos($name, 'sour flour') === 0) {
        return BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR;
    }
    return BAKERY_PRODUCTION_CADENCE_DAILY;
}

function bakery_production_cadence_parse_date(string $date): ?DateTime
{
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return null;
    }
    return $dt;
}

function bakery_production_cadence_weekday(string $date): int
{
    $dt = bakery_production_cadence_parse_date($date);
    if ($dt instanceof DateTime) {
        return (int)$dt->format('N');
    }
    if (function_exists('bakery_standing_day_from_date')) {
        return (int)bakery_standing_day_from_date($date);
    }
    return (int)date('N', strtotime($date));
}

/**
 * @return list<int>
 */
function bakery_production_cadence_cover_weekdays(string $family, int $bakeWeekday): array
{
    $bakeWeekday = $bakeWeekday === 0 ? 7 : $bakeWeekday;
    $map = bakery_production_cadence_cover_map();
    return $map[$family][$bakeWeekday] ?? [];
}

/**
 * Typical production weekdays for a family (not "we never bake", just the usual run).
 *
 * @return list<int>
 */
function bakery_production_cadence_typical_bake_weekdays(string $family): array
{
    return array_map('intval', array_keys(bakery_production_cadence_cover_map()[$family] ?? []));
}

function bakery_production_cadence_is_typical_bake_weekday(string $family, int $weekday): bool
{
    $weekday = $weekday === 0 ? 7 : $weekday;
    return in_array($weekday, bakery_production_cadence_typical_bake_weekdays($family), true);
}

/**
 * Bake weekday that produces a delivery weekday, or null when this family
 * has no dedicated run for that delivery day (e.g. Sour Flour on Tuesday).
 */
function bakery_production_cadence_bake_weekday_for_delivery(string $family, int $deliveryWeekday): ?int
{
    $deliveryWeekday = $deliveryWeekday === 0 ? 7 : $deliveryWeekday;
    foreach (bakery_production_cadence_cover_map()[$family] ?? [] as $bakeWeekday => $cover) {
        if (in_array($deliveryWeekday, $cover, true)) {
            return (int)$bakeWeekday;
        }
    }
    return null;
}

/**
 * Delivery dates covered by baking on $bakeDate, wrapping into the next week
 * when Friday covers Monday.
 *
 * @return list<string>
 */
function bakery_production_cadence_cover_dates(string $family, string $bakeDate): array
{
    $bake = bakery_production_cadence_parse_date($bakeDate);
    if (!$bake) {
        return [];
    }
    $bakeWeekday = (int)$bake->format('N');
    $dates = [];
    foreach (bakery_production_cadence_cover_weekdays($family, $bakeWeekday) as $coverWeekday) {
        $delta = (int)$coverWeekday - $bakeWeekday;
        if ($delta <= 0) {
            $delta += 7;
        }
        $day = clone $bake;
        $day->modify('+' . $delta . ' days');
        $dates[] = $day->format('Y-m-d');
    }
    sort($dates);
    return $dates;
}

/**
 * Calendar date of the bake that feeds this delivery date for a family.
 */
function bakery_production_cadence_bake_date_for_delivery(string $family, string $deliveryDate): ?string
{
    $delivery = bakery_production_cadence_parse_date($deliveryDate);
    if (!$delivery) {
        return null;
    }
    $deliveryWeekday = (int)$delivery->format('N');
    $bakeWeekday = bakery_production_cadence_bake_weekday_for_delivery($family, $deliveryWeekday);
    if ($bakeWeekday === null) {
        return null;
    }
    $delta = $deliveryWeekday - $bakeWeekday;
    if ($delta <= 0) {
        $delta += 7;
    }
    $bake = clone $delivery;
    $bake->modify('-' . $delta . ' days');
    return $bake->format('Y-m-d');
}

/**
 * Bake runs whose bake day or cover window touches the week.
 *
 * @return list<array{family:string,bake_date:string,bake_weekday:int,cover_dates:list<string>}>
 */
function bakery_production_cadence_runs_for_week(string $weekStart, string $weekEnd): array
{
    $start = bakery_production_cadence_parse_date($weekStart);
    $end = bakery_production_cadence_parse_date($weekEnd);
    if (!$start || !$end || $weekEnd < $weekStart) {
        return [];
    }
    $cursor = clone $start;
    $cursor->modify('-6 days');
    $map = bakery_production_cadence_cover_map();
    $runs = [];
    while ($cursor <= $end) {
        $bakeDate = $cursor->format('Y-m-d');
        $dow = (int)$cursor->format('N');
        foreach ($map as $family => $byBake) {
            if (!isset($byBake[$dow])) {
                continue;
            }
            $coverDates = bakery_production_cadence_cover_dates($family, $bakeDate);
            $touches = ($bakeDate >= $weekStart && $bakeDate <= $weekEnd);
            if (!$touches) {
                foreach ($coverDates as $coverDate) {
                    if ($coverDate >= $weekStart && $coverDate <= $weekEnd) {
                        $touches = true;
                        break;
                    }
                }
            }
            if (!$touches) {
                continue;
            }
            $runs[] = [
                'family' => $family,
                'bake_date' => $bakeDate,
                'bake_weekday' => $dow,
                'cover_dates' => $coverDates,
            ];
        }
        $cursor->modify('+1 day');
    }
    return $runs;
}

/**
 * Which bake feeds this delivery date, per family that has a mapping.
 *
 * @return list<array{family:string,bake_date:string,cover_dates:list<string>}>
 */
function bakery_production_cadence_delivery_legs(string $deliveryDate): array
{
    $legs = [];
    foreach ([BAKERY_PRODUCTION_CADENCE_DAILY, BAKERY_PRODUCTION_CADENCE_SOUR_FLOUR] as $family) {
        $bakeDate = bakery_production_cadence_bake_date_for_delivery($family, $deliveryDate);
        if ($bakeDate === null) {
            continue;
        }
        $legs[] = [
            'family' => $family,
            'bake_date' => $bakeDate,
            'cover_dates' => bakery_production_cadence_cover_dates($family, $bakeDate),
        ];
    }
    return $legs;
}
