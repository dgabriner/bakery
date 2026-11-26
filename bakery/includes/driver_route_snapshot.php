<?php
/**
 * Compact route state for one driver and date: stop ids, order, and status.
 *
 * My Route embeds the hash at page load and re-asks on focus or after the
 * outbox syncs; an unchanged hash costs one short reply instead of a reload.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/sfb_origin.php';
require_once __DIR__ . '/operational_timeline.php';

function bakery_driver_route_snapshot(PDO $db, int $driverId, string $date): array
{
    $stmt = $db->prepare("
        SELECT do.id AS id, doa.route_order AS route_order, doa.delivery_status AS status
        FROM daily_orders do
        INNER JOIN customers c ON do.customer_id = c.id
        " . bakery_sfb_ops_origin_clause('c', $db) . "
        INNER JOIN daily_order_assignments doa ON do.id = doa.daily_order_id
        WHERE doa.driver_id = ? AND do.order_date = ?
        ORDER BY doa.route_order, c.zone, c.name
    ");
    $stmt->execute([$driverId, $date]);
    $flagged = bakery_delivery_empty_flagged_order_ids($db, $date);
    $stops = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)$row['id'];
        $status = (string)($row['status'] ?? '');
        if ($status === '') {
            $status = 'pending';
        }
        if (isset($flagged[$id])) {
            $status = 'office_flag';
        }
        $stops[] = [
            'id' => $id,
            'order' => $row['route_order'] === null ? null : (int)$row['route_order'],
            'status' => $status,
        ];
    }

    return [
        'stops' => $stops,
        'hash' => substr(sha1((string)json_encode($stops)), 0, 16),
    ];
}

/**
 * One count for every layout. A carryover stop is on this date's assignment
 * list, so it is today's work. Phone progress and the empty-day card both
 * read this.
 *
 * @param list<array<string, mixed>> $stops
 * @return array{stop_count:int, carry_count:int, empty:bool}
 */
function bakery_driver_route_day_state(array $stops): array
{
    $carry = 0;
    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        if (trim((string)($stop['carry_label'] ?? '')) !== '') {
            $carry++;
        }
    }
    $count = count($stops);
    return [
        'stop_count' => $count,
        'carry_count' => $carry,
        'empty' => $count === 0,
    ];
}

/**
 * Next calendar day, within $horizonDays, that already has an assignment
 * for this driver. Empty string when none.
 */
function bakery_driver_next_assigned_date(PDO $db, int $driverId, string $afterDate, int $horizonDays = 21): string
{
    if ($driverId <= 0 || $horizonDays < 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $afterDate) !== 1) {
        return '';
    }
    if (!function_exists('table_exists') || !table_exists($db, 'daily_orders') || !table_exists($db, 'daily_order_assignments')) {
        return '';
    }
    try {
        $until = (new DateTimeImmutable($afterDate))->modify('+' . $horizonDays . ' days')->format('Y-m-d');
        $stmt = $db->prepare(
            'SELECT MIN(do.order_date)
             FROM daily_orders do
             INNER JOIN daily_order_assignments doa ON doa.daily_order_id = do.id
             WHERE doa.driver_id = ? AND do.order_date > ? AND do.order_date <= ?'
        );
        $stmt->execute([$driverId, $afterDate, $until]);
        $found = substr((string)$stmt->fetchColumn(), 0, 10);
    } catch (Throwable $e) {
        return '';
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $found) === 1 ? $found : '';
}
