<?php
/**
 * Operational timeline — append-only events plus adapted authoritative records.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Event type slugs stored in operational_events.event_type */
define('BAKERY_OP_DAILY_ORDER_GENERATED', 'daily_order_generated');
define('BAKERY_OP_DEMAND_CONFIRMED', 'demand_confirmed');
define('BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED', 'daily_order_quantity_changed');
define('BAKERY_OP_DAILY_ORDER_ITEM_ADDED', 'daily_order_item_added');
define('BAKERY_OP_DAILY_ORDER_STATUS_CHANGED', 'daily_order_status_changed');
define('BAKERY_OP_DAILY_ORDER_CLEARED', 'daily_order_cleared');
define('BAKERY_OP_DRIVER_ROUTE_ASSIGNED', 'driver_route_assigned');
define('BAKERY_OP_PRODUCTION_PLAN_SAVED', 'production_plan_saved');
define('BAKERY_OP_DRIVER_LOAD_SAVED', 'driver_load_saved');
define('BAKERY_OP_DRIVER_ROUTE_CLOSED', 'driver_route_closed');
define('BAKERY_OP_DELIVERY_COMPLETED', 'delivery_completed');
define('BAKERY_OP_DELIVERY_MARKED', 'delivery_marked');
define('BAKERY_OP_DELIVERY_MODIFIED', 'delivery_modified');
define('BAKERY_OP_DELIVERY_SKIPPED', 'delivery_skipped');
define('BAKERY_OP_DELIVERY_UNSKIPPED', 'delivery_unskipped');
define('BAKERY_OP_INVOICE_GENERATED', 'invoice_generated');
define('BAKERY_OP_INVOICE_EMAILED', 'invoice_emailed');
define('BAKERY_OP_DAY_CLOSED', 'operating_day_closed');
define('BAKERY_OP_DAY_REOPENED', 'operating_day_reopened');
define('BAKERY_OP_PORTAL_STANDING_CHANGED', 'portal_standing_changed');
define('BAKERY_OP_PORTAL_DAILY_CHANGED', 'portal_daily_changed');
define('BAKERY_OP_PORTAL_DELIVERY_SKIPPED', 'portal_delivery_skipped');
define('BAKERY_OP_PORTAL_DELIVERY_UNSKIPPED', 'portal_delivery_unskipped');
define('BAKERY_OP_PORTAL_PAUSE_CREATED', 'portal_pause_created');
define('BAKERY_OP_PORTAL_PAUSE_REMOVED', 'portal_pause_removed');
define('BAKERY_OP_PORTAL_CHANGE_REQUESTED', 'portal_change_requested');
define('BAKERY_OP_PORTAL_ISSUE_SUBMITTED', 'portal_issue_submitted');
define('BAKERY_OP_PORTAL_ISSUE_REVIEW_STARTED', 'portal_issue_review_started');
define('BAKERY_OP_PORTAL_ISSUE_RESOLVED', 'portal_issue_resolved');
define('BAKERY_OP_PORTAL_ACCOUNT_UPDATED', 'portal_account_updated');
define('BAKERY_OP_PORTAL_ACCOUNT_CHANGE_REQUESTED', 'portal_account_change_requested');
define('BAKERY_OP_EXCEPTION_WORK_COMPLETED', 'manager_exception_work_completed');

function bakery_operational_events_ready(PDO $db): bool
{
    return table_exists($db, 'operational_events');
}

function bakery_ensure_operational_events_schema(PDO $db): void
{
    if (bakery_operational_events_ready($db)) {
        return;
    }
    $path = dirname(__DIR__) . '/database/schema/021_operational_events.sql';
    if (!is_readable($path)) {
        return;
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }
    $db->exec($sql);
}

