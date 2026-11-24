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
