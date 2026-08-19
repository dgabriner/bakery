<?php
/**
 * Daily order generation from standing orders — shared by Daily Orders,
 * Daily Run inline actions, the dashboard, and the CLI test harness.
 *
 * Inactive customers never generate orders. Dated quantity changes are
 * preserved unless overwrite mode is explicitly requested.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/customer_portal.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/operational_timeline.php';

/**
 * Generate dated daily orders from standing orders for one date.
 *
 * @param array{overwrite_changed?:bool, record_event?:bool, assign_routes?:bool} $options
 *   overwrite_changed — replace dated quantity edits with standing quantities (default false)
 *   record_event      — write an operational-timeline event (default true)
 *   assign_routes     — rebuild dated driver assignments from standing routes (default true)
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
    $db->beginTransaction();

    try {
        // Get all standing orders for this day in one efficient query.
        // Inactive customers are excluded so deactivation stops generation.
        $stmt = $db->prepare("
            SELECT so.customer_id, so.product_id, so.quantity,
                   COALESCE(p.price, 0) as price,
                   c.default_pan_dulce_price,
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
        $initialAssignmentsByDriver = [];
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
                $initialAssignmentsByDriver[$driverId] = true;
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

                // Create or get daily order for this customer/date
                $stmt = $db->prepare("
                    INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
                    VALUES (?, ?, 'pending', 0)
                ");
                $stmt->execute([$customerId, $date]);

                if ($stmt->rowCount() > 0) {
                    $ordersCreated++;
                }

                // Get the daily order ID
                $stmt = $db->prepare("
                    SELECT id FROM daily_orders
                    WHERE customer_id = ? AND order_date = ?
                ");
                $stmt->execute([$customerId, $date]);
                $dailyOrderId = $stmt->fetchColumn();

                if ($dailyOrderId) {
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
                                // Keep the manager's dated change instead of silently resetting it.
                                $itemsPreserved++;
                                continue;
                            }
                        }

                        // Determine the unit price based on product line and customer pricing
                        $unitPrice = floatval($order['price'] ?? 0);

                        // If this is a Pan Dulce product and customer has a custom price, use it
                        if ($order['product_line_name'] === 'Pan Dulce' &&
                            !empty($order['default_pan_dulce_price'])) {
                            $unitPrice = floatval($order['default_pan_dulce_price']);
                        }

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
                    $stmt = $db->prepare("
                        UPDATE daily_orders
                        SET total_amount = (
                            SELECT COALESCE(SUM(line_total), 0)
                            FROM daily_order_items
                            WHERE daily_order_id = ?
                        )
                        WHERE id = ?
                    ");
                    $stmt->execute([$dailyOrderId, $dailyOrderId]);

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
                            continue;
                        }

                        $driverId = (int)$standingRoute['driver_id'];
                        $standingRouteOrder = max(1, (int)$standingRoute['route_order']);
                        if (empty($initialAssignmentsByDriver[$driverId])
                            && empty($usedRouteOrdersByDriver[$driverId][$standingRouteOrder])) {
                            $routeOrder = $standingRouteOrder;
                        } else {
                            $routeOrder = ($maxRouteOrderByDriver[$driverId] ?? 0) + 1;
                        }
                        $maxRouteOrderByDriver[$driverId] = max($maxRouteOrderByDriver[$driverId] ?? 0, $routeOrder);
                        $usedRouteOrdersByDriver[$driverId][$routeOrder] = true;

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
                        $insertAssignmentStmt->execute($assignmentParams);
                        // Keep the legacy column aligned for older views
                        // while daily_order_assignments remains canonical.
                        $updateLegacyDriverStmt->execute([
                            $driverId,
                            $dailyOrderId,
                        ]);
                        $driversAssigned++;
                    } elseif ($assignRoutes) {
                        $ordersWithoutRoute++;
                    }
                }
            }
        }

        $db->commit();

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
        . " – " . date('M j', strtotime($weekEnd)) . ": "
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
 * demand exists but dated commercial orders are still missing.
 *
 * Intended for first Daily Run / Daily Orders page view so tomorrow's
 * demand is never a remembered manual click. Always preserves dated
 * quantity edits (overwrite_changed is forced off). Skips closed days
 * and no-ops when generation is already complete.
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
    $needsGeneration = $missing > 0 || ($expected > 0 && $withDaily === 0);

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