function bakery_operational_driver_id(): ?int
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    if ($user && function_exists('bakery_is_driver_route_role')
        && bakery_is_driver_route_role($user['role_slug'] ?? '')
        && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO
        && function_exists('bakery_route_worker_driver_id')) {
        $driverId = bakery_route_worker_driver_id($GLOBALS['db'], $user, date('Y-m-d'));
        return $driverId > 0 ? $driverId : null;
    }
    if ($user && !empty($user['driver_id'])) {
        return (int)$user['driver_id'];
    }
    if (function_exists('bakery_get_selected_driver_id')) {
        $selected = (int)bakery_get_selected_driver_id();
        return $selected > 0 ? $selected : null;
    }
    return null;
}

/**
 * @return array{latitude:?float,longitude:?float,accuracy_m:?float,status:string,client_at:?string}
 */
function bakery_operational_gps_from_input(array $input): array
{
    $status = strtolower(trim((string)($input['gps_status'] ?? '')));
    $allowed = ['captured', 'denied', 'unavailable', 'error'];
    if (!in_array($status, $allowed, true)) {
        $status = 'unavailable';
    }

    $lat = isset($input['gps_latitude']) && $input['gps_latitude'] !== ''
        ? filter_var($input['gps_latitude'], FILTER_VALIDATE_FLOAT) : false;
    $lng = isset($input['gps_longitude']) && $input['gps_longitude'] !== ''
        ? filter_var($input['gps_longitude'], FILTER_VALIDATE_FLOAT) : false;
    $acc = isset($input['gps_accuracy_m']) && $input['gps_accuracy_m'] !== ''
        ? filter_var($input['gps_accuracy_m'], FILTER_VALIDATE_FLOAT) : false;

    if ($status === 'captured' && ($lat === false || $lng === false)) {
        $status = 'unavailable';
    }
    if ($status === 'captured' && $lat !== false && $lng !== false
        && abs((float)$lat) < 0.0001 && abs((float)$lng) < 0.0001) {
        $status = 'unavailable';
    }

    $clientAt = trim((string)($input['gps_client_at'] ?? ''));
    if ($clientAt !== '' && strlen($clientAt) > 64) {
        $clientAt = substr($clientAt, 0, 64);
    }

    return [
        'latitude' => $lat !== false ? (float)$lat : null,
        'longitude' => $lng !== false ? (float)$lng : null,
        'accuracy_m' => $acc !== false ? round((float)$acc, 1) : null,
        'status' => $status,
        'client_at' => $clientAt !== '' ? $clientAt : null,
    ];
}

