<?php
/**
 * Daily order generation from standing orders — shared by Daily Orders,
 * Daily Run inline actions, the dashboard, and the CLI test harness.
 *
 * Inactive customers never generate orders. Dated quantity changes are
 * preserved unless overwrite mode is explicitly requested.
 * Page views must not call the fillers. The cron, the staff self-heal POST,
 * and an explicit manager Build from standing action are the writers.
 * An order with standing_fill_blocked stays empty unless overwrite is requested.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/customer_portal.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/cron_run.php';
require_once __DIR__ . '/route_autoclose.php';

/**
 * Next free dated position for one standing stop.
 *
 * Uses the standing slot when that number is still open. Otherwise appends
 * after the highest position already taken for the driver on this date.
 *
 * @param array<int,int> $maxRouteOrderByDriver
 * @param array<int,array<int,bool>> $usedRouteOrdersByDriver
 */
function bakery_next_standing_route_order(
    int $driverId,
    int $standingRouteOrder,
    array &$maxRouteOrderByDriver,
    array &$usedRouteOrdersByDriver
): int {
    if ($standingRouteOrder > 0 && empty($usedRouteOrdersByDriver[$driverId][$standingRouteOrder])) {
        $routeOrder = $standingRouteOrder;
    } else {
        $routeOrder = ($maxRouteOrderByDriver[$driverId] ?? 0) + 1;
        while (!empty($usedRouteOrdersByDriver[$driverId][$routeOrder])) {
            $routeOrder++;
        }
    }
    $maxRouteOrderByDriver[$driverId] = max($maxRouteOrderByDriver[$driverId] ?? 0, $routeOrder);
    $usedRouteOrdersByDriver[$driverId][$routeOrder] = true;
    return $routeOrder;
}

/**
 * True when an active standing-route stop for this date has no dated assignment.
 * Paused and skipped customers are ignored. A cancelled assignment still counts
 * as present. A recorded manager removal stays off. An untracked hard delete
 * does not, so the next fill copies that stop again.
 */
