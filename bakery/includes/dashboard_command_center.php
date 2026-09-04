<?php
/**
 * Manager Daily Command Center — exception-oriented ops snapshot for index.php.
 *
 * Distinguishes true zero / no applicable records / query unavailable.
 * Does not invent schema fields; derives exceptions from existing tables only.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_exceptions.php';
require_once __DIR__ . '/production_plan.php';
require_once __DIR__ . '/sfb_origin.php';

/**
 * Strip credentials / SQL internals from exception messages shown to managers.
 */
function bakery_dashboard_safe_error_message(Throwable $e): string
{
    $message = trim($e->getMessage());
    if ($message === '') {
        return 'A data lookup failed. Try refreshing, or contact an administrator if this continues.';
    }

    $lower = strtolower($message);
    $sensitive = ['password', 'passwd', 'credential', 'mysql', 'mariadb', 'pdo', 'sqlstate', 'access denied', 'dsn', 'host=', 'dbname'];
    foreach ($sensitive as $needle) {
        if (strpos($lower, $needle) !== false) {
            return 'A data lookup failed. Try refreshing, or contact an administrator if this continues.';
        }
    }

    // Keep messages short and non-technical.
    $message = preg_replace('/\s+/', ' ', $message);
    if (strlen($message) > 160) {
        $message = substr($message, 0, 157) . '...';
    }
    return $message;
}

/**
 * Cached column existence check (no schema changes).
 */
function bakery_dashboard_column_exists(PDO $db, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!table_exists($db, $table)) {
        return $cache[$key] = false;
    }
    try {
        $safe = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $column);
        $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $db->quote($safe));
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        error_log('dashboard column check: ' . $e->getMessage());
        $cache[$key] = false;
    }
    return $cache[$key];
}

/**
 * Metric cell: state is ready|empty|unavailable.
 * - ready: value is known (including true zero)
 * - empty: no applicable records for this stage (value typically 0)
 * - unavailable: query/feature failed or not installed
 */
function bakery_dashboard_metric($value, string $state = 'ready', ?string $note = null): array
{
    return [
        'value' => $value === null ? null : (int)$value,
        'state' => $state,
        'note' => $note,
    ];
}