function bakery_operational_order_context(PDO $db, int $dailyOrderId): ?array
{
    $stmt = $db->prepare(
        'SELECT do.id AS daily_order_id, do.customer_id, do.order_date,
                c.name AS customer_name,
                doa.id AS assignment_id, doa.driver_id
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         LEFT JOIN (
             SELECT doa1.id, doa1.daily_order_id, doa1.driver_id
             FROM daily_order_assignments doa1
             INNER JOIN (
                 SELECT daily_order_id, MAX(id) AS max_id
                 FROM daily_order_assignments
                 GROUP BY daily_order_id
             ) latest ON latest.daily_order_id = doa1.daily_order_id AND latest.max_id = doa1.id
         ) doa ON doa.daily_order_id = do.id
         WHERE do.id = ?
         LIMIT 1'
    );
    $stmt->execute([$dailyOrderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string,mixed> $options
 */
function bakery_record_operational_event(PDO $db, string $eventType, string $summary, array $options = []): ?int
{
    try {
        bakery_ensure_operational_events_schema($db);
        if (!bakery_operational_events_ready($db)) {
            return null;
        }

        $summary = trim($summary);
        if ($summary === '') {
            return null;
        }
        if (strlen($summary) > 500) {
            $summary = substr($summary, 0, 497) . '...';
        }

        $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
        $actorUserId = $options['actor_user_id'] ?? ($user['id'] ?? null);
        $actorRole = $options['actor_role'] ?? ($user['role_slug'] ?? null);

        $driverId = array_key_exists('driver_id', $options)
            ? $options['driver_id']
            : bakery_operational_driver_id();

        $metadata = $options['metadata'] ?? null;
        if (is_array($metadata)) {
            $metadata = bakery_operational_sanitize_metadata($metadata);
        }

        $gps = is_array($options['gps'] ?? null) ? $options['gps'] : [];

        $stmt = $db->prepare(
            'INSERT INTO operational_events (
                event_type, operational_date, actor_user_id, actor_role, driver_id,
                customer_id, daily_order_id, assignment_id, invoice_ref, product_id,
                summary, metadata,
                gps_latitude, gps_longitude, gps_accuracy_m, gps_status
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $eventType,
            $options['operational_date'] ?? null,
            $actorUserId,
            $actorRole,
            $driverId,
            $options['customer_id'] ?? null,
            $options['daily_order_id'] ?? null,
            $options['assignment_id'] ?? null,
            $options['invoice_ref'] ?? null,
            $options['product_id'] ?? null,
            $summary,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $gps['latitude'] ?? null,
            $gps['longitude'] ?? null,
            $gps['accuracy_m'] ?? null,
            $gps['status'] ?? null,
        ]);

        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        error_log('Operational event error: ' . $e->getMessage());
        return null;
    }
}

/**
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>
 */
function bakery_operational_sanitize_metadata(array $metadata): array
{
    $blocked = ['password', 'token', 'secret', 'csrf', 'login_code', 'portal_code', 'password_hash'];
    $clean = [];
    foreach ($metadata as $key => $value) {
        $keyLower = strtolower((string)$key);
        $skip = false;
        foreach ($blocked as $needle) {
            if (strpos($keyLower, $needle) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        if (is_array($value)) {
            $clean[$key] = bakery_operational_sanitize_metadata($value);
        } elseif (is_scalar($value) || $value === null) {
            $clean[$key] = $value;
        }
    }
    return $clean;
}

function bakery_operational_event_categories(): array
{
    return [
        'demand' => 'Demand & orders',
        'production' => 'Production',
        'inventory' => 'Inventory & loading',
        'delivery' => 'Delivery',
        'billing' => 'Billing',
        'closeout' => 'Day close',
    ];
}

function bakery_operational_event_category(string $eventType): string
{
    static $map = [
        BAKERY_OP_DAILY_ORDER_GENERATED => 'demand',
        BAKERY_OP_DAILY_ORDER_QUANTITY_CHANGED => 'demand',
        BAKERY_OP_DAILY_ORDER_ITEM_ADDED => 'demand',
        BAKERY_OP_DAILY_ORDER_STATUS_CHANGED => 'demand',
        BAKERY_OP_DAILY_ORDER_CLEARED => 'demand',
        BAKERY_OP_DRIVER_ROUTE_ASSIGNED => 'delivery',
        BAKERY_OP_PRODUCTION_PLAN_SAVED => 'production',
        BAKERY_OP_DRIVER_LOAD_SAVED => 'inventory',
        BAKERY_OP_DELIVERY_COMPLETED => 'delivery',
        BAKERY_OP_DELIVERY_MARKED => 'delivery',
        BAKERY_OP_DELIVERY_MODIFIED => 'delivery',
        BAKERY_OP_DELIVERY_SKIPPED => 'delivery',
        BAKERY_OP_DELIVERY_UNSKIPPED => 'delivery',
        BAKERY_OP_INVOICE_GENERATED => 'billing',
        BAKERY_OP_INVOICE_EMAILED => 'billing',
        BAKERY_OP_DAY_CLOSED => 'closeout',
        BAKERY_OP_DAY_REOPENED => 'closeout',
        BAKERY_OP_PORTAL_STANDING_CHANGED => 'demand',
        BAKERY_OP_PORTAL_DAILY_CHANGED => 'demand',
        BAKERY_OP_PORTAL_DELIVERY_SKIPPED => 'demand',
        BAKERY_OP_PORTAL_DELIVERY_UNSKIPPED => 'demand',
        BAKERY_OP_PORTAL_PAUSE_CREATED => 'demand',
        BAKERY_OP_PORTAL_PAUSE_REMOVED => 'demand',
        BAKERY_OP_PORTAL_CHANGE_REQUESTED => 'demand',
        BAKERY_OP_PORTAL_ISSUE_SUBMITTED => 'delivery',
        BAKERY_OP_PORTAL_ISSUE_REVIEW_STARTED => 'delivery',
        BAKERY_OP_PORTAL_ISSUE_RESOLVED => 'delivery',
        'inventory_production' => 'production',
        'inventory_count' => 'inventory',
        'inventory_load' => 'inventory',
        'inventory_load_correction' => 'inventory',
        'inventory_return' => 'inventory',
        'inventory_waste' => 'inventory',
        'inventory_delivery' => 'inventory',
        'driver_route_closed' => 'inventory',
        'driver_photo' => 'delivery',
        BAKERY_OP_EXCEPTION_WORK_COMPLETED => 'delivery',
    ];
    return $map[$eventType] ?? 'demand';
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_operational_timeline_fetch(PDO $db, array $filters = []): array
{
    $limit = max(1, min(500, (int)($filters['limit'] ?? 100)));
    $entries = [];

    if (bakery_operational_events_ready($db)) {
        $entries = array_merge($entries, bakery_operational_timeline_from_events($db, $filters));
    }
    if (table_exists($db, 'inventory_movements')) {
        $entries = array_merge($entries, bakery_operational_timeline_from_inventory($db, $filters));
    }
    if (table_exists($db, 'driver_photos')) {
        $entries = array_merge($entries, bakery_operational_timeline_from_photos($db, $filters));
    }

    usort($entries, static function ($a, $b) {
        return strcmp($b['occurred_at'], $a['occurred_at']);
    });

    return array_slice($entries, 0, $limit);
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_operational_timeline_from_events(PDO $db, array $filters): array
{
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['operational_date'])) {
        $where[] = 'oe.operational_date = ?';
        $params[] = $filters['operational_date'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'oe.customer_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    if (!empty($filters['daily_order_id'])) {
        $where[] = 'oe.daily_order_id = ?';
        $params[] = (int)$filters['daily_order_id'];
    }
    if (!empty($filters['driver_id'])) {
        $where[] = 'oe.driver_id = ?';
        $params[] = (int)$filters['driver_id'];
    }
    if (!empty($filters['actor_user_id'])) {
        $where[] = 'oe.actor_user_id = ?';
        $params[] = (int)$filters['actor_user_id'];
    }
    if (!empty($filters['event_type'])) {
        $where[] = 'oe.event_type = ?';
        $params[] = $filters['event_type'];
    }
    if (!empty($filters['since'])) {
        $where[] = 'oe.occurred_at >= ?';
        $params[] = $filters['since'];
    }
    if (!empty($filters['until'])) {
        $where[] = 'oe.occurred_at <= ?';
        $params[] = $filters['until'];
    }

    $sql = '
        SELECT oe.id, oe.event_type, oe.operational_date, oe.occurred_at,
               oe.actor_user_id, oe.actor_role, oe.driver_id, oe.customer_id,
               oe.daily_order_id, oe.assignment_id, oe.invoice_ref, oe.product_id,
               oe.summary, oe.metadata, oe.gps_latitude, oe.gps_longitude,
               oe.gps_accuracy_m, oe.gps_status,
               u.display_name AS actor_name,
               d.name AS driver_name,
               c.name AS customer_name,
               p.name AS product_name
        FROM operational_events oe
        LEFT JOIN users u ON u.id = oe.actor_user_id
        LEFT JOIN drivers d ON d.id = oe.driver_id
        LEFT JOIN customers c ON c.id = oe.customer_id
        LEFT JOIN products p ON p.id = oe.product_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY oe.occurred_at DESC
        LIMIT 500';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryFilter = $filters['category'] ?? '';
    $out = [];
    foreach ($rows as $row) {
        $eventType = (string)$row['event_type'];
        $category = bakery_operational_event_category($eventType);
        if ($categoryFilter !== '' && $categoryFilter !== $category) {
            continue;
        }
        $metadata = [];
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string)$row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        $out[] = bakery_operational_timeline_normalize_row([
            'source' => 'event',
            'source_id' => (int)$row['id'],
            'event_type' => $eventType,
            'category' => $category,
            'occurred_at' => (string)$row['occurred_at'],
            'operational_date' => $row['operational_date'],
            'summary' => (string)$row['summary'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int)$row['actor_user_id'] : null,
            'actor_name' => (string)($row['actor_name'] ?? ''),
            'actor_role' => (string)($row['actor_role'] ?? ''),
            'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'customer_id' => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'daily_order_id' => $row['daily_order_id'] !== null ? (int)$row['daily_order_id'] : null,
            'product_id' => $row['product_id'] !== null ? (int)$row['product_id'] : null,
            'product_name' => (string)($row['product_name'] ?? ''),
            'invoice_ref' => $row['invoice_ref'],
            'metadata' => $metadata,
            'gps_latitude' => $row['gps_latitude'],
            'gps_longitude' => $row['gps_longitude'],
            'gps_accuracy_m' => $row['gps_accuracy_m'],
            'gps_status' => $row['gps_status'],
        ]);
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_operational_timeline_from_inventory(PDO $db, array $filters): array
{
    if (!empty($filters['event_type']) && strpos((string)$filters['event_type'], 'inventory_') !== 0) {
        return [];
    }

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['operational_date'])) {
        $where[] = 'im.delivery_date = ?';
        $params[] = $filters['operational_date'];
    }
    if (!empty($filters['driver_id'])) {
        $where[] = 'im.driver_id = ?';
        $params[] = (int)$filters['driver_id'];
    }
    if (!empty($filters['actor_user_id'])) {
        $where[] = 'im.created_by_user_id = ?';
        $params[] = (int)$filters['actor_user_id'];
    }
    if (!empty($filters['daily_order_id'])) {
        $where[] = 'im.daily_order_id = ?';
        $params[] = (int)$filters['daily_order_id'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'EXISTS (
            SELECT 1 FROM daily_orders do
            WHERE do.id = im.daily_order_id AND do.customer_id = ?
        )';
        $params[] = (int)$filters['customer_id'];
    }
    if (!empty($filters['since'])) {
        $where[] = 'im.created_at >= ?';
        $params[] = $filters['since'];
    }
    if (!empty($filters['until'])) {
        $where[] = 'im.created_at <= ?';
        $params[] = $filters['until'];
    }

    $sql = '
        SELECT im.id, im.delivery_date, im.movement_type, im.quantity_delta,
               im.driver_id, im.daily_order_id, im.notes, im.created_at, im.created_by_user_id,
               u.display_name AS actor_name,
               r.slug AS actor_role,
               d.name AS driver_name,
               p.name AS product_name, p.id AS product_id,
               c.id AS customer_id, c.name AS customer_name
        FROM inventory_movements im
        JOIN products p ON p.id = im.product_id
        LEFT JOIN users u ON u.id = im.created_by_user_id
        LEFT JOIN roles r ON r.id = u.role_id
        LEFT JOIN drivers d ON d.id = im.driver_id
        LEFT JOIN daily_orders do ON do.id = im.daily_order_id
        LEFT JOIN customers c ON c.id = do.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY im.created_at DESC
        LIMIT 500';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryFilter = $filters['category'] ?? '';
    $out = [];
    foreach ($rows as $row) {
        $type = (string)$row['movement_type'];
        $eventType = 'inventory_' . $type;
        $category = bakery_operational_event_category($eventType);
        if ($categoryFilter !== '' && $categoryFilter !== $category) {
            continue;
        }
        if (!empty($filters['event_type']) && $filters['event_type'] !== $eventType) {
            continue;
        }

        $delta = (int)$row['quantity_delta'];
        $productName = (string)$row['product_name'];
        $summary = bakery_operational_inventory_summary($type, $delta, $productName, (string)($row['driver_name'] ?? ''));

        $out[] = bakery_operational_timeline_normalize_row([
            'source' => 'inventory',
            'source_id' => (int)$row['id'],
            'event_type' => $eventType,
            'category' => $category,
            'occurred_at' => (string)$row['created_at'],
            'operational_date' => $row['delivery_date'],
            'summary' => $summary,
            'actor_user_id' => $row['created_by_user_id'] !== null ? (int)$row['created_by_user_id'] : null,
            'actor_name' => (string)($row['actor_name'] ?? ''),
            'actor_role' => (string)($row['actor_role'] ?? ''),
            'driver_id' => $row['driver_id'] !== null ? (int)$row['driver_id'] : null,
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'customer_id' => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'daily_order_id' => $row['daily_order_id'] !== null ? (int)$row['daily_order_id'] : null,
            'product_id' => (int)$row['product_id'],
            'product_name' => $productName,
            'metadata' => array_filter([
                'quantity_delta' => $delta,
                'notes' => $row['notes'] ?: null,
            ]),
            'gps_status' => null,
        ]);
    }
    return $out;
}

function bakery_operational_inventory_summary(string $type, int $delta, string $productName, string $driverName): string
{
    $qty = abs($delta);
    switch ($type) {
        case 'production':
            return "Recorded production of {$qty} {$productName}";
        case 'count':
            return $delta >= 0
                ? "Physical count added {$qty} {$productName} to finished goods"
                : "Physical count reduced {$productName} by {$qty}";
        case 'load':
            $who = $driverName !== '' ? " for {$driverName}" : '';
            return $delta < 0
                ? 'Loaded ' . abs($delta) . " {$productName}{$who}"
                : "Returned {$qty} {$productName} from driver load";
        case 'load_correction':
            return "Corrected driver load: {$qty} {$productName} returned to stock";
        case 'return':
            return "Returned {$qty} {$productName} to finished goods";
        case 'waste':
            $who = $driverName !== '' ? " on {$driverName}'s route" : '';
            return "Recorded waste of {$qty} {$productName}{$who}";
        case 'delivery':
            $who = $driverName !== '' ? " by {$driverName}" : '';
            return "Closed out {$qty} {$productName} delivered{$who}";
        default:
            return "Inventory movement ({$type}): {$qty} {$productName}";
    }
}

/**
 * @return list<array<string,mixed>>
 */
function bakery_operational_timeline_from_photos(PDO $db, array $filters): array
{
    if (!empty($filters['event_type']) && $filters['event_type'] !== 'driver_photo') {
        return [];
    }

    $where = ['1=1'];
    $params = [];

    if (!empty($filters['operational_date'])) {
        $where[] = 'dp.delivery_date = ?';
        $params[] = $filters['operational_date'];
    }
    if (!empty($filters['customer_id'])) {
        $where[] = 'dp.customer_id = ?';
        $params[] = (int)$filters['customer_id'];
    }
    if (!empty($filters['driver_id'])) {
        $where[] = 'dp.driver_id = ?';
        $params[] = (int)$filters['driver_id'];
    }
    if (!empty($filters['since'])) {
        $where[] = 'dp.created_at >= ?';
        $params[] = $filters['since'];
    }
    if (!empty($filters['until'])) {
        $where[] = 'dp.created_at <= ?';
        $params[] = $filters['until'];
    }
    if (!empty($filters['daily_order_id'])) {
        $where[] = 'EXISTS (
            SELECT 1 FROM daily_orders do
            WHERE do.customer_id = dp.customer_id
              AND do.order_date = dp.delivery_date
              AND do.id = ?
        )';
        $params[] = (int)$filters['daily_order_id'];
    }

    $sql = '
        SELECT dp.id, dp.driver_id, dp.customer_id, dp.delivery_date, dp.photo_type,
               dp.latitude, dp.longitude, dp.created_at,
               d.name AS driver_name, c.name AS customer_name
        FROM driver_photos dp
        JOIN drivers d ON d.id = dp.driver_id
        LEFT JOIN customers c ON c.id = dp.customer_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY dp.created_at DESC
        LIMIT 200';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $categoryFilter = $filters['category'] ?? '';
    $out = [];
    foreach ($rows as $row) {
        $category = bakery_operational_event_category('driver_photo');
        if ($categoryFilter !== '' && $categoryFilter !== $category) {
            continue;
        }
        $customerName = (string)($row['customer_name'] ?? 'customer');
        $driverName = (string)($row['driver_name'] ?? 'Driver');
        $photoType = (string)($row['photo_type'] ?? 'After');
        $summary = "{$driverName} attached a {$photoType} delivery photo at {$customerName}";

        $hasGps = $row['latitude'] !== null && $row['longitude'] !== null;

        $out[] = bakery_operational_timeline_normalize_row([
            'source' => 'photo',
            'source_id' => (int)$row['id'],
            'event_type' => 'driver_photo',
            'category' => $category,
            'occurred_at' => (string)$row['created_at'],
            'operational_date' => $row['delivery_date'],
            'summary' => $summary,
            'actor_name' => $driverName,
            'driver_id' => (int)$row['driver_id'],
            'driver_name' => $driverName,
            'customer_id' => $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            'customer_name' => $customerName,
            'metadata' => [
                'photo_type' => $photoType,
                'photo_id' => (int)$row['id'],
            ],
            'gps_latitude' => $row['latitude'],
            'gps_longitude' => $row['longitude'],
            'gps_status' => $hasGps ? 'captured' : 'unavailable',
        ]);
    }
    return $out;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function bakery_operational_timeline_normalize_row(array $row): array
{
    $row['actor_user_id'] = $row['actor_user_id'] ?? null;
    $row['driver_id'] = $row['driver_id'] ?? null;
    $row['customer_id'] = $row['customer_id'] ?? null;
    $row['daily_order_id'] = $row['daily_order_id'] ?? null;
    $row['metadata'] = $row['metadata'] ?? [];
    $row['detail_lines'] = bakery_operational_timeline_detail_lines($row);
    $row['links'] = bakery_operational_timeline_links($row);
    return $row;
}

/**
 * @param array<string,mixed> $entry
 * @return list<string>
 */
function bakery_operational_timeline_detail_lines(array $entry): array
{
    $lines = [];
    $meta = is_array($entry['metadata'] ?? null) ? $entry['metadata'] : [];

    if (!empty($meta['ordered_pieces']) || !empty($meta['delivered_pieces'])) {
        $ordered = (int)($meta['ordered_pieces'] ?? 0);
        $delivered = (int)($meta['delivered_pieces'] ?? 0);
        if ($ordered > 0 || $delivered > 0) {
            $lines[] = "Delivered {$delivered} of {$ordered} ordered";
        }
    }
    if (isset($meta['credits_taken_back']) && (int)$meta['credits_taken_back'] > 0) {
        $lines[] = (int)$meta['credits_taken_back'] . ' credit(s) taken back';
    }
    if (isset($meta['total']) && $meta['total'] !== null) {
        $lines[] = 'Billable total: $' . number_format((float)$meta['total'], 2);
    }
    if (!empty($meta['amount_collected'])) {
        $lines[] = 'Cash collected: $' . number_format((float)$meta['amount_collected'], 2);
    }
    if (!empty($meta['photo_attached'])) {
        $lines[] = 'Photo attached';
    }
    if (!empty($meta['notes'])) {
        $lines[] = (string)$meta['notes'];
    }

    $gpsLine = bakery_operational_gps_detail_line($entry);
    if ($gpsLine !== '') {
        $lines[] = $gpsLine;
    }

    return $lines;
}

/**
 * @param array<string,mixed> $entry
 */
function bakery_operational_gps_detail_line(array $entry): string
{
    $status = (string)($entry['gps_status'] ?? '');
    if ($status === 'captured' && $entry['gps_latitude'] !== null && $entry['gps_longitude'] !== null) {
        $acc = $entry['gps_accuracy_m'] ?? null;
        if ($acc !== null && (float)$acc > 0) {
            return 'GPS captured ±' . round((float)$acc) . ' m';
        }
        return 'GPS captured';
    }
    if ($status === 'denied') {
        return 'GPS not captured (permission denied)';
    }
    if ($status === 'error') {
        return 'GPS not captured (location error)';
    }
    if ($status === 'unavailable') {
        return 'GPS not captured';
    }
    return '';
}

/**
 * @param array<string,mixed> $entry
 * @return array<string,string>
 */
function bakery_operational_timeline_links(array $entry): array
{
    $links = [];
    if (!empty($entry['customer_id'])) {
        $links['customer'] = 'customer_record.php?customer_id=' . (int)$entry['customer_id'];
    }
    if (!empty($entry['daily_order_id'])) {
        $date = $entry['operational_date'] ?? '';
        $links['order'] = 'operational_timeline.php?context=order&daily_order_id=' . (int)$entry['daily_order_id']
            . ($date !== '' ? '&date=' . urlencode((string)$date) : '');
        $links['daily_orders'] = 'daily_orders.php?date=' . urlencode((string)($date ?: date('Y-m-d')));
    }
    if (!empty($entry['operational_date'])) {
        $links['day'] = 'operational_timeline.php?context=day&date=' . urlencode((string)$entry['operational_date']);
    }
    if (!empty($entry['invoice_ref'])) {
        $links['invoice'] = 'billing_center.php?panel=invoices';
    }
    if (($entry['event_type'] ?? '') === BAKERY_OP_PRODUCTION_PLAN_SAVED
        || strpos((string)($entry['event_type'] ?? ''), 'inventory_production') === 0) {
        $links['production'] = 'production.php?date=' . urlencode((string)($entry['operational_date'] ?? date('Y-m-d')));
    }
    return $links;
}

function bakery_operational_format_time(string $occurredAt): string
{
    $ts = strtotime($occurredAt);
    return $ts === false ? $occurredAt : date('g:i A', $ts);
}

function bakery_operational_format_datetime(string $occurredAt): string
{
    $ts = strtotime($occurredAt);
    return $ts === false ? $occurredAt : date('M j, Y g:i A', $ts);
}

function bakery_operational_actor_label(array $entry): string
{
    if (!empty($entry['actor_name'])) {
        $role = !empty($entry['actor_role']) ? ' (' . $entry['actor_role'] . ')' : '';
        return (string)$entry['actor_name'] . $role;
    }
    if (!empty($entry['driver_name'])) {
        return (string)$entry['driver_name'] . ' (driver)';
    }
    return 'System';
}

/**
 * @param array<string,mixed> $meta
 */
function bakery_operational_log_delivery(PDO $db, string $eventType, int $dailyOrderId, string $summary, array $meta = [], ?array $gps = null): void
{
    $ctx = bakery_operational_order_context($db, $dailyOrderId);
    if (!$ctx) {
        return;
    }
    $driverId = $ctx['driver_id'] !== null ? (int)$ctx['driver_id'] : bakery_operational_driver_id();
    bakery_record_operational_event($db, $eventType, $summary, [
        'operational_date' => $ctx['order_date'],
        'customer_id' => (int)$ctx['customer_id'],
        'daily_order_id' => $dailyOrderId,
        'assignment_id' => $ctx['assignment_id'] !== null ? (int)$ctx['assignment_id'] : null,
        'driver_id' => $driverId,
        'metadata' => $meta,
        'gps' => $gps,
    ]);
}

function bakery_operational_log_user_action(PDO $db, string $action, string $entity, $entityId = null, $details = null): void
{
    $summary = ucfirst(str_replace('_', ' ', $action)) . ' ' . $entity;
    if ($entityId !== null) {
        $summary .= ' #' . $entityId;
    }
    if ($details !== null && $details !== '') {
        $summary .= ' — ' . (is_string($details) ? $details : json_encode($details));
    }
    bakery_record_operational_event($db, 'legacy_' . preg_replace('/[^a-z0-9_]+/i', '_', $action), $summary, [
        'metadata' => [
            'legacy_entity' => $entity,
            'legacy_entity_id' => $entityId,
            'legacy_details' => is_string($details) ? $details : $details,
        ],
    ]);
}