function bakery_date_has_unassigned_standing_route_stops(PDO $db, string $date): bool
{
    if (!table_exists($db, 'standing_routes') || !table_exists($db, 'daily_orders') || !table_exists($db, 'daily_order_assignments')) {
        return false;
    }
    $dayClause = bakery_standing_day_in_clause(bakery_standing_day_from_date($date));
    $weekStart = bakery_week_start_monday($date);
    $pauseSql = '';
    $pauseParams = [];
    if (table_exists($db, 'standing_order_pauses')) {
        $pauseSql .= ' AND NOT EXISTS (
            SELECT 1 FROM standing_order_pauses sop
            WHERE sop.customer_id = sr.customer_id AND sop.week_start = ?
        )';
        $pauseParams[] = $weekStart;
    }
    if (table_exists($db, 'customer_delivery_skips')) {
        $pauseSql .= ' AND NOT EXISTS (
            SELECT 1 FROM customer_delivery_skips cds
            WHERE cds.customer_id = sr.customer_id AND cds.skip_date = ?
        )';
        $pauseParams[] = $date;
    }
    if (table_exists($db, 'customer_delivery_pauses')) {
        $pauseSql .= ' AND NOT EXISTS (
            SELECT 1 FROM customer_delivery_pauses cdp
            WHERE cdp.customer_id = sr.customer_id
              AND cdp.pause_start <= ? AND cdp.pause_end >= ?
        )';
        $pauseParams[] = $date;
        $pauseParams[] = $date;
    }
    if (table_exists($db, 'dated_route_removals')) {
        $pauseSql .= ' AND NOT EXISTS (
            SELECT 1 FROM dated_route_removals drr
            WHERE drr.customer_id = sr.customer_id AND drr.delivery_date = ?
        )';
        $pauseParams[] = $date;
    }

    $stmt = $db->prepare(
        'SELECT 1
         FROM standing_routes sr
         JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
         ' . bakery_sfb_ops_origin_clause('c', $db) . '
         WHERE sr.day_of_week ' . $dayClause['sql'] . '
           AND NOT EXISTS (
               SELECT 1
               FROM daily_orders do
               JOIN daily_order_assignments doa
                 ON doa.daily_order_id = do.id AND doa.delivery_date = ?
               WHERE do.customer_id = sr.customer_id AND do.order_date = ?
           )
           ' . $pauseSql . '
         LIMIT 1'
    );
    $stmt->execute([...$dayClause['values'], $date, $date, ...$pauseParams]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Create the dated-removal table when this database has not migrated yet.
 * Call this before opening a transaction. DDL commits an open transaction.
 */
function bakery_dated_route_removals_ready(PDO $db): bool
{
    static $ready = false;
    if ($ready && table_exists($db, 'dated_route_removals')) {
        return true;
    }
    if (table_exists($db, 'dated_route_removals')) {
        $ready = true;
        return true;
    }
    if ($db->inTransaction()) {
        return false;
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS dated_route_removals (
            id INT NOT NULL AUTO_INCREMENT,
            delivery_date DATE NOT NULL,
            customer_id INT NOT NULL,
            driver_id INT NULL DEFAULT NULL,
            removed_by_user_id INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dated_route_removals_date_customer (delivery_date, customer_id),
            KEY idx_dated_route_removals_driver_date (driver_id, delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('dated_route_removals');
    }
    $ready = table_exists($db, 'dated_route_removals');
    return $ready;
}

/**
 * @return array<int,true>
 */
function bakery_dated_route_removed_customer_ids(PDO $db, string $date): array
{
    if (!table_exists($db, 'dated_route_removals')) {
        return [];
    }
    $stmt = $db->prepare('SELECT customer_id FROM dated_route_removals WHERE delivery_date = ?');
    $stmt->execute([$date]);
    $ids = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $customerId) {
        $ids[(int)$customerId] = true;
    }
    return $ids;
}

function bakery_dated_route_removal_record(PDO $db, string $date, int $customerId, int $driverId): void
{
    if ($customerId <= 0 || !table_exists($db, 'dated_route_removals')) {
        return;
    }
    $userId = null;
    if (function_exists('bakery_current_user')) {
        $user = bakery_current_user();
        if ($user && (int)($user['id'] ?? 0) > 0) {
            $userId = (int)$user['id'];
        }
    }
    $stmt = $db->prepare(
        'INSERT INTO dated_route_removals (delivery_date, customer_id, driver_id, removed_by_user_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            driver_id = VALUES(driver_id),
            removed_by_user_id = VALUES(removed_by_user_id),
            created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$date, $customerId, $driverId > 0 ? $driverId : null, $userId]);
}

function bakery_dated_route_removal_clear(PDO $db, string $date, int $customerId): void
{
    if ($customerId <= 0 || !table_exists($db, 'dated_route_removals')) {
        return;
    }
    $db->prepare('DELETE FROM dated_route_removals WHERE delivery_date = ? AND customer_id = ?')
        ->execute([$date, $customerId]);
}

function bakery_dated_route_removal_clear_date(PDO $db, string $date): void
{
    if (!table_exists($db, 'dated_route_removals')) {
        return;
    }
    $db->prepare('DELETE FROM dated_route_removals WHERE delivery_date = ?')->execute([$date]);
}

/**
 * Create the append-only removal log when this database has not migrated yet.
 * Call this before opening a transaction. DDL commits an open transaction.
 */
function bakery_dated_route_removal_log_ready(PDO $db): bool
{
    static $ready = false;
    if ($ready && table_exists($db, 'dated_route_removal_log')) {
        return true;
    }
    if (table_exists($db, 'dated_route_removal_log')) {
        $ready = true;
        return true;
    }
    if ($db->inTransaction()) {
        return false;
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS dated_route_removal_log (
            id BIGINT NOT NULL AUTO_INCREMENT,
            delivery_date DATE NOT NULL,
            customer_id INT NOT NULL,
            daily_order_id INT NULL DEFAULT NULL,
            driver_id INT NULL DEFAULT NULL,
            removed_by_user_id INT NULL DEFAULT NULL,
            removed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reason VARCHAR(64) NOT NULL,
            code_path VARCHAR(128) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_dated_route_removal_log_date_driver (delivery_date, driver_id),
            KEY idx_dated_route_removal_log_customer_date (customer_id, delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('dated_route_removal_log');
    }
    $ready = table_exists($db, 'dated_route_removal_log');
    return $ready;
}

/**
 * Create the morning route baseline when this database has not migrated yet.
 * Call this before opening a transaction. DDL commits an open transaction.
 */
function bakery_dated_route_morning_checks_ready(PDO $db): bool
{
    static $ready = false;
    if ($ready && table_exists($db, 'dated_route_morning_checks')) {
        return true;
    }
    if (table_exists($db, 'dated_route_morning_checks')) {
        $ready = true;
        return true;
    }
    if ($db->inTransaction()) {
        return false;
    }
    $db->exec(
        'CREATE TABLE IF NOT EXISTS dated_route_morning_checks (
            id BIGINT NOT NULL AUTO_INCREMENT,
            delivery_date DATE NOT NULL,
            driver_id INT NOT NULL,
            customer_id INT NOT NULL,
            checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_dated_route_morning_date_driver_customer (delivery_date, driver_id, customer_id),
            KEY idx_dated_route_morning_date (delivery_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('dated_route_morning_checks');
    }
    $ready = table_exists($db, 'dated_route_morning_checks');
    return $ready;
}

function bakery_dated_route_actor_user_id(): ?int
{
    if (!function_exists('bakery_current_user')) {
        return null;
    }
    $user = bakery_current_user();
    $userId = (int)($user['id'] ?? 0);
    return $userId > 0 ? $userId : null;
}

/**
 * Record who removed a stop, when, why, and which code path.
 * A tombstone also blocks the next standing fill from copying the stop back.
 */
function bakery_dated_route_log_removal(
    PDO $db,
    string $date,
    int $customerId,
    int $driverId,
    int $dailyOrderId,
    string $reason,
    string $codePath,
    bool $tombstone = false
): void {
    if ($customerId <= 0) {
        return;
    }
    if (table_exists($db, 'dated_route_removal_log')) {
        $stmt = $db->prepare(
            'INSERT INTO dated_route_removal_log (
                delivery_date, customer_id, daily_order_id, driver_id,
                removed_by_user_id, reason, code_path
             ) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $date,
            $customerId,
            $dailyOrderId > 0 ? $dailyOrderId : null,
            $driverId > 0 ? $driverId : null,
            bakery_dated_route_actor_user_id(),
            substr($reason, 0, 64),
            substr($codePath, 0, 128),
        ]);
    }
    if ($tombstone) {
        bakery_dated_route_removal_record($db, $date, $customerId, $driverId);
    }
}

/**
 * Freeze the first non-empty dated route for this date. Later writers do not
 * shrink the baseline, so an evening loss is still visible.
 */
function bakery_dated_route_morning_capture(PDO $db, string $date): void
{
    if (!bakery_dated_route_morning_checks_ready($db)) {
        return;
    }
    $exists = $db->prepare('SELECT 1 FROM dated_route_morning_checks WHERE delivery_date = ? LIMIT 1');
    $exists->execute([$date]);
    if ($exists->fetchColumn()) {
        return;
    }
    $rows = $db->prepare(
        'SELECT doa.driver_id, do.customer_id
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id AND do.order_date = doa.delivery_date
         WHERE doa.delivery_date = ?
           AND doa.driver_id IS NOT NULL
           AND do.customer_id IS NOT NULL'
    );
    $rows->execute([$date]);
    $found = $rows->fetchAll(PDO::FETCH_ASSOC);
    if ($found === []) {
        return;
    }
    $insert = $db->prepare(
        'INSERT IGNORE INTO dated_route_morning_checks (delivery_date, driver_id, customer_id)
         VALUES (?, ?, ?)'
    );
    foreach ($found as $row) {
        $driverId = (int)$row['driver_id'];
        $customerId = (int)$row['customer_id'];
        if ($driverId > 0 && $customerId > 0) {
            $insert->execute([$date, $driverId, $customerId]);
        }
    }
}

/**
 * Stores on a driver at the morning check that are no longer on that driver.
 * Read-only. A missing table returns an empty list and does not create one.
 *
 * @return list<array{driver_id:int,driver_name:string,customer_id:int,customer_name:string}>
 */
function bakery_dated_route_morning_losses(PDO $db, string $date): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'dated_route_morning_checks')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT m.driver_id, d.name AS driver_name, m.customer_id, c.name AS customer_name
         FROM dated_route_morning_checks m
         JOIN customers c ON c.id = m.customer_id
         LEFT JOIN drivers d ON d.id = m.driver_id
         WHERE m.delivery_date = ?
           AND NOT EXISTS (
               SELECT 1
               FROM daily_order_assignments doa
               JOIN daily_orders do ON do.id = doa.daily_order_id
               WHERE doa.delivery_date = m.delivery_date
                 AND doa.driver_id = m.driver_id
                 AND do.customer_id = m.customer_id
                 AND do.order_date = doa.delivery_date
           )
         ORDER BY d.name, c.name, m.customer_id'
    );
    $stmt->execute([$date]);
    $losses = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $losses[] = [
            'driver_id' => (int)$row['driver_id'],
            'driver_name' => (string)($row['driver_name'] ?? ''),
            'customer_id' => (int)$row['customer_id'],
            'customer_name' => (string)$row['customer_name'],
        ];
    }
    return $losses;
}

/**
 * @param list<int> $orderIds
 * @return array<int,int> daily_order_id => customer_id
 */