function bakery_dashboard_week_start_monday(string $date): string
{
    if (function_exists('bakery_week_start_monday')) {
        return bakery_week_start_monday($date);
    }
    $ts = strtotime($date);
    $dow = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

/**
 * Build the full manager command-center payload for one operating date.
 *
 * @return array{
 *   date: string,
 *   weekday: int,
 *   stages: array,
 *   exceptions: array,
 *   section_errors: array<string, string>,
 *   has_blocking_error: bool
 * }
 */
function bakery_dashboard_command_center(PDO $db, string $date): array
{
    $weekday = bakery_standing_day_from_date($date);
    $weekStart = bakery_dashboard_week_start_monday($date);
    $base = defined('BASE_URL') ? BASE_URL : '';

    $sectionErrors = [];
    $exceptions = [];

    if (!function_exists('bakery_cron_is_stale')) {
        $cronFile = __DIR__ . '/cron_run.php';
        if (is_readable($cronFile)) {
            require_once $cronFile;
        }
    }
    if (function_exists('bakery_cron_is_stale') && bakery_cron_is_stale('demand_scheduler', 26.0)) {
        $ageHours = function_exists('bakery_cron_age_hours') ? bakery_cron_age_hours('demand_scheduler') : null;
        $ageLabel = $ageHours === null ? '?' : (string)(int)ceil($ageHours);
        $exceptions[] = bakery_ops_exception([
            'type' => 'cron_overnight_stale',
            'severity' => 'warning',
            'category' => 'demand',
            'title' => function_exists('bakery_t')
                ? (string)bakery_t('dashboard.cron_overnight_stale', ['hours' => $ageLabel], 'Overnight generation stale (:hours h)')
                : ('Overnight generation stale (' . $ageLabel . 'h)'),
            'detail' => 'demand_scheduler last run is missing or older than 26 hours. Check docs/CRON_KIT.md and health_deploy.php cron.*.age_hours.',
            'href' => (defined('BASE_URL') ? BASE_URL : '') . 'health_deploy.php',
            'action' => 'Verify overnight cron',
            'work_key' => 'cron-overnight-stale-' . $date,
        ]);
    }

    $links = [
        'daily_orders' => bakery_ops_link_daily_orders($date),
        'standing' => $base . 'standing_orders_manager.php',
        'production' => bakery_ops_link_production($date),
        'production_center' => bakery_ops_link_production_center($weekStart, ['date' => $date]),
        'pack' => bakery_ops_link_pack_list($date),
        'inventory' => bakery_ops_link_inventory($date),
        'driver_load' => bakery_ops_link_driver_load($date),
        'driver_assignment' => bakery_ops_link_driver_assignment($date),
        'driver_list' => bakery_ops_link_driver_list($date),
        'daily_route' => $base . 'daily_route.php?date=' . rawurlencode($date),
        'invoice' => bakery_ops_link_billing($date),
        'invoice_delivered' => bakery_ops_link_billing($date, ['status' => 'delivered']),
        'product_distribution' => $base . 'product_distribution.php?production_date=' . rawurlencode($date),
        'service_issues' => bakery_ops_link_service_issues(['status' => 'open']),
        'ingredient_planner' => bakery_ops_link_ingredient_planner($date),
    ];

    // --- Demand ---
    $demand = [
        'key' => 'demand',
        'label' => 'Demand',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['daily_orders'],
        'metrics' => [
            'daily_orders' => bakery_dashboard_metric(null, 'unavailable'),
            'customers' => bakery_dashboard_metric(null, 'unavailable'),
            'standing_customers' => bakery_dashboard_metric(null, 'unavailable'),
            'missing_daily' => bakery_dashboard_metric(null, 'unavailable'),
        ],
    ];

    $dailyOrderCount = null;
    $customersWithOrders = null;
    $standingCustomers = null;
    $missingDailyCustomers = null;
    $standingLines = null;

    try {
        if (!table_exists($db, 'daily_orders')) {
            $sectionErrors['demand'] = 'Daily orders are not available in this database.';
            $demand['summary'] = 'Not installed';
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE order_date = ?');
            $stmt->execute([$date]);
            $dailyOrderCount = (int)$stmt->fetchColumn();

            $stmt = $db->prepare('SELECT COUNT(DISTINCT customer_id) FROM daily_orders WHERE order_date = ?');
            $stmt->execute([$date]);
            $customersWithOrders = (int)$stmt->fetchColumn();

            $demand['metrics']['daily_orders'] = bakery_dashboard_metric($dailyOrderCount, 'ready');
            $demand['metrics']['customers'] = bakery_dashboard_metric($customersWithOrders, 'ready');

            if (table_exists($db, 'standing_orders')) {
                $dayClause = bakery_standing_day_in_clause($weekday);
                $pauseJoin = '';
                $pauseParams = [];
                if (table_exists($db, 'standing_order_pauses')) {
                    $pauseJoin = 'AND NOT EXISTS (
                        SELECT 1 FROM standing_order_pauses p
                        WHERE p.customer_id = so.customer_id AND p.week_start = ?
                    )';
                    $pauseParams = [$weekStart];
                }

                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT so.customer_id)
                    FROM standing_orders so
                    JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    WHERE so.quantity > 0 AND so.day_of_week {$dayClause['sql']}
                    {$pauseJoin}
                ");
                $stmt->execute(array_merge($dayClause['values'], $pauseParams));
                $standingCustomers = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM standing_orders so
                    JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    WHERE so.quantity > 0 AND so.day_of_week {$dayClause['sql']}
                    {$pauseJoin}
                ");
                $stmt->execute(array_merge($dayClause['values'], $pauseParams));
                $standingLines = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT so.customer_id)
                    FROM standing_orders so
                    JOIN customers c ON c.id = so.customer_id AND c.is_active = 1
                    " . bakery_sfb_ops_origin_clause('c', $db) . "
                    WHERE so.quantity > 0
                      AND so.day_of_week {$dayClause['sql']}
                      {$pauseJoin}
                      AND NOT EXISTS (
                          SELECT 1 FROM daily_orders do
                          WHERE do.customer_id = so.customer_id AND do.order_date = ?
                      )
                ");
                $stmt->execute(array_merge($dayClause['values'], $pauseParams, [$date]));
                $missingDailyCustomers = (int)$stmt->fetchColumn();

                $demand['metrics']['standing_customers'] = bakery_dashboard_metric($standingCustomers, 'ready');
                $demand['metrics']['missing_daily'] = bakery_dashboard_metric($missingDailyCustomers, 'ready');
            } else {
                $demand['metrics']['standing_customers'] = bakery_dashboard_metric(0, 'empty', 'No standing-order table');
                $demand['metrics']['missing_daily'] = bakery_dashboard_metric(0, 'empty', 'No standing-order table');
            }

            if ($missingDailyCustomers > 0) {
                $demand['state'] = 'attention';
                $demand['summary'] = $missingDailyCustomers . ' standing customer'
                    . ($missingDailyCustomers === 1 ? '' : 's') . ' without daily orders';
                $exceptions[] = bakery_ops_exception([
                    'type' => 'demand_missing_daily',
                    'severity' => 'critical',
                    'category' => 'demand',
                    'title' => 'Standing customers missing daily orders',
                    'detail' => $missingDailyCustomers . ' customer'
                        . ($missingDailyCustomers === 1 ? '' : 's')
                        . ' have standing demand for this weekday but no dated daily order.',
                    'count' => $missingDailyCustomers,
                    'href' => bakery_ops_link_daily_orders($date, ['review' => 'missing']),
                    'action' => 'Review missing orders',
                ]);
            } elseif ($dailyOrderCount === 0 && $standingLines > 0) {
                $demand['state'] = 'attention';
                $demand['summary'] = 'No daily orders yet · standing demand exists';
                $exceptions[] = bakery_ops_exception([
                    'type' => 'demand_no_orders',
                    'severity' => 'warning',
                    'category' => 'demand',
                    'title' => 'No daily orders for this date',
                    'detail' => 'There are ' . $standingLines . ' standing order line'
                        . ($standingLines === 1 ? '' : 's')
                        . ' for this weekday, but no dated daily orders yet.',
                    'count' => $standingLines,
                    'href' => bakery_ops_link_daily_orders($date, ['review' => 'differences']),
                    'action' => 'Generate or review Daily Orders',
                ]);
            } elseif ($dailyOrderCount === 0 && ($standingLines === 0 || $standingLines === null)) {
                $demand['state'] = 'empty';
                $demand['summary'] = 'No demand on file';
            } else {
                $demand['state'] = 'ok';
                $demand['summary'] = $dailyOrderCount . ' daily order'
                    . ($dailyOrderCount === 1 ? '' : 's')
                    . ' · ' . $customersWithOrders . ' customer'
                    . ($customersWithOrders === 1 ? '' : 's');
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard demand: ' . $e->getMessage());
        $sectionErrors['demand'] = bakery_dashboard_safe_error_message($e);
        $demand['state'] = 'unknown';
        $demand['summary'] = 'Unavailable';
        $exceptions[] = bakery_ops_exception([
            'type' => 'demand_unavailable',
            'severity' => 'critical',
            'category' => 'demand',
            'title' => 'Demand data unavailable',
            'detail' => $sectionErrors['demand'],
            'count' => null,
            'href' => $links['daily_orders'],
            'action' => 'Open Daily Orders',
            'resolution' => 'module',
        ]);
    }

    // --- Production & finished goods ---
    $production = [
        'key' => 'production',
        'label' => 'Production',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['production'],
        'metrics' => [
            'required_units' => bakery_dashboard_metric(null, 'unavailable'),
            'short_products' => bakery_dashboard_metric(null, 'unavailable'),
            'plan_short' => bakery_dashboard_metric(null, 'unavailable'),
        ],
    ];
    $pack = [
        'key' => 'pack',
        'label' => 'Pack',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['pack'],
        'metrics' => [
            'item_lines' => bakery_dashboard_metric(null, 'unavailable'),
            'units' => bakery_dashboard_metric(null, 'unavailable'),
        ],
    ];

    $inventoryReady = false;
    if (function_exists('bakery_inventory_ready')) {
        try {
            $inventoryReady = bakery_inventory_ready($db);
        } catch (Throwable $e) {
            $inventoryReady = false;
        }
    } else {
        require_once __DIR__ . '/product_inventory.php';
        try {
            $inventoryReady = bakery_inventory_ready($db);
        } catch (Throwable $e) {
            $inventoryReady = false;
        }
    }

    try {
        require_once __DIR__ . '/demand_review.php';
        $operatingLines = bakery_operating_demand_lines($db, $date);
        $operatingDemand = bakery_operating_demand_by_product($db, $date);
        $itemLines = count($operatingLines);
        $itemUnits = (int)$operatingDemand['required_units'];
        $hasDailyItems = !empty($operatingDemand['has_daily']);
        $requiredByProduct = $operatingDemand['by_product'];

        $pack['metrics']['item_lines'] = bakery_dashboard_metric($itemLines, $itemLines > 0 ? 'ready' : 'empty');
        $pack['metrics']['units'] = bakery_dashboard_metric($itemUnits, $itemUnits > 0 ? 'ready' : 'empty');
        if ($itemLines > 0) {
            $pack['state'] = 'ok';
            $pack['summary'] = $itemLines . ' line' . ($itemLines === 1 ? '' : 's')
                . ' · ' . number_format($itemUnits) . ' units to pack';
        } else {
            $pack['state'] = 'empty';
            $pack['summary'] = 'Nothing to pack';
        }

        $requiredUnits = array_sum($requiredByProduct);
        $production['metrics']['required_units'] = bakery_dashboard_metric(
            $requiredUnits,
            $requiredUnits > 0 ? 'ready' : 'empty',
            $hasDailyItems ? 'From daily orders' : 'From standing forecast'
        );

        $shortProducts = 0;
        $planShortProducts = 0;

        if ($inventoryReady && $requiredUnits > 0) {
            $placeholders = implode(',', array_fill(0, count($requiredByProduct), '?'));
            $productIds = array_keys($requiredByProduct);
            $stmt = $db->prepare("
                SELECT product_id,
                       COALESCE(available_quantity, 0) AS available_quantity,
                       COALESCE(loaded_quantity, 0) AS loaded_quantity,
                       COALESCE(produced_quantity, 0) AS produced_quantity
                FROM product_inventory_days
                WHERE delivery_date = ? AND product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$date], $productIds));
            $invRows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $invRows[(int)$row['product_id']] = $row;
            }

            foreach ($requiredByProduct as $productId => $required) {
                $inv = $invRows[$productId] ?? null;
                $stock = $inv
                    ? ((int)$inv['available_quantity'] + (int)$inv['loaded_quantity'])
                    : 0;
                if ($required > $stock) {
                    $shortProducts++;
                }
            }

            $production['metrics']['short_products'] = bakery_dashboard_metric($shortProducts, 'ready');

            if ($shortProducts > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'production_fg_shortfall',
                    'severity' => 'critical',
                    'category' => 'production',
                    'title' => 'Finished-goods shortfall',
                    'detail' => $shortProducts . ' product'
                        . ($shortProducts === 1 ? '' : 's')
                        . ' have less available+loaded stock than committed demand.',
                    'count' => $shortProducts,
                    'href' => bakery_ops_link_inventory($date, ['attention' => 'shortfall']),
                    'action' => 'Open Finished Goods',
                ]);
            }
        } elseif (!$inventoryReady) {
            $production['metrics']['short_products'] = bakery_dashboard_metric(null, 'unavailable', 'Finished-goods inventory not installed');
        } else {
            $production['metrics']['short_products'] = bakery_dashboard_metric(0, 'empty');
        }

        if (table_exists($db, 'production_plan_items') && $requiredUnits > 0) {
            $placeholders = implode(',', array_fill(0, count($requiredByProduct), '?'));
            $productIds = array_keys($requiredByProduct);
            $stmt = $db->prepare("
                SELECT product_id, planned_quantity
                FROM production_plan_items
                WHERE delivery_date = ? AND product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$date], $productIds));
            $plans = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $plans[(int)$row['product_id']] = (int)$row['planned_quantity'];
            }

            foreach ($requiredByProduct as $productId => $required) {
                if (!isset($plans[$productId])) {
                    continue; // no saved plan for this product — not an automatic shortfall
                }
                if ($plans[$productId] < $required) {
                    $planShortProducts++;
                }
            }
            $production['metrics']['plan_short'] = bakery_dashboard_metric($planShortProducts, 'ready');

            if ($planShortProducts > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'production_plan_short',
                    'severity' => 'warning',
                    'category' => 'production',
                    'title' => 'Production plan below demand',
                    'detail' => $planShortProducts . ' saved production target'
                        . ($planShortProducts === 1 ? '' : 's')
                        . ' are below committed demand for this date.',
                    'count' => $planShortProducts,
                    'href' => bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date]),
                    'action' => 'Open Production Center',
                ]);
            }

            if (function_exists('bakery_production_plan_state')) {
                $commitState = bakery_production_plan_state($db, $date);
                if (!empty($commitState['available'])) {
                    if ($commitState['commit'] === null) {
                        $exceptions[] = bakery_ops_exception([
                            'type' => 'production_plan_uncommitted',
                            'severity' => 'critical',
                            'category' => 'production',
                            'stage' => 'production_plan',
                            'title' => 'Production plan not committed',
                            'detail' => 'Saved targets are a draft. Commit the plan so Daily Production bakes those numbers.',
                            'href' => bakery_ops_link_production_center($weekStart, ['date' => $date]),
                            'action' => 'Commit Production Plan',
                            'inline_action' => [
                                'action' => 'commit_production_plan',
                                'label' => 'Commit plan',
                                'confirm' => 'Commit the last saved production targets for this delivery date? The baker will bake these numbers until you commit again.',
                            ],
                        ]);
                    } elseif ((int)($commitState['changed_since']['count'] ?? 0) > 0) {
                        $driftCount = (int)$commitState['changed_since']['count'];
                        $exceptions[] = bakery_ops_exception([
                            'type' => 'production_plan_drift',
                            'severity' => 'warning',
                            'category' => 'production',
                            'stage' => 'production_plan',
                            'title' => 'Demand changed after production plan commit',
                            'detail' => $driftCount . ' demand-affecting change'
                                . ($driftCount === 1 ? '' : 's')
                                . ' recorded after commit. The bake sheet still uses the committed plan.',
                            'count' => $driftCount,
                            'href' => bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date]),
                            'action' => 'Review and commit again',
                            'inline_action' => [
                                'action' => 'commit_production_plan',
                                'label' => 'Commit again',
                                'confirm' => 'Re-commit the last saved production targets? This updates the baker\'s numbers. Demand stays visible beside them.',
                            ],
                        ]);
                    }
                }
            }
        } else {
            $production['metrics']['plan_short'] = bakery_dashboard_metric(
                0,
                table_exists($db, 'production_plan_items') ? 'empty' : 'unavailable',
                table_exists($db, 'production_plan_items') ? null : 'No saved production plans'
            );
        }

        if ($shortProducts > 0 || $planShortProducts > 0) {
            $production['state'] = 'attention';
            $bits = [];
            if ($shortProducts > 0) {
                $bits[] = $shortProducts . ' short';
            }
            if ($planShortProducts > 0) {
                $bits[] = $planShortProducts . ' under-planned';
            }
            $production['summary'] = implode(' · ', $bits);
        } elseif ($requiredUnits === 0) {
            $production['state'] = 'empty';
            $production['summary'] = 'No production demand';
        } elseif (!$inventoryReady) {
            $production['state'] = 'ok';
            $production['summary'] = number_format($requiredUnits) . ' units demanded'
                . ($hasDailyItems ? '' : ' (standing forecast)');
        } else {
            $production['state'] = 'ok';
            $production['summary'] = 'Stock covers demand · ' . number_format($requiredUnits) . ' units';
        }
    } catch (Throwable $e) {
        error_log('dashboard production/pack: ' . $e->getMessage());
        $msg = bakery_dashboard_safe_error_message($e);
        $sectionErrors['production'] = $msg;
        $sectionErrors['pack'] = $msg;
        $production['state'] = 'unknown';
        $production['summary'] = 'Unavailable';
        $pack['state'] = 'unknown';
        $pack['summary'] = 'Unavailable';
        $exceptions[] = bakery_ops_exception([
            'type' => 'production_unavailable',
            'severity' => 'critical',
            'category' => 'production',
            'title' => 'Production / pack data unavailable',
            'detail' => $msg,
            'count' => null,
            'href' => $links['production'],
            'action' => 'Open Daily Production',
            'resolution' => 'module',
        ]);
    }

    // --- Load ---
    $load = [
        'key' => 'load',
        'label' => 'Load',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['driver_load'],
        'metrics' => [
            'drivers_with_work' => bakery_dashboard_metric(null, 'unavailable'),
            'incomplete_loads' => bakery_dashboard_metric(null, 'unavailable'),
        ],
        'focus_driver_id' => 0,
    ];

    try {
        if (!$inventoryReady || !table_exists($db, 'daily_order_assignments')) {
            $load['state'] = $inventoryReady ? 'empty' : 'unknown';
            $load['summary'] = $inventoryReady ? 'No load data' : 'Loads unavailable';
            $load['metrics']['drivers_with_work'] = bakery_dashboard_metric(null, $inventoryReady ? 'empty' : 'unavailable');
            $load['metrics']['incomplete_loads'] = bakery_dashboard_metric(null, $inventoryReady ? 'empty' : 'unavailable');
            if (!$inventoryReady) {
                $sectionErrors['load'] = 'Driver pickup loads require finished-goods inventory tables.';
            }
        } else {
            if (!function_exists('bakery_inventory_load_progress')) {
                require_once __DIR__ . '/product_inventory.php';
            }
            $loadProgress = bakery_inventory_load_progress($db, $date);
            $driversWithWork = (int)$loadProgress['drivers_with_work'];
            $incompleteList = $loadProgress['incomplete'];
            $incomplete = count($incompleteList);
            $focusDriverId = $incomplete === 1 ? (int)$incompleteList[0]['driver_id'] : 0;
            $load['focus_driver_id'] = $focusDriverId;

            $load['metrics']['drivers_with_work'] = bakery_dashboard_metric($driversWithWork, $driversWithWork > 0 ? 'ready' : 'empty');
            $load['metrics']['incomplete_loads'] = bakery_dashboard_metric($incomplete, 'ready');

            if ($driversWithWork === 0) {
                $load['state'] = 'empty';
                $load['summary'] = 'No driver loads yet';
            } elseif ($incomplete > 0) {
                $load['state'] = 'attention';
                $load['summary'] = $incomplete . ' of ' . $driversWithWork . ' driver load'
                    . ($driversWithWork === 1 ? '' : 's') . ' incomplete';
                $loadParams = ['attention' => 'incomplete'];
                $focusName = '';
                if ($focusDriverId > 0) {
                    $loadParams['driver_id'] = $focusDriverId;
                    $focusName = trim((string)($incompleteList[0]['name'] ?? ''));
                }
                $detail = $incomplete . ' driver'
                    . ($incomplete === 1 ? '' : 's')
                    . ' have assigned order units that are missing or under-loaded.';
                if ($focusName !== '') {
                    $detail = $focusName
                        . ' has assigned order units with no matching pickup saved. '
                        . 'Open Driver Pickup Loads for this operating date, set pickup quantities '
                        . '(Fill to need is fine even when production is empty), and Save pickup.';
                }
                $exceptions[] = bakery_ops_exception([
                    'type' => 'load_incomplete',
                    'severity' => 'warning',
                    'category' => 'load',
                    'stage' => 'dispatch',
                    'title' => 'Incomplete driver pickup loads',
                    'detail' => $detail,
                    'count' => $incomplete,
                    'href' => bakery_ops_link_driver_load($date, $loadParams),
                    'action' => 'Open Driver Pickup Loads',
                ]);
            } else {
                $load['state'] = 'ok';
                $load['summary'] = $driversWithWork . ' driver' . ($driversWithWork === 1 ? '' : 's') . ' loaded';
            }

            // Route closeout: loaded vans that are not yet reconciled block the day.
            if (function_exists('bakery_inventory_closeout_ready')
                && bakery_inventory_closeout_ready($db)
                && function_exists('bakery_inventory_closeout_stats')) {
                $closeoutStats = bakery_inventory_closeout_stats($db, $date);
                $routesOpen = (int)($closeoutStats['unreconciled'] ?? 0);
                $load['metrics']['routes_open'] = bakery_dashboard_metric($routesOpen, 'ready');
                if ($routesOpen > 0) {
                    $exceptions[] = bakery_ops_exception([
                        'type' => 'route_unreconciled',
                        'severity' => 'warning',
                        'category' => 'delivery',
                        'stage' => 'deliver',
                        'title' => 'Routes not closed out',
                        'detail' => $routesOpen . ' driver route'
                            . ($routesOpen === 1 ? '' : 's')
                            . ' still need loaded vs delivered vs returned vs waste reconciliation.',
                        'count' => $routesOpen,
                        'href' => bakery_ops_link_route_closeout($date, ['attention' => 'open']),
                        'action' => 'Open Route Closeout',
                    ]);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard load: ' . $e->getMessage());
        $sectionErrors['load'] = bakery_dashboard_safe_error_message($e);
        $load['state'] = 'unknown';
        $load['summary'] = 'Unavailable';
        $exceptions[] = bakery_ops_exception([
            'type' => 'load_unavailable',
            'severity' => 'critical',
            'category' => 'load',
            'title' => 'Load data unavailable',
            'detail' => $sectionErrors['load'],
            'count' => null,
            'href' => $links['driver_load'],
            'action' => 'Open Driver Pickup Loads',
            'resolution' => 'module',
        ]);
    }

    // --- Delivery ---
    $delivery = [
        'key' => 'delivery',
        'label' => 'Delivery',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['driver_assignment'],
        'metrics' => [
            'unassigned' => bakery_dashboard_metric(null, 'unavailable'),
            'pending' => bakery_dashboard_metric(null, 'unavailable'),
            'in_transit' => bakery_dashboard_metric(null, 'unavailable'),
            'delivered' => bakery_dashboard_metric(null, 'unavailable'),
            'failed' => bakery_dashboard_metric(null, 'unavailable'),
            'qty_variance' => bakery_dashboard_metric(null, 'unavailable'),
        ],
    ];

    $unassigned = null;
    $pending = null;
    $inTransit = null;
    $deliveredStops = null;
    $failed = null;
    $qtyVariance = null;

    try {
        if (!table_exists($db, 'daily_orders')) {
            $sectionErrors['delivery'] = 'Daily orders are not available in this database.';
        } else {
            if (table_exists($db, 'daily_order_assignments')) {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM daily_orders do
                    WHERE do.order_date = ?
                      AND NOT EXISTS (
                          SELECT 1 FROM daily_order_assignments doa
                          WHERE doa.daily_order_id = do.id AND doa.delivery_date = ?
                      )
                ");
                $stmt->execute([$date, $date]);
                $unassigned = (int)$stmt->fetchColumn();

                $stmt = $db->prepare("
                    SELECT
                        COALESCE(SUM(CASE WHEN delivery_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_cnt,
                        COALESCE(SUM(CASE WHEN delivery_status = 'in_transit' THEN 1 ELSE 0 END), 0) AS in_transit_cnt,
                        COALESCE(SUM(CASE WHEN delivery_status = 'delivered' THEN 1 ELSE 0 END), 0) AS delivered_cnt,
                        COALESCE(SUM(CASE WHEN delivery_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed_cnt
                    FROM daily_order_assignments
                    WHERE delivery_date = ?
                ");
                $stmt->execute([$date]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $pending = (int)($row['pending_cnt'] ?? 0);
                $inTransit = (int)($row['in_transit_cnt'] ?? 0);
                $deliveredStops = (int)($row['delivered_cnt'] ?? 0);
                $failed = (int)($row['failed_cnt'] ?? 0);

                $delivery['metrics']['unassigned'] = bakery_dashboard_metric($unassigned, 'ready');
                $delivery['metrics']['pending'] = bakery_dashboard_metric($pending, 'ready');
                $delivery['metrics']['in_transit'] = bakery_dashboard_metric($inTransit, 'ready');
                $delivery['metrics']['delivered'] = bakery_dashboard_metric($deliveredStops, 'ready');
                $delivery['metrics']['failed'] = bakery_dashboard_metric($failed, 'ready');
            } else {
                $sectionErrors['delivery'] = 'Delivery assignments are not available in this database.';
            }

            if (
                table_exists($db, 'daily_order_items')
                && bakery_dashboard_column_exists($db, 'daily_order_items', 'delivered_quantity')
            ) {
                $stmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM daily_order_items doi
                    JOIN daily_orders do ON do.id = doi.daily_order_id
                    WHERE do.order_date = ?
                      AND doi.delivered_quantity IS NOT NULL
                      AND doi.delivered_quantity <> doi.quantity
                ");
                $stmt->execute([$date]);
                $qtyVariance = (int)$stmt->fetchColumn();
                $delivery['metrics']['qty_variance'] = bakery_dashboard_metric($qtyVariance, 'ready');
            } else {
                $delivery['metrics']['qty_variance'] = bakery_dashboard_metric(null, 'unavailable', 'Delivered quantities not tracked');
            }

            if ($unassigned !== null && $unassigned > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'delivery_unassigned',
                    'severity' => 'critical',
                    'category' => 'delivery',
                    'stage' => 'dispatch',
                    'title' => 'Orders with no driver assignment',
                    'detail' => $unassigned . ' daily order'
                        . ($unassigned === 1 ? '' : 's')
                        . ' have no driver assignment for this date.',
                    'count' => $unassigned,
                    'href' => bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned']),
                    'action' => 'Assign drivers',
                    'inline_action' => [
                        'action' => 'assign_from_standing',
                        'label' => 'Build routes from standing',
                        'confirm' => 'Build this date from the standing route? Existing dated assignments for this date will be replaced.',
                    ],
                ]);
            }
            if ($failed !== null && $failed > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'delivery_failed',
                    'severity' => 'critical',
                    'category' => 'delivery',
                    'stage' => 'deliver',
                    'title' => 'Failed deliveries',
                    'detail' => $failed . ' stop' . ($failed === 1 ? '' : 's') . ' marked failed.',
                    'count' => $failed,
                    'href' => bakery_ops_link_manager($date, ['attention' => 'failed', 'fragment' => 'failed-stop-recovery']),
                    'action' => 'Open failed-stop recovery',
                ]);
            }
            if ($qtyVariance !== null && $qtyVariance > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'delivery_qty_variance',
                    'severity' => 'warning',
                    'category' => 'delivery',
                    'stage' => 'deliver',
                    'title' => 'Ordered vs delivered quantity differences',
                    'detail' => $qtyVariance . ' line' . ($qtyVariance === 1 ? '' : 's')
                        . ' have a delivered quantity different from the ordered quantity.',
                    'count' => $qtyVariance,
                    'href' => bakery_ops_link_billing($date, ['attention' => 'needs_attention', 'delivered_only' => '1']),
                    'action' => 'Review in Billing Center',
                ]);
            }

            $openStops = ($pending ?? 0) + ($inTransit ?? 0);
            $failedCount = (int)($failed ?? 0);
            $unassignedCount = (int)($unassigned ?? 0);
            $deliveredCount = (int)($deliveredStops ?? 0);

            if ($failedCount > 0 || $unassignedCount > 0) {
                $delivery['state'] = 'attention';
                $parts = [];
                if ($unassignedCount > 0) {
                    $parts[] = $unassignedCount . ' unassigned';
                }
                if ($failedCount > 0) {
                    $parts[] = $failedCount . ' failed';
                }
                if ($openStops > 0) {
                    $parts[] = $openStops . ' open';
                }
                $delivery['summary'] = implode(' · ', $parts);
            } elseif (($dailyOrderCount ?? 0) === 0 && $deliveredCount === 0 && $openStops === 0) {
                $delivery['state'] = 'empty';
                $delivery['summary'] = 'No deliveries';
            } elseif ($openStops === 0 && $deliveredCount > 0) {
                $delivery['state'] = 'ok';
                $delivery['summary'] = $deliveredCount . ' delivered';
            } elseif ($openStops > 0) {
                $delivery['state'] = 'ok'; // in-progress is normal, not an exception by itself
                $delivery['summary'] = $openStops . ' in progress · ' . $deliveredCount . ' delivered';
            } else {
                $delivery['state'] = 'empty';
                $delivery['summary'] = 'No deliveries';
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard delivery: ' . $e->getMessage());
        $sectionErrors['delivery'] = bakery_dashboard_safe_error_message($e);
        $delivery['state'] = 'unknown';
        $delivery['summary'] = 'Unavailable';
        $exceptions[] = bakery_ops_exception([
            'type' => 'delivery_unavailable',
            'severity' => 'critical',
            'category' => 'delivery',
            'title' => 'Delivery data unavailable',
            'detail' => $sectionErrors['delivery'],
            'count' => null,
            'href' => $links['driver_assignment'],
            'action' => 'Open Driver Assignment',
            'resolution' => 'module',
        ]);
    }

    // --- Invoice ---
    $invoice = [
        'key' => 'invoice',
        'label' => 'Invoice',
        'state' => 'unknown',
        'summary' => 'Unavailable',
        'href' => $links['invoice'],
        'metrics' => [
            'delivered_orders' => bakery_dashboard_metric(null, 'unavailable'),
            'uninvoiced' => bakery_dashboard_metric(null, 'unavailable'),
            'invoiced' => bakery_dashboard_metric(null, 'unavailable'),
            'unconfirmed' => bakery_dashboard_metric(null, 'unavailable'),
        ],
    ];

    try {
        if (!table_exists($db, 'daily_orders')) {
            $sectionErrors['invoice'] = 'Daily orders are not available in this database.';
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM daily_orders WHERE order_date = ? AND status = 'delivered'");
            $stmt->execute([$date]);
            $uninvoiced = (int)$stmt->fetchColumn();

            $stmt = $db->prepare("SELECT COUNT(*) FROM daily_orders WHERE order_date = ? AND status = 'invoiced'");
            $stmt->execute([$date]);
            $invoiced = (int)$stmt->fetchColumn();

            $deliveredOrders = $uninvoiced + $invoiced;
            $invoice['metrics']['delivered_orders'] = bakery_dashboard_metric($deliveredOrders, 'ready');
            $invoice['metrics']['uninvoiced'] = bakery_dashboard_metric($uninvoiced, 'ready');
            $invoice['metrics']['invoiced'] = bakery_dashboard_metric($invoiced, 'ready');

            $unconfirmed = null;
            if (bakery_dashboard_column_exists($db, 'daily_orders', 'delivery_confirmed_at')) {
                // Assignment marked delivered, but commercial confirmation/snapshot missing.
                if (table_exists($db, 'daily_order_assignments')) {
                    $stmt = $db->prepare("
                        SELECT COUNT(*)
                        FROM daily_order_assignments doa
                        JOIN daily_orders do ON do.id = doa.daily_order_id
                        WHERE doa.delivery_date = ?
                          AND do.order_date = ?
                          AND doa.delivery_status = 'delivered'
                          AND do.delivery_confirmed_at IS NULL
                          AND do.status <> 'invoiced'
                    ");
                    $stmt->execute([$date, $date]);
                    $unconfirmed = (int)$stmt->fetchColumn();
                    $invoice['metrics']['unconfirmed'] = bakery_dashboard_metric($unconfirmed, 'ready');
                } else {
                    $invoice['metrics']['unconfirmed'] = bakery_dashboard_metric(null, 'unavailable');
                }
            } else {
                $invoice['metrics']['unconfirmed'] = bakery_dashboard_metric(null, 'unavailable', 'Confirmation not tracked');
            }

            if ($uninvoiced > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'invoice_uninvoiced',
                    'severity' => 'warning',
                    'category' => 'invoice',
                    'stage' => 'invoice',
                    'title' => 'Delivered work not invoiced',
                    'detail' => $uninvoiced . ' order' . ($uninvoiced === 1 ? '' : 's')
                        . ' are marked delivered but not invoiced.',
                    'count' => $uninvoiced,
                    'href' => bakery_ops_link_billing($date, ['status' => 'delivered', 'attention' => 'needs_attention']),
                    'action' => 'Open Billing Center',
                ]);
            }
            if ($unconfirmed !== null && $unconfirmed > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'invoice_unconfirmed',
                    'severity' => 'info',
                    'category' => 'invoice',
                    'stage' => 'deliver',
                    'title' => 'Deliveries missing invoice confirmation',
                    'detail' => $unconfirmed . ' delivered stop'
                        . ($unconfirmed === 1 ? '' : 's')
                        . ' still lack a delivery confirmation / invoice snapshot.',
                    'count' => $unconfirmed,
                    'href' => bakery_ops_link_billing($date, ['attention' => 'needs_attention']),
                    'action' => 'Open Billing Center',
                ]);
            }

            if ($uninvoiced > 0 || ($unconfirmed ?? 0) > 0) {
                $invoice['state'] = 'attention';
                $invoice['summary'] = $uninvoiced . ' uninvoiced'
                    . (($unconfirmed ?? 0) > 0 ? ' · ' . $unconfirmed . ' unconfirmed' : '');
            } elseif ($deliveredOrders === 0) {
                $invoice['state'] = 'empty';
                $invoice['summary'] = 'Nothing to invoice yet';
            } else {
                $invoice['state'] = 'ok';
                $invoice['summary'] = $invoiced . ' invoiced';
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard invoice: ' . $e->getMessage());
        $sectionErrors['invoice'] = bakery_dashboard_safe_error_message($e);
        $invoice['state'] = 'unknown';
        $invoice['summary'] = 'Unavailable';
        $exceptions[] = bakery_ops_exception([
            'type' => 'invoice_unavailable',
            'severity' => 'critical',
            'category' => 'invoice',
            'title' => 'Invoice data unavailable',
            'detail' => $sectionErrors['invoice'],
            'count' => null,
            'href' => $links['invoice'],
            'action' => 'Open Billing Center',
            'resolution' => 'module',
        ]);
    }

    // Customer service issues (global, not date-scoped)
    try {
        if (!function_exists('bakery_delivery_issues_open_count')) {
            require_once __DIR__ . '/customer_delivery_issues.php';
        }
        if (function_exists('bakery_delivery_issues_open_count')) {
            $openIssues = bakery_delivery_issues_open_count($db);
            if ($openIssues > 0) {
                $exceptions[] = bakery_ops_exception([
                    'type' => 'service_open_issues',
                    'severity' => 'warning',
                    'category' => 'service',
                    'title' => 'Customer delivery issues',
                    'detail' => $openIssues . ' open issue(s) awaiting review',
                    'count' => $openIssues,
                    'href' => bakery_ops_link_service_issues(['status' => 'open']),
                    'action' => 'Review issues',
                ]);
            }
        }
    } catch (Throwable $e) {
        // Non-blocking — service issues table may not exist yet.
    }

    // Sort exceptions: critical → warning → info
    $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($exceptions, static function ($a, $b) use ($severityRank) {
        $ra = $severityRank[$a['severity']] ?? 9;
        $rb = $severityRank[$b['severity']] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp($a['title'], $b['title']);
    });

    return [
        'date' => $date,
        'weekday' => $weekday,
        'stages' => [$demand, $production, $pack, $load, $delivery, $invoice],
        'exceptions' => $exceptions,
        'section_errors' => $sectionErrors,
        'has_blocking_error' => $sectionErrors !== [],
        'links' => $links,
        'inventory_ready' => $inventoryReady,
    ];
}