function bakery_dated_route_customers_for_orders(PDO $db, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter(
        array_map('intval', $orderIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($orderIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $db->prepare("SELECT id, customer_id FROM daily_orders WHERE id IN ($placeholders)");
    $stmt->execute($orderIds);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['id']] = (int)$row['customer_id'];
    }
    return $map;
}

/**
 * Generate dated daily orders from standing orders for one date.
 *
 * @param array{overwrite_changed?:bool, record_event?:bool, assign_routes?:bool} $options
 *   overwrite_changed — replace dated quantity edits with standing quantities (default false)
 *   record_event      — write an operational-timeline event (default true)
 *   assign_routes     — copy standing-route stops onto the dated route (default true).
 *                       Stops with no standing order are included. An existing
 *                       dated assignment is left where it is. A recorded
 *                       manager removal stays off the route. An untracked hard
 *                       delete is not recorded, so the next fill copies that
 *                       stop again. Cancelled assignments stay cancelled.
 *
 * @return array{db_day:int, standing_rows:int, orders_created:int, items_created:int,
 *               items_updated:int, items_preserved:int, overwrite_changed:bool,
 *               drivers_assigned:int, routes_preserved:int, orders_without_route:int, message:string}
 * @throws Exception on invalid date or database failure (transaction rolled back)
 */
function bakery_generate_daily_orders_from_standing(PDO $db, string $date, array $options = []): array
{
    $overwriteChanged = !empty($options['overwrite_changed']);
    $recordEvent = !array_key_exists('record_event', $options) || !empty($options['record_event']);
    $assignRoutes = !array_key_exists('assign_routes', $options) || !empty($options['assign_routes']);
    $onlyCustomerId = max(0, (int)($options['only_customer_id'] ?? 0));

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new Exception('Invalid order date');
    }

    $dbDayOfWeek = bakery_standing_day_from_date($date);
    $dayClause = bakery_standing_day_in_clause($dbDayOfWeek);

    // Runtime schema checks may issue DDL on an older local installation,
    // which implicitly commits in MariaDB. Finish them before opening the
    // generation transaction.
    bakery_customer_order_ensure_schema($db);
    $removedCustomerIds = [];
    if ($assignRoutes) {
        bakery_dated_route_removals_ready($db);
        bakery_dated_route_removal_log_ready($db);
        bakery_dated_route_morning_checks_ready($db);
        $removedCustomerIds = bakery_dated_route_removed_customer_ids($db, $date);
    }
    $standingFillColumn = bakery_daily_order_standing_fill_column_ready($db);
    $db->beginTransaction();

    try {
        // Get all standing orders for this day in one efficient query.
        // Inactive customers are excluded so deactivation stops generation.
        $stmt = $db->prepare("
            SELECT so.customer_id, so.product_id, so.quantity,
                   COALESCE(p.price, 0) as price,
                   p.wholesale_price,
                   c.default_pan_dulce_price,
                   c.pricing_tier,
                   pl.name as product_line_name
            FROM standing_orders so
            JOIN customers c ON so.customer_id = c.id AND c.is_active = 1
                " . bakery_sfb_ops_origin_clause('c', $db) . "
            JOIN products p ON so.product_id = p.id
            JOIN dough_types dt ON p.dough_type_id = dt.id
            JOIN product_lines pl ON dt.product_line_id = pl.id
            WHERE so.day_of_week {$dayClause['sql']}
            ORDER BY so.customer_id, so.product_id
        ");
        $stmt->execute($dayClause['values']);
        $standingOrders = $stmt->fetchAll();

        $standingRouteByCustomer = [];
        if ($assignRoutes && table_exists($db, 'standing_routes')) {
            // Load the recurring driver plan once. A customer must have at
            // most one standing-route driver for a weekday; otherwise there
            // is no safe way to decide which driver is correct.
            $routeStmt = $db->prepare("
                SELECT sr.customer_id, sr.driver_id, sr.route_order
                FROM standing_routes sr
                JOIN customers c ON c.id = sr.customer_id AND c.is_active = 1
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                WHERE sr.day_of_week {$dayClause['sql']}
                ORDER BY sr.driver_id, COALESCE(sr.route_order, 2147483647), sr.id
            ");
            $routeStmt->execute($dayClause['values']);
            $nextRouteOrderByDriver = [];
            foreach ($routeStmt->fetchAll() as $route) {
                $customerId = (int)$route['customer_id'];
                $driverId = (int)$route['driver_id'];
                if (isset($standingRouteByCustomer[$customerId])) {
                    // During the Sunday 0 -> 7 migration, the same stop can
                    // temporarily exist under both encodings. Treat an exact
                    // driver duplicate as one stop, but reject real conflicts.
                    if ((int)$standingRouteByCustomer[$customerId]['driver_id'] !== $driverId) {
                        throw new Exception("Customer ID $customerId has more than one standing-route driver for this weekday");
                    }
                    continue;
                }
                $routeOrder = (int)($route['route_order'] ?? 0);
                if ($routeOrder <= 0) {
                    $routeOrder = ($nextRouteOrderByDriver[$driverId] ?? 0) + 1;
                }
                $nextRouteOrderByDriver[$driverId] = max(
                    $nextRouteOrderByDriver[$driverId] ?? 0,
                    $routeOrder
                );
                $standingRouteByCustomer[$customerId] = [
                    'driver_id' => $driverId,
                    'route_order' => $routeOrder,
                ];
            }
        }

        // Older installations of daily_order_assignments do not have
        // the optional notes column. Keep generation compatible with
        // those schemas while preserving notes where the column exists.
        $assignmentHasNotes = (bool)$db->query(
            "SHOW COLUMNS FROM daily_order_assignments LIKE 'notes'"
        )->fetch();
        $assignmentNotesSelect = $assignmentHasNotes ? ', notes' : '';
        $assignmentNotesInsert = $assignmentHasNotes ? ', notes' : '';
        $assignmentNotesPlaceholder = $assignmentHasNotes ? ', ?' : '';

        $existingAssignmentStmt = $db->prepare("
            SELECT id, driver_id, route_order, scheduled_delivery_time, actual_delivery_time,
                   estimated_delivery_time, delivery_status{$assignmentNotesSelect}
            FROM daily_order_assignments
            WHERE daily_order_id = ? AND delivery_date = ?
            ORDER BY id
        ");
        $insertAssignmentStmt = $db->prepare("
            INSERT INTO daily_order_assignments (
                daily_order_id, driver_id, delivery_date, scheduled_delivery_time,
                actual_delivery_time, route_order, estimated_delivery_time,
                delivery_status{$assignmentNotesInsert}
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?{$assignmentNotesPlaceholder})
        ");
        $updateLegacyDriverStmt = $db->prepare("
            UPDATE daily_orders SET driver_id = ? WHERE id = ?
        ");

        $ordersCreated = 0;
        $itemsCreated = 0;
        $itemsUpdated = 0;
        $itemsPreserved = 0;
        $driversAssigned = 0;
        $routesPreserved = 0;
        $ordersWithoutRoute = 0;
        $standingRoutePlaced = [];
        $maxRouteOrderByDriver = [];
        $usedRouteOrdersByDriver = [];
        if ($assignRoutes) {
            $datedRouteStmt = $db->prepare('
                SELECT driver_id, route_order
                FROM daily_order_assignments
                WHERE delivery_date = ?
                ORDER BY driver_id, route_order, id
            ');
            $datedRouteStmt->execute([$date]);
            foreach ($datedRouteStmt->fetchAll(PDO::FETCH_ASSOC) as $datedRoute) {
                $driverId = (int)$datedRoute['driver_id'];
                $routeOrder = max(0, (int)$datedRoute['route_order']);
                $maxRouteOrderByDriver[$driverId] = max($maxRouteOrderByDriver[$driverId] ?? 0, $routeOrder);
                if ($routeOrder > 0) {
                    $usedRouteOrdersByDriver[$driverId][$routeOrder] = true;
                }
            }
        }
        $existingItemQtyStmt = $db->prepare("
            SELECT product_id, quantity
            FROM daily_order_items
            WHERE daily_order_id = ?
        ");

        if (count($standingOrders) > 0) {
            // Group orders by customer for batch processing
            $customerOrders = [];
            foreach ($standingOrders as $order) {
                $customerId = $order['customer_id'];
                if (!isset($customerOrders[$customerId])) {
                    $customerOrders[$customerId] = [];
                }
                $customerOrders[$customerId][] = $order;
            }

            // Process each customer's orders
            foreach ($customerOrders as $customerId => $orders) {
                if ($onlyCustomerId > 0 && (int)$customerId !== $onlyCustomerId) {
                    continue;
                }
                $orderWeekStart = bakery_week_start_monday($date);
                if (bakery_customer_week_is_paused($db, $customerId, $orderWeekStart)) {
                    continue;
                }
                if (bakery_customer_delivery_is_skipped($db, $customerId, $date)) {
                    continue;
                }
                if (bakery_customer_delivery_in_pause_range($db, $customerId, $date)) {
                    continue;
                }

                $existedStmt = $db->prepare(
                    'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
                );
                $existedStmt->execute([$customerId, $date]);
                $existedBefore = (int)$existedStmt->fetchColumn() > 0;

                $dailyOrderId = bakery_daily_order_find_or_create($db, (int)$customerId, $date);
                if (!$existedBefore) {
                    $ordersCreated++;
                }

                if ($dailyOrderId) {
                    $fillBlocked = $existedBefore
                        && !$overwriteChanged
                        && bakery_daily_order_standing_fill_is_blocked($db, (int)$dailyOrderId, $standingFillColumn);
                    if ($existedBefore && $overwriteChanged) {
                        bakery_daily_order_set_standing_fill_blocked($db, (int)$dailyOrderId, false, $standingFillColumn);
                    }
                    if (!$fillBlocked) {
                    $existingItemQtyStmt->execute([$dailyOrderId]);
                    $existingQtyByProduct = [];
                    foreach ($existingItemQtyStmt->fetchAll(PDO::FETCH_ASSOC) as $existingItem) {
                        $existingQtyByProduct[(int)$existingItem['product_id']] = (int)$existingItem['quantity'];
                    }

                    // Prepare batch insert for items that should be written
                    $itemValues = [];
                    $itemParams = [];

                    foreach ($orders as $order) {
                        $productId = (int)$order['product_id'];
                        $standingQty = (int)$order['quantity'];
                        if (isset($existingQtyByProduct[$productId])) {
                            if ($existingQtyByProduct[$productId] !== $standingQty && !$overwriteChanged) {
                                // Keep the manager's dated change, including a
                                // one-day removal stored as quantity 0.
                                $itemsPreserved++;
                                continue;
                            }
                        }

                        // Determine the unit price based on product line and customer pricing
                        $unitPrice = bakery_resolve_customer_price($db, [
                            'id' => (int)$order['customer_id'],
                            'pricing_tier' => $order['pricing_tier'] ?? 'retail',
                            'default_pan_dulce_price' => $order['default_pan_dulce_price'] ?? null,
                        ], [
                            'id' => $productId,
                            'price' => floatval($order['price'] ?? 0),
                            'wholesale_price' => $order['wholesale_price'] ?? null,
                            'product_line_name' => $order['product_line_name'] ?? '',
                        ]);

                        $lineTotal = $standingQty * $unitPrice;

                        $itemValues[] = "(?, ?, ?, ?, ?)";
                        $itemParams[] = $dailyOrderId;
                        $itemParams[] = $productId;
                        $itemParams[] = $standingQty;
                        $itemParams[] = $unitPrice;
                        $itemParams[] = $lineTotal;

                        if (isset($existingQtyByProduct[$productId])) {
                            $itemsUpdated++;
                        } else {
                            $itemsCreated++;
                        }
                    }

                    if (!empty($itemValues)) {
                        $sql = "
                            INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
                            VALUES " . implode(', ', $itemValues) . "
                            ON DUPLICATE KEY UPDATE
                            quantity = VALUES(quantity),
                            unit_price = VALUES(unit_price),
                            line_total = VALUES(line_total)
                        ";

                        $stmt = $db->prepare($sql);
                        $stmt->execute($itemParams);
                    }

                    // Update order total efficiently
                    bakery_daily_order_recompute_total($db, (int)$dailyOrderId);
                    }

                    $standingRoute = $standingRouteByCustomer[(int)$customerId] ?? null;
                    if ($standingRoute) {
                        // A dated route is an operational decision. Re-running
                        // demand generation must never move or reorder an
                        // existing stop back to its standing-route position.
                        $existingAssignmentStmt->execute([$dailyOrderId, $date]);
                        $existingAssignments = $existingAssignmentStmt->fetchAll();
                        if ($existingAssignments !== []) {
                            $datedDriverId = (int)$existingAssignments[0]['driver_id'];
                            $updateLegacyDriverStmt->execute([$datedDriverId, $dailyOrderId]);
                            $routesPreserved++;
                            $standingRoutePlaced[(int)$customerId] = true;
                            continue;
                        }
                        if (isset($removedCustomerIds[(int)$customerId])) {
                            $standingRoutePlaced[(int)$customerId] = true;
                            continue;
                        }

                        $driverId = (int)$standingRoute['driver_id'];
                        $routeOrder = bakery_next_standing_route_order(
                            $driverId,
                            (int)$standingRoute['route_order'],
                            $maxRouteOrderByDriver,
                            $usedRouteOrdersByDriver
                        );

                        $assignmentParams = [
                            $dailyOrderId,
                            $driverId,
                            $date,
                            null,
                            null,
                            $routeOrder,
                            null,
                            'pending',
                        ];
                        if ($assignmentHasNotes) {
                            $assignmentParams[] = null;
                        }
                        // driver_assignments.php loads this file, so the helper is required here.
                        if (!function_exists('bakery_route_stop_restore_removed_day')) {
                            require_once __DIR__ . '/driver_assignments.php';
                        }
                        bakery_route_stop_restore_removed_day($db, (int)$dailyOrderId);
                        $insertAssignmentStmt->execute($assignmentParams);
                        // Keep the legacy column aligned for older views
                        // while daily_order_assignments remains canonical.
                        $updateLegacyDriverStmt->execute([
                            $driverId,
                            $dailyOrderId,
                        ]);
                        $driversAssigned++;
                        $standingRoutePlaced[(int)$customerId] = true;
                    } elseif ($assignRoutes) {
                        $ordersWithoutRoute++;
                    }
                }
            }
        }

        // Standing-route stops are the route plan. Customers with no standing
        // order (or whose order lines were filtered out of the product join)
        // still belong on the dated route. Existing assignments, including
        // cancelled ones, are left alone. A recorded manager removal stays
        // off. An untracked hard delete leaves no record, so this pass copies
        // that stop again.
        if ($assignRoutes && $standingRouteByCustomer !== []) {
            $routeWeekStart = bakery_week_start_monday($date);
            $routeOrderExists = $db->prepare(
                'SELECT id FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
            );
            foreach ($standingRouteByCustomer as $routeCustomerId => $standingRoute) {
                $routeCustomerId = (int)$routeCustomerId;
                if (isset($standingRoutePlaced[$routeCustomerId])) {
                    continue;
                }
                if (isset($removedCustomerIds[$routeCustomerId])) {
                    continue;
                }
                if (bakery_customer_week_is_paused($db, $routeCustomerId, $routeWeekStart)
                    || bakery_customer_delivery_is_skipped($db, $routeCustomerId, $date)
                    || bakery_customer_delivery_in_pause_range($db, $routeCustomerId, $date)
                ) {
                    continue;
                }

                $routeOrderExists->execute([$routeCustomerId, $date]);
                $routeExistedBefore = (int)$routeOrderExists->fetchColumn() > 0;
                $dailyOrderId = bakery_daily_order_find_or_create($db, $routeCustomerId, $date);
                if (!$routeExistedBefore) {
                    $ordersCreated++;
                }

                $existingAssignmentStmt->execute([$dailyOrderId, $date]);
                $existingAssignments = $existingAssignmentStmt->fetchAll();
                if ($existingAssignments !== []) {
                    $datedDriverId = (int)$existingAssignments[0]['driver_id'];
                    $updateLegacyDriverStmt->execute([$datedDriverId, $dailyOrderId]);
                    $routesPreserved++;
                    continue;
                }

                $driverId = (int)$standingRoute['driver_id'];
                $routeOrder = bakery_next_standing_route_order(
                    $driverId,
                    (int)$standingRoute['route_order'],
                    $maxRouteOrderByDriver,
                    $usedRouteOrdersByDriver
                );
                $assignmentParams = [
                    $dailyOrderId,
                    $driverId,
                    $date,
                    null,
                    null,
                    $routeOrder,
                    null,
                    'pending',
                ];
                if ($assignmentHasNotes) {
                    $assignmentParams[] = null;
                }
                // driver_assignments.php loads this file, so the helper is required here.
                if (!function_exists('bakery_route_stop_restore_removed_day')) {
                    require_once __DIR__ . '/driver_assignments.php';
                }
                bakery_route_stop_restore_removed_day($db, (int)$dailyOrderId);
                $insertAssignmentStmt->execute($assignmentParams);
                $updateLegacyDriverStmt->execute([$driverId, $dailyOrderId]);
                $driversAssigned++;
            }
        }

        $db->commit();

        if ($assignRoutes) {
            bakery_dated_route_morning_capture($db, $date);
        }

        if ($recordEvent) {
            bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_GENERATED, 'Generated daily orders from standing for ' . date('l, F j, Y', strtotime($date)), [
                'operational_date' => $date,
                'metadata' => [
                    'orders_created' => $ordersCreated,
                    'items_created' => $itemsCreated,
                    'items_updated' => $itemsUpdated,
                    'items_preserved' => $itemsPreserved,
                    'overwrite_changed' => $overwriteChanged,
                    'drivers_assigned' => $driversAssigned,
                    'routes_preserved' => $routesPreserved,
                ],
            ]);
        }

        $message = "Generated $ordersCreated new orders; wrote $itemsCreated new item(s) and updated $itemsUpdated item(s); assigned $driversAssigned driver route(s) for " . date('l, F j, Y', strtotime($date));
        if ($itemsPreserved > 0) {
            $message .= ". Preserved $itemsPreserved dated quantity change(s) that differed from standing.";
        }
        if (!$overwriteChanged && $itemsPreserved === 0) {
            $message .= '. Dated quantities that already matched standing were refreshed where needed.';
        }
        if ($overwriteChanged) {
            $message .= ' Overwrite mode was enabled for quantities that differed from standing.';
        }
        if ($routesPreserved > 0) {
            $message .= " Preserved $routesPreserved dated route stop(s).";
        }

        return [
            'db_day' => $dbDayOfWeek,
            'standing_rows' => count($standingOrders),
            'orders_created' => $ordersCreated,
            'items_created' => $itemsCreated,
            'items_updated' => $itemsUpdated,
            'items_preserved' => $itemsPreserved,
            'overwrite_changed' => $overwriteChanged,
            'drivers_assigned' => $driversAssigned,
            'routes_preserved' => $routesPreserved,
            'orders_without_route' => $ordersWithoutRoute,
            'message' => $message,
        ];
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error generating orders: " . $e->getMessage());
        throw new Exception('Failed to generate orders: ' . $e->getMessage());
    }
}

/**
 * Generate dated orders for the full Monday–Sunday week containing $date.
 * Each date is generated in its own transaction; a failure stops the run
 * and reports which date failed (earlier dates remain committed).
 *
 * @param array $options Same options as bakery_generate_daily_orders_from_standing().
 */
function bakery_generate_daily_orders_week(PDO $db, string $date, array $options = []): array
{
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new Exception('Invalid order date');
    }

    $weekStart = bakery_week_start_monday($date);
    $totals = [
        'orders_created' => 0,
        'items_created' => 0,
        'items_updated' => 0,
        'items_preserved' => 0,
        'drivers_assigned' => 0,
        'routes_preserved' => 0,
        'orders_without_route' => 0,
    ];
    $daysGenerated = 0;

    for ($i = 0; $i < 7; $i++) {
        $day = date('Y-m-d', strtotime($weekStart . " +{$i} days"));
        try {
            $result = bakery_generate_daily_orders_from_standing($db, $day, $options);
        } catch (Exception $e) {
            throw new Exception(
                "Week generation stopped on " . date('l, F j', strtotime($day)) .
                " after $daysGenerated day(s): " . $e->getMessage()
            );
        }
        $daysGenerated++;
        foreach ($totals as $key => $value) {
            $totals[$key] += (int)($result[$key] ?? 0);
        }
    }

    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $message = "Generated orders for the week of " . date('M j', strtotime($weekStart))
        . ' ' . bakery_locale_range_word() . ' ' . date('M j', strtotime($weekEnd)) . ": "
        . $totals['orders_created'] . " new orders, "
        . $totals['items_created'] . " new item(s), "
        . $totals['items_updated'] . " updated item(s), "
        . $totals['drivers_assigned'] . " driver route(s) assigned.";
    if ($totals['items_preserved'] > 0) {
        $message .= " Preserved " . $totals['items_preserved'] . " dated quantity change(s).";
    }
    if ($totals['routes_preserved'] > 0) {
        $message .= " Preserved " . $totals['routes_preserved'] . " dated route stop(s).";
    }

    return [
        'week_start' => $weekStart,
        'week_end' => $weekEnd,
        'days_generated' => $daysGenerated,
        'overwrite_changed' => !empty($options['overwrite_changed']),
        'message' => $message,
    ] + $totals;
}

/**
 * Lazy auto-generate dated orders for one operating date when standing
 * demand exists but dated commercial orders are still missing, including
 * already-generated orders that lack newly added standing product lines.
 *
 * Callers are the demand scheduler cron and an explicit manager action.
 * Page views must not call this. Always preserves dated quantity edits
 * (overwrite_changed is forced off). Skips closed days, skips orders a
 * manager emptied on purpose, and no-ops when generation is already complete.
 *
 * @param array{record_event?:bool, assign_routes?:bool, skip_if_closed?:bool} $options
 * @return array{ran:bool, skipped_reason:?string, result:?array}
 */
function bakery_ensure_daily_orders_for_date(PDO $db, string $date, array $options = []): array
{
    $recordEvent = !array_key_exists('record_event', $options) || !empty($options['record_event']);
    $assignRoutes = !array_key_exists('assign_routes', $options) || !empty($options['assign_routes']);
    $skipIfClosed = !array_key_exists('skip_if_closed', $options) || !empty($options['skip_if_closed']);

    $noop = static function (?string $reason): array {
        return ['ran' => false, 'skipped_reason' => $reason, 'result' => null];
    };

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        return $noop('invalid_date');
    }

    if (!table_exists($db, 'daily_orders') || !table_exists($db, 'standing_orders')) {
        return $noop('unavailable');
    }

    if ($skipIfClosed && table_exists($db, 'operating_day_closeouts')) {
        try {
            $closeStmt = $db->prepare('
                SELECT closed_at, reopened_at
                FROM operating_day_closeouts
                WHERE operating_date = ?
                LIMIT 1
            ');
            $closeStmt->execute([$date]);
            $closeRow = $closeStmt->fetch(PDO::FETCH_ASSOC);
            if ($closeRow && !empty($closeRow['closed_at']) && empty($closeRow['reopened_at'])) {
                return $noop('day_closed');
            }
        } catch (Throwable $e) {
            error_log('ensure daily orders closeout check: ' . $e->getMessage());
        }
    }

    if (!function_exists('bakery_demand_review_build')) {
        require_once __DIR__ . '/demand_review.php';
    }

    try {
        $review = bakery_demand_review_build($db, $date, []);
    } catch (Throwable $e) {
        error_log('ensure daily orders review: ' . $e->getMessage());
        return $noop('review_failed');
    }

    $summary = $review['summary'] ?? [];
    $expected = (int)($summary['expected_customers'] ?? 0);
    $withDaily = (int)($summary['customers_with_daily'] ?? 0);
    $missing = (int)($summary['missing_daily'] ?? 0);
    $missingStandingLines = (int)($summary['missing_standing_lines'] ?? 0);
    $missingRoutes = $assignRoutes && bakery_date_has_unassigned_standing_route_stops($db, $date);
    $needsGeneration = $missing > 0
        || $missingStandingLines > 0
        || ($expected > 0 && $withDaily === 0)
        || $missingRoutes;

    if (!$needsGeneration) {
        return $noop('already_generated');
    }

    $result = bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => false,
        'record_event' => $recordEvent,
        'assign_routes' => $assignRoutes,
    ]);

    return [
        'ran' => true,
        'skipped_reason' => null,
        'result' => $result,
    ];
}

/**
 * Rolling days of dated demand to keep materialized from standing orders.
 * Seven days covers Monday planning → Tuesday bake → Wednesday route
 * and the rest of the operating week without a daily Generate click.
 */
function bakery_demand_horizon_days(): int
{
    return 7;
}

/**
 * Default demand lookahead from calendar day D: tomorrow's bake sheet
 * (D+1) is keyed on the next route date (D+2). That matches mid-week
 * pan dulce (bake the day before delivery). Friday's pan dulce bake
 * covers Saturday, Sunday, and Monday; Sour Flour bakes Tuesday/Friday
 * plus Sunday-for-Monday — see includes/production_cadence.php.
 * Production.php stays keyed on the delivery date.
 *
 * @return array{from_date:string,bake_day:string,route_date:string,production_date:string,horizon_end:string}
 */
function bakery_demand_cadence_dates(string $fromDate): array
{
    $from = DateTime::createFromFormat('!Y-m-d', $fromDate);
    if (!$from || $from->format('Y-m-d') !== $fromDate) {
        $fromDate = date('Y-m-d');
        $from = new DateTime($fromDate);
    }
    $bake = clone $from;
    $bake->modify('+1 day');
    $route = clone $from;
    $route->modify('+2 days');
    $horizonEnd = clone $from;
    $horizonEnd->modify('+' . (bakery_demand_horizon_days() - 1) . ' days');

    return [
        'from_date' => $fromDate,
        'bake_day' => $bake->format('Y-m-d'),
        'route_date' => $route->format('Y-m-d'),
        'production_date' => $route->format('Y-m-d'),
        'horizon_end' => $horizonEnd->format('Y-m-d'),
    ];
}

/**
 * @return list<string>
 */
function bakery_demand_horizon_date_list(string $fromDate, ?int $days = null): array
{
    $from = DateTime::createFromFormat('!Y-m-d', $fromDate);
    if (!$from || $from->format('Y-m-d') !== $fromDate) {
        return [];
    }
    $days = $days ?? bakery_demand_horizon_days();
    if ($days < 1) {
        $days = 1;
    }
    if ($days > 14) {
        $days = 14;
    }
    $dates = [];
    for ($i = 0; $i < $days; $i++) {
        $day = clone $from;
        if ($i > 0) {
            $day->modify('+' . $i . ' days');
        }
        $dates[] = $day->format('Y-m-d');
    }
    return $dates;
}

/**
 * Fill dated orders from standing for a rolling horizon. Always preserves
 * dated quantity and route edits. Closed days are skipped.
 *
 * @param array{record_event?:bool, assign_routes?:bool, skip_if_closed?:bool, days?:int} $options
 * @return array{from_date:string, through_date:string, days:int, generated:list<array>, skipped:list<array>,
 *               orders_created:int, items_created:int, items_updated:int, items_preserved:int,
 *               drivers_assigned:int, ran_count:int}
 */
function bakery_ensure_daily_orders_horizon(PDO $db, string $fromDate, array $options = []): array
{
    $days = isset($options['days']) ? (int)$options['days'] : bakery_demand_horizon_days();
    $dates = bakery_demand_horizon_date_list($fromDate, $days);
    $empty = [
        'from_date' => $fromDate,
        'through_date' => $dates ? $dates[count($dates) - 1] : $fromDate,
        'days' => count($dates),
        'generated' => [],
        'skipped' => [],
        'orders_created' => 0,
        'items_created' => 0,
        'items_updated' => 0,
        'items_preserved' => 0,
        'drivers_assigned' => 0,
        'ran_count' => 0,
    ];
    if ($dates === []) {
        return $empty;
    }

    $ensureOptions = [
        'record_event' => !array_key_exists('record_event', $options) || !empty($options['record_event']),
        'assign_routes' => !array_key_exists('assign_routes', $options) || !empty($options['assign_routes']),
        'skip_if_closed' => !array_key_exists('skip_if_closed', $options) || !empty($options['skip_if_closed']),
    ];

    foreach ($dates as $date) {
        try {
            $ensure = bakery_ensure_daily_orders_for_date($db, $date, $ensureOptions);
        } catch (Throwable $e) {
            error_log('demand horizon ' . $date . ': ' . $e->getMessage());
            $empty['skipped'][] = ['date' => $date, 'reason' => 'error'];
            continue;
        }
        if (!empty($ensure['ran']) && is_array($ensure['result'] ?? null)) {
            $empty['generated'][] = ['date' => $date, 'result' => $ensure['result']];
            $empty['ran_count']++;
            $empty['orders_created'] += (int)($ensure['result']['orders_created'] ?? 0);
            $empty['items_created'] += (int)($ensure['result']['items_created'] ?? 0);
            $empty['items_updated'] += (int)($ensure['result']['items_updated'] ?? 0);
            $empty['items_preserved'] += (int)($ensure['result']['items_preserved'] ?? 0);
            $empty['drivers_assigned'] += (int)($ensure['result']['drivers_assigned'] ?? 0);
        } else {
            $empty['skipped'][] = [
                'date' => $date,
                'reason' => (string)($ensure['skipped_reason'] ?? 'unknown'),
            ];
        }
    }

    return $empty;
}

/**
 * Keep the rolling horizon full from today, and also the date a screen is
 * looking at when that date sits outside the window.
 *
 * @return array Horizon result (see bakery_ensure_daily_orders_horizon).
 */
function bakery_fill_demand_horizon(PDO $db, ?string $alsoDate = null, array $options = []): array
{
    $today = date('Y-m-d');
    $horizon = bakery_ensure_daily_orders_horizon($db, $today, $options);
    if ($alsoDate === null || $alsoDate === '') {
        return $horizon;
    }
    $alsoObject = DateTime::createFromFormat('!Y-m-d', $alsoDate);
    if (!$alsoObject || $alsoObject->format('Y-m-d') !== $alsoDate) {
        return $horizon;
    }
    if ($alsoDate >= $horizon['from_date'] && $alsoDate <= $horizon['through_date']) {
        return $horizon;
    }
    try {
        $one = bakery_ensure_daily_orders_for_date($db, $alsoDate, $options);
    } catch (Throwable $e) {
        error_log('demand horizon extra date ' . $alsoDate . ': ' . $e->getMessage());
        return $horizon;
    }
    if (!empty($one['ran']) && is_array($one['result'] ?? null)) {
        $horizon['generated'][] = ['date' => $alsoDate, 'result' => $one['result']];
        $horizon['ran_count']++;
        $horizon['orders_created'] += (int)($one['result']['orders_created'] ?? 0);
        $horizon['items_created'] += (int)($one['result']['items_created'] ?? 0);
        $horizon['items_updated'] += (int)($one['result']['items_updated'] ?? 0);
        $horizon['items_preserved'] += (int)($one['result']['items_preserved'] ?? 0);
        $horizon['drivers_assigned'] += (int)($one['result']['drivers_assigned'] ?? 0);
    }
    return $horizon;
}

/**
 * Manager POST: build one date from standing. CSRF and manager role required.
 * No-ops unless the request is that action. Redirects back to the current page.
 */
function bakery_try_build_from_standing_post(PDO $db): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }
    if ((string)($_POST['action'] ?? '') !== 'build_from_standing') {
        return;
    }
    bakery_require_role(['administrator', 'manager']);
    bakery_require_csrf();
    $date = trim((string)($_POST['date'] ?? ''));
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Invalid order date');
    }
    $result = bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => false,
        'record_event' => true,
    ]);
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $message = function_exists('bakery_t')
        ? bakery_t('orders.built_from_standing', [
            'date' => $date,
            'orders' => (int)($result['orders_created'] ?? 0),
        ])
        : 'Built dated orders from standing.';
    $query = $_GET;
    $query['date'] = $date;
    $query['flash'] = 'success';
    $query['msg'] = $message;
    safe_redirect($script . '?' . http_build_query($query));
}

/**
 * Explicit Build from standing control. Empty for anyone who is not a manager.
 */
function bakery_build_from_standing_button(string $date, string $buttonClass = 'btn btn-primary'): string
{
    if (!function_exists('bakery_user_has_role') || !bakery_user_has_role(['administrator', 'manager'])) {
        return '';
    }
    $label = function_exists('bakery_t') ? bakery_t('ux.daily_orders.generate_standing') : 'Build from standing';
    $csrf = function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
    return '<form method="post" action="" class="build-from-standing-form" style="display:inline-flex;margin:0;align-items:center">'
        . $csrf
        . '<input type="hidden" name="action" value="build_from_standing">'
        . '<input type="hidden" name="date" value="' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="' . htmlspecialchars($buttonClass, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

/**
 * CLI cron guard. Unforced runs are DreamHost production (bakerysf) or
 * DreamHost staging. Local one-shots need --force. Laptop-to-prod is refused.
 */
function bakery_demand_scheduler_assert_cli(PDO $db, bool $force = false): void
{
    $name = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    $isLocal = defined('IS_LOCAL') && IS_LOCAL;
    $isStaging = defined('IS_STAGING') && IS_STAGING;
    $useProdDb = defined('USE_PROD_DB') && USE_PROD_DB;

    if ($isLocal && $useProdDb) {
        throw new RuntimeException(
            'Demand scheduler cron runs on DreamHost. Do not generate production demand from this machine.'
        );
    }

    if ($isLocal) {
        if (!$force) {
            throw new RuntimeException(
                'Demand scheduler cron is DreamHost-only. Use Build from standing, or pass --force for a one-shot.'
            );
        }
        return;
    }

    if ($isStaging && $name === 'bakerysoftware') {
        return;
    }

    if ($name !== 'bakerysf') {
        throw new RuntimeException('Demand scheduler cron requires database bakerysf, got ' . $name . '.');
    }
}

/**
 * 3:00 AM America/Los_Angeles is the first moment a missed 2:30 AM build may run.
 */
function bakery_demand_self_heal_window_open(DateTimeImmutable $now): bool
{
    $pt = $now->setTimezone(bakery_cron_timezone());
    return $pt >= $pt->setTime(3, 0, 0);
}

function bakery_demand_self_heal_sell_day(DateTimeImmutable $now): string
{
    return $now->setTimezone(bakery_cron_timezone())->format('Y-m-d');
}

function bakery_demand_self_heal_recorded(PDO $db, string $sellDay): bool
{
    $success = bakery_cron_last_successful_run('demand_scheduler', $db);
    if (!$success) {
        return false;
    }
    $last = trim((string)($success['last_run_at'] ?? ''));
    if ($last === '') {
        return false;
    }
    try {
        $day = (new DateTimeImmutable($last))->setTimezone(bakery_cron_timezone())->format('Y-m-d');
    } catch (Throwable $e) {
        return false;
    }
    return $day === $sellDay;
}

/**
 * @return array{ok:bool,ran:bool,reason:string,source:string,sell_day:string,orders_created:int}
 */
function bakery_demand_self_heal_result(bool $ran, string $reason, string $sellDay, int $ordersCreated = 0): array
{
    return [
        'ok' => true,
        'ran' => $ran,
        'reason' => $reason,
        'source' => 'self_heal',
        'sell_day' => $sellDay,
        'orders_created' => $ordersCreated,
    ];
}

function bakery_demand_self_heal_acquire(PDO $db): bool
{
    try {
        $got = $db->query("SELECT GET_LOCK('bakery_demand_self_heal', 2)")->fetchColumn();
    } catch (Throwable $e) {
        error_log('demand self heal lock: ' . $e->getMessage());
        return false;
    }
    return (string)$got === '1';
}

function bakery_demand_self_heal_release(PDO $db): void
{
    try {
        $db->query("SELECT RELEASE_LOCK('bakery_demand_self_heal')");
    } catch (Throwable $e) {
        error_log('demand self heal unlock: ' . $e->getMessage());
    }
}

/**
 * Same horizon fill as scripts/demand_scheduler.php, for one sell day.
 * Fills customers with no dated order. Does not overwrite dated edits,
 * one-time skips, or closed days. A second call on a sell day that already
 * has a successful demand_scheduler cron_run is a no-op.
 *
 * pan_carry.php requires this file, so it is loaded only when a build runs.
 *
 * @return array{ok:bool,ran:bool,reason:string,source:string,sell_day:string,orders_created:int}
 */
function bakery_demand_self_heal_if_stale(PDO $db, ?DateTimeImmutable $now = null): array
{
    $now = $now ?? bakery_cron_now();
    $sellDay = bakery_demand_self_heal_sell_day($now);
    if (!bakery_demand_self_heal_window_open($now)) {
        return bakery_demand_self_heal_result(false, 'before_window', $sellDay);
    }
    if (bakery_demand_self_heal_recorded($db, $sellDay)) {
        return bakery_demand_self_heal_result(false, 'already_built', $sellDay);
    }
    if (!bakery_demand_self_heal_acquire($db)) {
        return bakery_demand_self_heal_result(false, 'locked', $sellDay);
    }
    try {
        if (bakery_demand_self_heal_recorded($db, $sellDay)) {
            return bakery_demand_self_heal_result(false, 'already_built', $sellDay);
        }
        require_once __DIR__ . '/pan_carry.php';
        $result = bakery_ensure_daily_orders_horizon($db, $sellDay, [
            'record_event' => true,
            'assign_routes' => true,
            'skip_if_closed' => true,
        ]);
        $carry = ['carried' => 0, 'deduped' => 0, 'restored' => 0, 'skipped' => 0];
        foreach (bakery_pan_carry_forward_recent($db, $sellDay) as $dayCounts) {
            if (!is_array($dayCounts)) {
                continue;
            }
            foreach ($carry as $key => $value) {
                $carry[$key] = $value + (int)($dayCounts[$key] ?? 0);
            }
        }
        $routeStats = bakery_route_load_rebuild($db);
        $ordersCreated = (int)($result['orders_created'] ?? 0);
        bakery_cron_record_run($db, 'demand_scheduler', 'ok', [
            'source' => 'self_heal',
            'from_date' => $result['from_date'] ?? null,
            'through_date' => $result['through_date'] ?? null,
            'ran_count' => (int)($result['ran_count'] ?? 0),
            'orders_created' => $ordersCreated,
            'carried' => (int)$carry['carried'],
            'restored' => (int)$carry['restored'],
            'route_service_stats' => (int)($routeStats['service'] ?? 0),
            'route_drive_stats' => (int)($routeStats['drive'] ?? 0),
        ], $now);
        return bakery_demand_self_heal_result(true, '', $sellDay, $ordersCreated);
    } catch (Throwable $e) {
        try {
            bakery_cron_record_run($db, 'demand_scheduler', 'failed', [
                'source' => 'self_heal',
                'error' => substr($e->getMessage(), 0, 200),
            ], $now);
        } catch (Throwable $inner) {
            error_log('demand self heal failed stamp: ' . $inner->getMessage());
        }
        throw $e;
    } finally {
        bakery_demand_self_heal_release($db);
    }
}

/**
 * HTTP entry for demand_self_heal.php. GET never writes. POST builds or no-ops.
 *
 * @return array{status:int,body:array{ok:bool,ran:bool,reason:string,source:string,sell_day:string,orders_created:int}}
 */
function bakery_demand_self_heal_dispatch(PDO $db, ?DateTimeImmutable $now = null): array
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        return [
            'status' => 405,
            'body' => bakery_demand_self_heal_result(false, 'method', ''),
        ];
    }
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $role = is_array($user) ? (string)($user['role_slug'] ?? '') : '';
    if (!is_array($user) || !in_array($role, bakery_demand_self_heal_roles(), true)) {
        return [
            'status' => 403,
            'body' => bakery_demand_self_heal_result(false, 'role', ''),
        ];
    }
    $body = bakery_demand_self_heal_if_stale($db, $now);
    $closed = bakery_route_autoclose_past($db, $now);
    $body['routes_auto_closed'] = (int)($closed['closed'] ?? 0);
    return [
        'status' => 200,
        'body' => $body,
    ];
}

/**
 * Same-origin POST beacon. Printed only for a staff role after 3:00 AM PT.
 * The page GET does not write. csrf.js attaches the token to fetch.
 */
function bakery_demand_self_heal_render_beacon(?DateTimeImmutable $now = null): void
{
    $user = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $role = is_array($user) ? (string)($user['role_slug'] ?? '') : '';
    if (!in_array($role, bakery_demand_self_heal_roles(), true)) {
        return;
    }
    $now = $now ?? bakery_cron_now();
    if (!bakery_demand_self_heal_window_open($now)) {
        return;
    }
    $endpoint = json_encode(
        (defined('BASE_URL') ? BASE_URL : '/') . 'demand_self_heal.php',
        JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    echo '<script>
document.addEventListener("DOMContentLoaded", function () {
  if (!window.fetch) return;
  fetch(' . $endpoint . ', {
    method: "POST",
    credentials: "same-origin",
    headers: { "Accept": "application/json", "Content-Type": "application/json" },
    body: "{}",
    keepalive: true
  }).then(function (response) {
    return response.json();
  }).then(function (body) {
    if (!body || body.ran !== true) return;
    window.location.reload();
  }).catch(function () {});
});
</script>' . "\n";
}
