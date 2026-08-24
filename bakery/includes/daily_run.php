<?php
/**
 * Daily Run — step-by-step operating checklist for one bakery day.
 *
 * Builds on dashboard_command_center.php and demand_review.php.
 * Does not invent lifecycle fields; derives stage state from existing tables.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/dashboard_command_center.php';
require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/demand_confirmation.php';
require_once __DIR__ . '/production_plan.php';
require_once __DIR__ . '/operational_exceptions.php';
require_once __DIR__ . '/product_inventory.php';
if (file_exists(__DIR__ . '/operational_timeline.php')) {
    require_once __DIR__ . '/operational_timeline.php';
}

/**
 * Whether the operating_day_closeouts table is available.
 */
function bakery_daily_run_closeout_ready(PDO $db): bool
{
    return table_exists($db, 'operating_day_closeouts');
}

/**
 * Fetch closeout row for a date, or null.
 */
function bakery_daily_run_get_closeout(PDO $db, string $date): ?array
{
    if (!bakery_daily_run_closeout_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT operating_date, closed_at, closed_by_user_id, manager_note,
               reopened_at, reopened_by_user_id
        FROM operating_day_closeouts
        WHERE operating_date = ?
        LIMIT 1
    ');
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * True when the manager has closed the day and not reopened it since.
 */
function bakery_daily_run_is_closed(array $closeout): bool
{
    if ($closeout === []) {
        return false;
    }
    if (empty($closeout['closed_at'])) {
        return false;
    }
    // Reopen sets reopened_at; re-close clears it.
    return empty($closeout['reopened_at']);
}

/**
 * Record manager closeout for an operating date.
 */
function bakery_daily_run_close_day(PDO $db, string $date, ?int $userId, ?string $note): void
{
    if (!bakery_daily_run_closeout_ready($db)) {
        throw new RuntimeException('Operating day closeout is not installed. Run database migrations.');
    }
    $runState = bakery_daily_run_build($db, $date);
    if (empty($runState['operational_complete'])) {
        throw new RuntimeException(
            'Cannot close this day until all operating stages are complete with no outstanding blockers.'
        );
    }
    $stmt = $db->prepare('
        INSERT INTO operating_day_closeouts
            (operating_date, closed_at, closed_by_user_id, manager_note, reopened_at, reopened_by_user_id)
        VALUES (?, NOW(), ?, ?, NULL, NULL)
        ON DUPLICATE KEY UPDATE
            closed_at = NOW(),
            closed_by_user_id = VALUES(closed_by_user_id),
            manager_note = VALUES(manager_note),
            reopened_at = NULL,
            reopened_by_user_id = NULL
    ');
    $stmt->execute([$date, $userId, $note !== '' ? $note : null]);
    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DAY_CLOSED')) {
        bakery_record_operational_event($db, BAKERY_OP_DAY_CLOSED, 'Manager closed operating day ' . $date, [
            'operational_date' => $date,
            'metadata' => $note !== '' ? ['manager_note' => $note] : [],
        ]);
    } elseif (function_exists('app_log')) {
        app_log("Daily run closed for {$date} by user " . ($userId ?? 'unknown'), 'info');
    }
}

/**
 * Reopen a previously closed operating date.
 */
function bakery_daily_run_reopen_day(PDO $db, string $date, ?int $userId): void
{
    if (!bakery_daily_run_closeout_ready($db)) {
        throw new RuntimeException('Operating day closeout is not installed. Run database migrations.');
    }
    $stmt = $db->prepare('
        UPDATE operating_day_closeouts
        SET reopened_at = NOW(), reopened_by_user_id = ?
        WHERE operating_date = ? AND closed_at IS NOT NULL
    ');
    $stmt->execute([$userId, $date]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('This operating date has not been closed yet.');
    }
    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DAY_REOPENED')) {
        bakery_record_operational_event($db, BAKERY_OP_DAY_REOPENED, 'Manager reopened operating day ' . $date, [
            'operational_date' => $date,
        ]);
    } elseif (function_exists('app_log')) {
        app_log("Daily run reopened for {$date} by user " . ($userId ?? 'unknown'), 'info');
    }
}

/**
 * Map internal stage state to Daily Run UI vocabulary.
 */
function bakery_daily_run_ui_state(string $state, bool $hasBlockers = false, bool $inProgress = false): string
{
    if ($state === 'unknown') {
        return 'unavailable';
    }
    if ($state === 'empty') {
        return 'empty';
    }
    if ($hasBlockers) {
        return 'needs_attention';
    }
    if ($inProgress) {
        return 'in_progress';
    }
    if ($state === 'attention') {
        return 'needs_attention';
    }
    if ($state === 'ok') {
        return 'complete';
    }
    return 'not_started';
}

/**
 * Count stages that count toward progress (complete or nothing-to-do).
 */
function bakery_daily_run_counts_toward_progress(string $uiState): bool
{
    return in_array($uiState, ['complete', 'empty'], true);
}

/**
 * Build required product quantities for a date (daily orders preferred, else standing).
 *
 * @return array{by_product: array<int,int>, has_daily: bool, required_units: int, product_count: int}
 */
function bakery_daily_run_required_products(PDO $db, string $date, int $weekday): array
{
    if (!function_exists('bakery_operating_demand_by_product')) {
        require_once __DIR__ . '/demand_review.php';
    }
    if (function_exists('bakery_operating_demand_by_product')) {
        $demand = bakery_operating_demand_by_product($db, $date);
        return [
            'by_product' => $demand['by_product'],
            'has_daily' => $demand['has_daily'],
            'required_units' => $demand['required_units'],
            'product_count' => $demand['product_count'],
        ];
    }

    $byProduct = [];
    $hasDaily = false;

    if (table_exists($db, 'daily_order_items') && table_exists($db, 'daily_orders')) {
        $stmt = $db->prepare("
            SELECT doi.product_id, SUM(doi.quantity) AS qty
            FROM daily_order_items doi
            JOIN daily_orders do ON do.id = doi.daily_order_id
            WHERE do.order_date = ? AND doi.quantity > 0
            GROUP BY doi.product_id
        ");
        $stmt->execute([$date]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byProduct[(int)$row['product_id']] = (int)$row['qty'];
        }
        $hasDaily = count($byProduct) > 0;
    }

    if (!$hasDaily && table_exists($db, 'standing_orders')) {
        $dayClause = bakery_standing_day_in_clause($weekday);
        $stmt = $db->prepare("
            SELECT product_id, SUM(quantity) AS qty
            FROM standing_orders
            WHERE quantity > 0 AND day_of_week {$dayClause['sql']}
            GROUP BY product_id
        ");
        $stmt->execute($dayClause['values']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byProduct[(int)$row['product_id']] = (int)$row['qty'];
        }
    }

    return [
        'by_product' => $byProduct,
        'has_daily' => $hasDaily,
        'required_units' => array_sum($byProduct),
        'product_count' => count($byProduct),
    ];
}

/**
 * Build the full Daily Run payload for one operating date.
 *
 * @return array
 */
function bakery_daily_run_build(PDO $db, string $date): array
{
    $weekday = bakery_standing_day_from_date($date);
    $weekStart = bakery_dashboard_week_start_monday($date);
    $base = defined('BASE_URL') ? BASE_URL : '';

    $links = [
        'daily_run' => $base . 'daily_run.php?date=' . rawurlencode($date),
        'daily_orders' => bakery_ops_link_daily_orders($date, [], 'daily_run'),
        'production_center' => bakery_ops_link_production_center($weekStart, ['date' => $date], 'daily_run'),
        'production' => bakery_ops_link_production($date, [], 'daily_run'),
        'pack' => bakery_ops_link_pack_list($date, [], 'daily_run'),
        'inventory' => bakery_ops_link_inventory($date, [], 'daily_run'),
        'driver_assignment' => bakery_ops_link_driver_assignment($date, [], 'daily_run'),
        'driver_load' => bakery_ops_link_driver_load($date, [], 'daily_run'),
        'route_closeout' => bakery_ops_link_route_closeout($date, [], 'daily_run'),
        'driver_list' => bakery_ops_link_driver_list($date, [], 'daily_run'),
        'driver_route' => $base . 'driver.php?date=' . rawurlencode($date),
        'invoice' => bakery_ops_link_billing($date, [], 'daily_run'),
        'invoice_delivered' => bakery_ops_link_billing($date, ['status' => 'delivered'], 'daily_run'),
        'index' => bakery_ops_link_append_return($base . 'index.php?date=' . rawurlencode($date), 'daily_run'),
    ];

    $blockers = [];
    $sectionErrors = [];

    $inventoryReady = false;
    if (function_exists('bakery_inventory_ready')) {
        try {
            $inventoryReady = bakery_inventory_ready($db);
        } catch (Throwable $e) {
            $inventoryReady = false;
        }
    }

    // Demand review (canonical standing vs daily comparison).
    $demandReview = null;
    $demandError = null;
    try {
        if (table_exists($db, 'daily_orders')) {
            $demandReview = bakery_demand_review_build($db, $date, []);
        }
    } catch (Throwable $e) {
        error_log('daily_run demand: ' . $e->getMessage());
        $demandError = bakery_dashboard_safe_error_message($e);
        $sectionErrors['demand'] = $demandError;
    }

    $required = bakery_daily_run_required_products($db, $date, $weekday);
    $requiredByProduct = $required['by_product'];
    $requiredUnits = $required['required_units'];
    $productCount = $required['product_count'];

    // --- Stage 1: Confirm Demand ---
    $demandStage = [
        'key' => 'confirm_demand',
        'step' => 1,
        'label' => 'Confirm Demand',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Review Demand',
        'href' => $links['daily_orders'],
    ];

    if ($demandError) {
        $demandStage['ui_state'] = 'unavailable';
        $demandStage['summary'] = 'Demand data unavailable';
        $blockers[] = [
            'severity' => 'critical',
            'stage' => 'confirm_demand',
            'title' => 'Demand data unavailable',
            'detail' => $demandError,
            'href' => $links['daily_orders'],
            'action' => 'Open Daily Orders',
        ];
    } elseif ($demandReview === null) {
        $demandStage['ui_state'] = 'unavailable';
        $demandStage['summary'] = 'Daily orders not installed';
    } else {
        $ds = $demandReview['summary'];
        $demandStage['metrics'] = [
            ['label' => 'Expected customers', 'value' => (int)$ds['expected_customers']],
            ['label' => 'With dated orders', 'value' => (int)$ds['customers_with_daily']],
            ['label' => 'Missing daily order', 'value' => (int)$ds['missing_daily']],
            ['label' => 'Changed from standing', 'value' => (int)$ds['changed']],
            ['label' => 'Empty dated orders', 'value' => (int)$ds['empty_daily']],
        ];

        $attentionCount = (int)$ds['missing_daily'] + (int)$ds['empty_daily'];
        if ((int)$ds['expected_customers'] === 0 && (int)$ds['customers_with_daily'] === 0) {
            $demandStage['ui_state'] = 'empty';
            $demandStage['summary'] = 'No demand on file for this date';
        } elseif ($attentionCount > 0) {
            $demandStage['ui_state'] = 'needs_attention';
            $parts = [];
            if ((int)$ds['missing_daily'] > 0) {
                $parts[] = (int)$ds['missing_daily'] . ' missing dated order'
                    . ((int)$ds['missing_daily'] === 1 ? '' : 's');
                $blockers[] = bakery_ops_exception([
                    'type' => 'demand_missing_daily',
                    'severity' => 'critical',
                    'stage' => 'confirm_demand',
                    'category' => 'demand',
                    'title' => 'Standing customers without dated orders',
                    'detail' => (int)$ds['missing_daily'] . ' expected customer'
                        . ((int)$ds['missing_daily'] === 1 ? '' : 's')
                        . ' have standing demand but no daily_orders row for this date.',
                    'count' => (int)$ds['missing_daily'],
                    'href' => bakery_ops_link_daily_orders($date, ['review' => 'missing'], 'daily_run'),
                    'action' => 'Review Demand',
                ]);
            }
            if ((int)$ds['empty_daily'] > 0) {
                $parts[] = (int)$ds['empty_daily'] . ' empty dated order'
                    . ((int)$ds['empty_daily'] === 1 ? '' : 's');
                $blockers[] = bakery_ops_exception([
                    'type' => 'demand_empty_daily',
                    'severity' => 'warning',
                    'stage' => 'confirm_demand',
                    'category' => 'demand',
                    'title' => 'Dated orders with no line items',
                    'detail' => (int)$ds['empty_daily'] . ' daily order'
                        . ((int)$ds['empty_daily'] === 1 ? '' : 's')
                        . ' exist but have no committed products.',
                    'count' => (int)$ds['empty_daily'],
                    'href' => bakery_ops_link_daily_orders($date, ['review' => 'empty'], 'daily_run'),
                    'action' => 'Review Demand',
                ]);
            }
            $demandStage['summary'] = implode(' · ', $parts);
        } elseif ((int)$ds['expected_customers'] > 0 && (int)$ds['customers_with_daily'] === 0) {
            $demandStage['ui_state'] = 'needs_attention';
            $demandStage['summary'] = 'Standing demand exists but no dated orders yet';
            $blockers[] = bakery_ops_exception([
                'type' => 'demand_no_orders',
                'severity' => 'critical',
                'stage' => 'confirm_demand',
                'category' => 'demand',
                'title' => 'No daily orders for this date',
                'detail' => 'Standing forecast exists for this weekday, but no dated commercial orders have been created.',
                'href' => bakery_ops_link_daily_orders($date, ['review' => 'differences'], 'daily_run'),
                'action' => 'Generate or Review Daily Orders',
            ]);
        } else {
            // Generation shape looks healthy. Confirmation (when installed)
            // decides whether stage 1 is truly complete and can gate closeout.
            $demandStage['ui_state'] = 'complete';
            $demandStage['summary'] = (int)$ds['customers_with_daily'] . ' customer'
                . ((int)$ds['customers_with_daily'] === 1 ? '' : 's')
                . ' · ' . number_format((int)$ds['daily_units']) . ' units committed';
            if ((int)$ds['changed'] > 0) {
                $demandStage['summary'] .= ' · ' . (int)$ds['changed'] . ' intentional change'
                    . ((int)$ds['changed'] === 1 ? '' : 's');
            }
        }

        // Explicit manager confirmation ("Tomorrow, confirmed"). When the
        // demand_confirmations table is available, stage 1 stays incomplete
        // until confirmed (and reopens on post-confirm demand drift), which
        // hard-gates Daily Run closeout via operational_complete.
        try {
            $confirmState = bakery_demand_confirmation_state($db, $date);
            $confirmable = bakery_demand_is_confirmable($ds);
            $demandStage['confirmation'] = [
                'available' => $confirmState['available'],
                'confirmable' => $confirmable,
                'confirmation' => $confirmState['confirmation'],
                'changed_since' => $confirmState['changed_since'],
            ];
            if ($confirmState['confirmation'] !== null
                && $confirmState['changed_since']['count'] > 0) {
                $demandStage['summary'] .= ' · ' . $confirmState['changed_since']['count']
                    . ' change' . ($confirmState['changed_since']['count'] === 1 ? '' : 's')
                    . ' since confirmation';
            }

            if ($confirmState['available'] && $demandStage['ui_state'] === 'complete' && $confirmable) {
                if ($confirmState['confirmation'] === null) {
                    $demandStage['ui_state'] = 'needs_attention';
                    $demandStage['summary'] .= ' · awaiting manager confirmation';
                    $demandStage['action_label'] = 'Confirm Demand';
                    $demandStage['href'] = $links['daily_run'] . '#confirm_demand';
                    $blockers[] = bakery_ops_exception([
                        'type' => 'demand_unconfirmed',
                        'severity' => 'critical',
                        'stage' => 'confirm_demand',
                        'category' => 'demand',
                        'title' => 'Demand not confirmed',
                        'detail' => 'Dated orders are ready. Confirm demand before later stages can finish the day.',
                        'href' => $links['daily_run'] . '#confirm_demand',
                        'action' => 'Confirm Demand',
                    ]);
                } elseif ((int)$confirmState['changed_since']['count'] > 0) {
                    $demandStage['ui_state'] = 'needs_attention';
                    $demandStage['action_label'] = 'Confirm again';
                    $demandStage['href'] = $links['daily_run'] . '#confirm_demand';
                    $blockers[] = bakery_ops_exception([
                        'type' => 'demand_changed_since',
                        'severity' => 'warning',
                        'stage' => 'confirm_demand',
                        'category' => 'demand',
                        'title' => 'Demand changed since confirmation',
                        'detail' => (int)$confirmState['changed_since']['count']
                            . ' demand-affecting change'
                            . ((int)$confirmState['changed_since']['count'] === 1 ? '' : 's')
                            . ' recorded after confirmation — review and confirm again.',
                        'count' => (int)$confirmState['changed_since']['count'],
                        'href' => bakery_ops_link_daily_orders($date, ['review' => 'differences'], 'daily_run'),
                        'action' => 'Review and confirm again',
                    ]);
                }
            }
        } catch (Throwable $e) {
            error_log('daily_run demand confirmation: ' . $e->getMessage());
            $demandStage['confirmation'] = ['available' => false];
        }
    }

    // --- Stage 2: Commit Production Plan ---
    $planStage = [
        'key' => 'production_plan',
        'step' => 2,
        'label' => 'Commit Production Plan',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Finish Production Plan',
        'href' => $links['production_center'],
    ];

    $productsWithPlan = 0;
    $planShortProducts = 0;
    $missingPlanProducts = 0;

    if ($productCount === 0) {
        $planStage['ui_state'] = 'empty';
        $planStage['summary'] = 'No production demand yet';
    } elseif (!table_exists($db, 'production_plan_items')) {
        $planStage['ui_state'] = 'unavailable';
        $planStage['summary'] = 'Production Center plans not installed';
    } else {
        try {
            $productIds = array_keys($requiredByProduct);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
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

            foreach ($requiredByProduct as $productId => $requiredQty) {
                if (!isset($plans[$productId])) {
                    $missingPlanProducts++;
                } elseif ($plans[$productId] < $requiredQty) {
                    $planShortProducts++;
                } elseif ($plans[$productId] >= $requiredQty) {
                    $productsWithPlan++;
                }
            }

            $planStage['metrics'] = [
                ['label' => 'Products required', 'value' => $productCount],
                ['label' => 'Targets meet demand', 'value' => $productsWithPlan],
                ['label' => 'Missing saved target', 'value' => $missingPlanProducts],
                ['label' => 'Target below demand', 'value' => $planShortProducts],
            ];

            $coverageBits = [];
            if ($missingPlanProducts > 0) {
                $coverageBits[] = $missingPlanProducts . ' without saved target';
            }
            if ($planShortProducts > 0) {
                $coverageBits[] = $planShortProducts . ' under-planned';
            }

            $commitState = function_exists('bakery_production_plan_state')
                ? bakery_production_plan_state($db, $date)
                : ['available' => false, 'commit' => null, 'changed_since' => ['count' => 0, 'latest' => null, 'examples' => []]];
            $planStage['commit'] = $commitState;

            $warnMissingShort = static function () use (
                &$blockers,
                $missingPlanProducts,
                $planShortProducts,
                $weekStart,
                $date
            ): void {
                if ($missingPlanProducts > 0) {
                    $blockers[] = bakery_ops_exception([
                        'type' => 'production_plan_missing',
                        'severity' => 'warning',
                        'stage' => 'production_plan',
                        'category' => 'production',
                        'title' => 'Products without saved production target',
                        'detail' => $missingPlanProducts . ' demanded product'
                            . ($missingPlanProducts === 1 ? '' : 's')
                            . ' have no production_plan_items row for this date.',
                        'count' => $missingPlanProducts,
                        'href' => bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date], 'daily_run'),
                        'action' => 'Finish Production Plan',
                    ]);
                }
                if ($planShortProducts > 0) {
                    $blockers[] = bakery_ops_exception([
                        'type' => 'production_plan_short',
                        'severity' => 'warning',
                        'stage' => 'production_plan',
                        'category' => 'production',
                        'title' => 'Production plan below committed demand',
                        'detail' => $planShortProducts . ' saved target'
                            . ($planShortProducts === 1 ? '' : 's')
                            . ' are below dated order quantities.',
                        'count' => $planShortProducts,
                        'href' => bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date], 'daily_run'),
                        'action' => 'Finish Production Plan',
                    ]);
                }
            };

            if (!empty($commitState['available'])) {
                $commitRow = $commitState['commit'];
                $driftCount = (int)($commitState['changed_since']['count'] ?? 0);
                if ($commitRow === null) {
                    $planStage['ui_state'] = 'needs_attention';
                    $planStage['action_label'] = 'Commit Production Plan';
                    $planStage['href'] = $links['daily_run'] . '#production_plan';
                    $summaryBits = ['awaiting manager commit'];
                    if ($coverageBits !== []) {
                        $summaryBits = array_merge($coverageBits, $summaryBits);
                    }
                    $planStage['summary'] = implode(' · ', $summaryBits);
                    $warnMissingShort();
                    $blockers[] = bakery_ops_exception([
                        'type' => 'production_plan_uncommitted',
                        'severity' => 'critical',
                        'stage' => 'production_plan',
                        'category' => 'production',
                        'title' => 'Production plan not committed',
                        'detail' => 'Saved targets are a draft. Commit the plan so Daily Production bakes those numbers.',
                        'href' => $links['daily_run'] . '#production_plan',
                        'action' => 'Commit Production Plan',
                        'inline_action' => [
                            'action' => 'commit_production_plan',
                            'label' => 'Commit plan',
                            'confirm' => 'Commit the last saved production targets for this delivery date? The baker will bake these numbers until you commit again.',
                        ],
                    ]);
                } elseif ($driftCount > 0) {
                    $planStage['ui_state'] = 'needs_attention';
                    $planStage['action_label'] = 'Commit again';
                    $planStage['href'] = $links['daily_run'] . '#production_plan';
                    $planStage['summary'] = 'Committed · ' . $driftCount . ' demand change'
                        . ($driftCount === 1 ? '' : 's')
                        . ' since commit — baker numbers are unchanged';
                    $blockers[] = bakery_ops_exception([
                        'type' => 'production_plan_drift',
                        'severity' => 'warning',
                        'stage' => 'production_plan',
                        'category' => 'production',
                        'title' => 'Demand changed after production plan commit',
                        'detail' => $driftCount . ' demand-affecting change'
                            . ($driftCount === 1 ? '' : 's')
                            . ' recorded after commit. The bake sheet still uses the committed plan until you commit again.',
                        'count' => $driftCount,
                        'href' => bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date], 'daily_run'),
                        'action' => 'Review and commit again',
                        'inline_action' => [
                            'action' => 'commit_production_plan',
                            'label' => 'Commit again',
                            'confirm' => 'Re-commit the last saved production targets? This updates the baker\'s numbers. Demand stays visible beside them.',
                        ],
                    ]);
                } else {
                    $planStage['ui_state'] = 'complete';
                    $planStage['summary'] = (int)$commitRow['products_count'] . ' product'
                        . ((int)$commitRow['products_count'] === 1 ? '' : 's')
                        . ' · ' . number_format((int)$commitRow['units_count'])
                        . ' units committed to the baker';
                    $planStage['action_label'] = 'Open Production Center';
                    $planStage['href'] = $links['production_center'];
                }
            } elseif ($missingPlanProducts > 0 || $planShortProducts > 0) {
                $planStage['ui_state'] = 'needs_attention';
                $planStage['summary'] = implode(' · ', $coverageBits);
                $warnMissingShort();
            } elseif ($productsWithPlan === $productCount) {
                $planStage['ui_state'] = 'complete';
                $planStage['summary'] = $productCount . ' product'
                    . ($productCount === 1 ? '' : 's')
                    . ' · saved targets cover demand';
            } else {
                $planStage['ui_state'] = 'in_progress';
                $planStage['summary'] = $productsWithPlan . ' of ' . $productCount . ' products covered';
            }
        } catch (Throwable $e) {
            error_log('daily_run plan: ' . $e->getMessage());
            $planStage['ui_state'] = 'unavailable';
            $planStage['summary'] = 'Production plan lookup failed';
            $sectionErrors['production_plan'] = bakery_dashboard_safe_error_message($e);
        }
    }

    // --- Stage 3: Produce ---
    // Measure against the bake sheet: committed plan when committed, else demand.
    $produceTargets = function_exists('bakery_production_produce_targets_by_product')
        ? bakery_production_produce_targets_by_product($db, $date)
        : [
            'by_product' => $requiredByProduct,
            'product_count' => $productCount,
            'required_units' => $requiredUnits,
            'source' => 'demand',
            'committed' => false,
        ];
    $produceByProduct = $produceTargets['by_product'];
    $produceProductCount = (int)$produceTargets['product_count'];
    $produceAgainstCommit = !empty($produceTargets['committed']);

    $produceStage = [
        'key' => 'produce',
        'step' => 3,
        'label' => 'Produce',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Open Daily Production',
        'href' => $links['production'],
        'target_source' => $produceTargets['source'] ?? 'demand',
    ];

    $completeProducts = 0;
    $partialProducts = 0;
    $pendingProducts = 0;
    $unitsMade = 0;
    $unitsPlanned = 0;

    if ($produceProductCount === 0) {
        $produceStage['ui_state'] = 'empty';
        $produceStage['summary'] = 'Nothing to produce';
    } elseif (!$inventoryReady) {
        $produceStage['ui_state'] = 'unavailable';
        $produceStage['summary'] = 'Finished-goods inventory not installed';
    } else {
        try {
            $productIds = array_keys($produceByProduct);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("
                SELECT product_id, COALESCE(produced_quantity, 0) AS produced_quantity
                FROM product_inventory_days
                WHERE delivery_date = ? AND product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$date], $productIds));
            $produced = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $produced[(int)$row['product_id']] = (int)$row['produced_quantity'];
            }

            foreach ($produceByProduct as $productId => $plannedQty) {
                $madeQty = $produced[$productId] ?? 0;
                $unitsPlanned += $plannedQty;
                $unitsMade += min($madeQty, $plannedQty);
                if ($madeQty >= $plannedQty) {
                    $completeProducts++;
                } elseif ($madeQty > 0) {
                    $partialProducts++;
                } else {
                    $pendingProducts++;
                }
            }

            $produceStage['metrics'] = [
                ['label' => 'Products complete', 'value' => $completeProducts . '/' . $produceProductCount],
                ['label' => 'In progress', 'value' => $partialProducts],
                ['label' => 'Not started', 'value' => $pendingProducts],
                ['label' => 'Units recorded', 'value' => number_format($unitsMade) . '/' . number_format($unitsPlanned)],
                [
                    'label' => 'Measured against',
                    'value' => $produceAgainstCommit ? 'Committed bake' : 'Demand',
                ],
            ];

            if ($pendingProducts > 0 || $partialProducts > 0) {
                $inProgress = $partialProducts > 0 || ($completeProducts > 0 && $pendingProducts > 0);
                $produceStage['ui_state'] = $pendingProducts === $produceProductCount ? 'not_started' : ($inProgress ? 'in_progress' : 'needs_attention');
                if ($partialProducts > 0 || ($completeProducts > 0 && $pendingProducts > 0)) {
                    $produceStage['ui_state'] = 'in_progress';
                } else {
                    $produceStage['ui_state'] = 'not_started';
                }
                $produceStage['summary'] = $completeProducts . '/' . $produceProductCount . ' products complete';
                if ($produceAgainstCommit) {
                    $produceStage['summary'] .= ' · vs committed bake';
                }
                if ($pendingProducts > 0) {
                    $produceStage['summary'] .= ' · ' . $pendingProducts . ' not started';
                }
                if ($partialProducts > 0) {
                    $produceStage['summary'] .= ' · ' . $partialProducts . ' in progress';
                    $blockers[] = [
                        'severity' => 'warning',
                        'stage' => 'produce',
                        'title' => 'Production still in progress',
                        'detail' => $partialProducts . ' product'
                            . ($partialProducts === 1 ? '' : 's')
                            . ' have partial produced_quantity for this date.',
                        'count' => $partialProducts,
                        'href' => $links['production'],
                        'action' => 'Open Daily Production',
                    ];
                }
                if ($pendingProducts > 0 && $completeProducts > 0) {
                    $blockers[] = [
                        'severity' => 'info',
                        'stage' => 'produce',
                        'title' => 'Production started while items remain',
                        'detail' => 'Later stages may be underway while ' . $pendingProducts . ' product'
                            . ($pendingProducts === 1 ? '' : 's')
                            . ' still ha'
                            . ($pendingProducts === 1 ? 's' : 've')
                            . ' no recorded production.',
                        'count' => $pendingProducts,
                        'href' => $links['production'],
                        'action' => 'Open Daily Production',
                    ];
                }
            } else {
                $produceStage['ui_state'] = 'complete';
                $produceStage['summary'] = 'All ' . $produceProductCount . ' products recorded · '
                    . number_format($unitsMade) . ' units'
                    . ($produceAgainstCommit ? ' · vs committed bake' : '');
            }
        } catch (Throwable $e) {
            error_log('daily_run produce: ' . $e->getMessage());
            $produceStage['ui_state'] = 'unavailable';
            $produceStage['summary'] = 'Production data unavailable';
            $sectionErrors['produce'] = bakery_dashboard_safe_error_message($e);
        }
    }

    // --- Stage 4: Pack ---
    $packStage = [
        'key' => 'pack',
        'step' => 4,
        'label' => 'Pack',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Open Pack List',
        'href' => $links['pack'],
    ];

    $packLines = 0;
    $packUnits = 0;
    $stockShortProducts = 0;

    try {
        if (table_exists($db, 'daily_order_items') && table_exists($db, 'daily_orders')) {
            $stmt = $db->prepare("
                SELECT COUNT(*) AS lines_cnt, COALESCE(SUM(doi.quantity), 0) AS units_cnt
                FROM daily_order_items doi
                JOIN daily_orders do ON do.id = doi.daily_order_id
                WHERE do.order_date = ? AND doi.quantity > 0
            ");
            $stmt->execute([$date]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $packLines = (int)($row['lines_cnt'] ?? 0);
            $packUnits = (int)($row['units_cnt'] ?? 0);
        }

        if ($packLines === 0 && $productCount > 0 && !$required['has_daily']) {
            // Standing-only forecast — approximate line count from product totals.
            $packLines = $productCount;
            $packUnits = $requiredUnits;
        }

        if ($inventoryReady && $productCount > 0) {
            $productIds = array_keys($requiredByProduct);
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $stmt = $db->prepare("
                SELECT product_id,
                       COALESCE(available_quantity, 0) AS available_quantity,
                       COALESCE(loaded_quantity, 0) AS loaded_quantity
                FROM product_inventory_days
                WHERE delivery_date = ? AND product_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$date], $productIds));
            $invRows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $invRows[(int)$row['product_id']] = $row;
            }
            foreach ($requiredByProduct as $productId => $requiredQty) {
                $inv = $invRows[$productId] ?? null;
                $stock = $inv ? ((int)$inv['available_quantity'] + (int)$inv['loaded_quantity']) : 0;
                if ($requiredQty > $stock) {
                    $stockShortProducts++;
                }
            }
        }

        $packStage['metrics'] = [
            ['label' => 'Pack lines', 'value' => $packLines],
            ['label' => 'Units to pack', 'value' => number_format($packUnits)],
            ['label' => 'Stock shortfall', 'value' => $stockShortProducts],
        ];

        if ($packLines === 0 && $packUnits === 0) {
            $packStage['ui_state'] = 'empty';
            $packStage['summary'] = 'Nothing to pack';
        } elseif ($stockShortProducts > 0) {
            $packStage['ui_state'] = 'needs_attention';
            $packStage['summary'] = $packLines . ' lines · ' . $stockShortProducts . ' product'
                . ($stockShortProducts === 1 ? '' : 's') . ' short on finished goods';
            $blockers[] = bakery_ops_exception([
                'type' => 'production_fg_shortfall',
                'severity' => 'critical',
                'stage' => 'pack',
                'category' => 'production',
                'title' => 'Insufficient finished goods to pack',
                'detail' => $stockShortProducts . ' product'
                    . ($stockShortProducts === 1 ? '' : 's')
                    . ' have less available+loaded stock than committed demand.',
                'count' => $stockShortProducts,
                'href' => bakery_ops_link_inventory($date, ['attention' => 'shortfall'], 'daily_run'),
                'action' => 'Open Finished Goods',
            ]);
        } elseif ($completeProducts < $productCount && $productCount > 0) {
            $packStage['ui_state'] = 'in_progress';
            $packStage['summary'] = number_format($packUnits) . ' units to pack · production incomplete';
        } else {
            $packStage['ui_state'] = 'complete';
            $packStage['summary'] = $packLines . ' line'
                . ($packLines === 1 ? '' : 's')
                . ' · ' . number_format($packUnits) . ' units ready to pack';
        }
    } catch (Throwable $e) {
        error_log('daily_run pack: ' . $e->getMessage());
        $packStage['ui_state'] = 'unavailable';
        $packStage['summary'] = 'Pack data unavailable';
        $sectionErrors['pack'] = bakery_dashboard_safe_error_message($e);
    }

    // Reuse dashboard command center for delivery/load/invoice metrics and shared blockers.
    $commandCenter = bakery_dashboard_command_center($db, $date);
    $ccStages = [];
    foreach ($commandCenter['stages'] as $s) {
        $ccStages[$s['key']] = $s;
    }
    $ccBlockers = bakery_ops_exceptions_to_blockers(
        bakery_ops_enrich_exceptions($commandCenter['exceptions'], $date, 'daily_run')
    );
    // CC covers dispatch/deliver/invoice/load/delivery/service — merge after stage-specific blockers.
    // --- Stage 5: Assign / Load / Dispatch ---
    $dispatchStage = [
        'key' => 'dispatch',
        'step' => 5,
        'label' => 'Assign / Load / Dispatch',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Open Driver Assignment',
        'href' => $links['driver_assignment'],
    ];

    $unassigned = (int)($ccStages['delivery']['metrics']['unassigned']['value'] ?? 0);
    $driversWithWork = (int)($ccStages['load']['metrics']['drivers_with_work']['value'] ?? 0);
    $incompleteLoads = (int)($ccStages['load']['metrics']['incomplete_loads']['value'] ?? 0);
    $inTransit = (int)($ccStages['delivery']['metrics']['in_transit']['value'] ?? 0);
    $dailyOrderCount = (int)($ccStages['demand']['metrics']['daily_orders']['value'] ?? 0);

    $dispatchStage['metrics'] = [
        ['label' => 'Unassigned orders', 'value' => $unassigned],
        ['label' => 'Drivers with work', 'value' => $driversWithWork],
        ['label' => 'Incomplete loads', 'value' => $incompleteLoads],
        ['label' => 'In transit', 'value' => $inTransit],
    ];

    if (($ccStages['delivery']['state'] ?? '') === 'unknown' || ($ccStages['load']['state'] ?? '') === 'unknown') {
        $dispatchStage['ui_state'] = 'unavailable';
        $dispatchStage['summary'] = 'Dispatch data unavailable';
    } elseif ($dailyOrderCount === 0) {
        $dispatchStage['ui_state'] = 'empty';
        $dispatchStage['summary'] = 'No orders to assign';
    } elseif ($unassigned > 0 || $incompleteLoads > 0) {
        $dispatchStage['ui_state'] = 'needs_attention';
        $parts = [];
        if ($unassigned > 0) {
            $parts[] = $unassigned . ' unassigned';
        }
        if ($incompleteLoads > 0) {
            $parts[] = $incompleteLoads . ' load'
                . ($incompleteLoads === 1 ? '' : 's') . ' incomplete';
        }
        $dispatchStage['summary'] = implode(' · ', $parts);
        if ($unassigned > 0) {
            $dispatchStage['action_label'] = 'Open Driver Assignment';
            $dispatchStage['href'] = $links['driver_assignment'];
        } else {
            $loadParams = ['attention' => 'incomplete'];
            $focusDriverId = (int)($ccStages['load']['focus_driver_id'] ?? 0);
            if ($focusDriverId > 0) {
                $loadParams['driver_id'] = $focusDriverId;
            }
            $dispatchStage['action_label'] = 'Open Driver Pickup Loads';
            $dispatchStage['href'] = bakery_ops_link_driver_load($date, $loadParams, 'daily_run');
        }
    } elseif ($inTransit > 0) {
        $dispatchStage['ui_state'] = 'in_progress';
        $dispatchStage['summary'] = $driversWithWork . ' driver'
            . ($driversWithWork === 1 ? '' : 's') . ' · ' . $inTransit . ' in transit';
        $dispatchStage['action_label'] = 'Open Driver Pickup Loads';
        $dispatchStage['href'] = $links['driver_load'];
    } elseif ($driversWithWork > 0 && $incompleteLoads === 0 && $unassigned === 0) {
        $dispatchStage['ui_state'] = 'complete';
        $dispatchStage['summary'] = $driversWithWork . ' driver'
            . ($driversWithWork === 1 ? '' : 's') . ' assigned and loaded';
    } else {
        $dispatchStage['ui_state'] = 'not_started';
        $dispatchStage['summary'] = 'Assignments not started';
    }

    // --- Stage 6: Deliver & Reconcile ---
    $deliverStage = [
        'key' => 'deliver',
        'step' => 6,
        'label' => 'Deliver & Reconcile',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => 'Open My Route',
        'href' => $links['driver_route'],
    ];

    $pending = (int)($ccStages['delivery']['metrics']['pending']['value'] ?? 0);
    $deliveredStops = (int)($ccStages['delivery']['metrics']['delivered']['value'] ?? 0);
    $failed = (int)($ccStages['delivery']['metrics']['failed']['value'] ?? 0);
    $qtyVariance = (int)($ccStages['delivery']['metrics']['qty_variance']['value'] ?? 0);
    $unconfirmed = (int)($ccStages['invoice']['metrics']['unconfirmed']['value'] ?? 0);

    $routesOpen = 0;
    $routesClosed = 0;
    $closeoutReady = function_exists('bakery_inventory_closeout_ready')
        && bakery_inventory_closeout_ready($db);
    if ($closeoutReady && function_exists('bakery_inventory_closeout_stats')) {
        try {
            $closeoutStats = bakery_inventory_closeout_stats($db, $date);
            $routesOpen = (int)($closeoutStats['unreconciled'] ?? 0);
            $routesClosed = (int)($closeoutStats['reconciled'] ?? 0);
        } catch (Throwable $e) {
            error_log('daily_run route closeout: ' . $e->getMessage());
            $sectionErrors['route_closeout'] = bakery_dashboard_safe_error_message($e);
        }
    }

    $deliverStage['metrics'] = [
        ['label' => 'Pending stops', 'value' => $pending],
        ['label' => 'In transit', 'value' => $inTransit],
        ['label' => 'Delivered', 'value' => $deliveredStops],
        ['label' => 'Failed', 'value' => $failed],
        ['label' => 'Routes open', 'value' => $closeoutReady ? $routesOpen : '—'],
        ['label' => 'Routes closed', 'value' => $closeoutReady ? $routesClosed : '—'],
    ];

    if (($ccStages['delivery']['state'] ?? '') === 'unknown') {
        $deliverStage['ui_state'] = 'unavailable';
        $deliverStage['summary'] = 'Delivery data unavailable';
    } elseif ($dailyOrderCount === 0 && $deliveredStops === 0) {
        $deliverStage['ui_state'] = 'empty';
        $deliverStage['summary'] = 'No deliveries';
    } elseif ($failed > 0 || $unassigned > 0) {
        $deliverStage['ui_state'] = 'needs_attention';
        $deliverStage['summary'] = $ccStages['delivery']['summary'] ?? 'Delivery exceptions';
    } elseif ($pending > 0 || $inTransit > 0) {
        $deliverStage['ui_state'] = 'in_progress';
        $deliverStage['summary'] = ($pending + $inTransit) . ' open · ' . $deliveredStops . ' delivered';
    } elseif ($closeoutReady && $routesOpen > 0) {
        $deliverStage['ui_state'] = 'needs_attention';
        $deliverStage['summary'] = $routesOpen . ' route'
            . ($routesOpen === 1 ? '' : 's') . ' still need closeout';
        $deliverStage['action_label'] = 'Open Route Closeout';
        $deliverStage['href'] = bakery_ops_link_route_closeout($date, ['attention' => 'open'], 'daily_run');
        $blockers[] = bakery_ops_exception([
            'type' => 'route_unreconciled',
            'severity' => 'critical',
            'category' => 'delivery',
            'stage' => 'deliver',
            'title' => 'Routes not closed out',
            'detail' => $routesOpen . ' driver route'
                . ($routesOpen === 1 ? '' : 's')
                . ' still need loaded vs delivered vs returned vs waste reconciliation.',
            'count' => $routesOpen,
            'href' => bakery_ops_link_route_closeout($date, ['attention' => 'open'], 'daily_run'),
            'action' => 'Open Route Closeout',
        ]);
    } elseif ($qtyVariance > 0 || $unconfirmed > 0) {
        $deliverStage['ui_state'] = 'needs_attention';
        $parts = [];
        if ($qtyVariance > 0) {
            $parts[] = $qtyVariance . ' qty variance';
        }
        if ($unconfirmed > 0) {
            $parts[] = $unconfirmed . ' unconfirmed';
        }
        $deliverStage['summary'] = implode(' · ', $parts);
        $deliverStage['action_label'] = 'Review in Billing Center';
        $deliverStage['href'] = $links['invoice'];
    } elseif ($deliveredStops > 0 && $pending === 0 && $inTransit === 0
        && (!$closeoutReady || $routesOpen === 0)) {
        $deliverStage['ui_state'] = 'complete';
        $deliverStage['summary'] = $deliveredStops . ' stop'
            . ($deliveredStops === 1 ? '' : 's') . ' delivered'
            . ($closeoutReady && $routesClosed > 0
                ? ' · ' . $routesClosed . ' route' . ($routesClosed === 1 ? '' : 's') . ' closed'
                : ' and reconciled');
    } else {
        $deliverStage['ui_state'] = 'not_started';
        $deliverStage['summary'] = 'Delivery not started';
    }

    // --- Stage 7: Invoice ---
    $invoiceStage = [
        'key' => 'invoice',
        'step' => 7,
        'label' => 'Invoice',
        'ui_state' => 'not_started',
        'summary' => '',
        'metrics' => [],
        'blockers' => [],
        'action_label' => function_exists('bakery_t') ? bakery_t('orders.open_billing_center') : 'Open Billing Center',
        'href' => $links['invoice'],
    ];

    $uninvoiced = (int)($ccStages['invoice']['metrics']['uninvoiced']['value'] ?? 0);
    $invoiced = (int)($ccStages['invoice']['metrics']['invoiced']['value'] ?? 0);
    $deliveredOrders = (int)($ccStages['invoice']['metrics']['delivered_orders']['value'] ?? 0);
    $sentInvoices = 0;
    if (function_exists('column_exists') && column_exists($db, 'daily_orders', 'invoice_sent_at')) {
        try {
            $sentStmt = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE order_date = ? AND invoice_sent_at IS NOT NULL');
            $sentStmt->execute([$date]);
            $sentInvoices = (int)$sentStmt->fetchColumn();
        } catch (Throwable $e) {
            $sentInvoices = 0;
        }
    }

    $invoiceStage['metrics'] = [
        ['label' => 'Delivered orders', 'value' => $deliveredOrders],
        ['label' => 'Uninvoiced', 'value' => $uninvoiced],
        ['label' => 'Invoiced', 'value' => $invoiced],
        ['label' => 'Unconfirmed', 'value' => $unconfirmed],
        ['label' => function_exists('bakery_t') ? bakery_t('daily_run.invoice_sent') : 'Sent', 'value' => $sentInvoices],
    ];

    if (($ccStages['invoice']['state'] ?? '') === 'unknown') {
        $invoiceStage['ui_state'] = 'unavailable';
        $invoiceStage['summary'] = 'Invoice data unavailable';
    } elseif ($deliveredOrders === 0 && $invoiced === 0) {
        $invoiceStage['ui_state'] = 'empty';
        $invoiceStage['summary'] = 'Nothing to invoice yet';
    } elseif ($uninvoiced > 0 || $unconfirmed > 0) {
        $invoiceStage['ui_state'] = 'needs_attention';
        $invoiceStage['summary'] = $ccStages['invoice']['summary'] ?? ($uninvoiced . ' uninvoiced');
    } elseif ($invoiced > 0) {
        $invoiceStage['ui_state'] = 'complete';
        $invoiceStage['summary'] = $invoiced . ' order' . ($invoiced === 1 ? '' : 's') . ' invoiced';
    } else {
        $invoiceStage['ui_state'] = 'not_started';
        $invoiceStage['summary'] = 'Invoicing not started';
    }

    // --- Stage 8: Close the Day ---
    $stages = [$demandStage, $planStage, $produceStage, $packStage, $dispatchStage, $deliverStage, $invoiceStage];

    $progressComplete = 0;
    $progressTotal = count($stages);
    foreach ($stages as $stage) {
        if (bakery_daily_run_counts_toward_progress($stage['ui_state'])) {
            $progressComplete++;
        }
    }

    // Merge stage-specific blockers with shared command-center blockers.
    $blockers = bakery_ops_merge_blockers($blockers, $ccBlockers);

    // Deduplicate blockers (legacy key fallback).
    $uniqueBlockers = $blockers;

    $criticalBlockers = array_filter($uniqueBlockers, static function ($b) {
        return ($b['severity'] ?? '') === 'critical';
    });
    $warningBlockers = array_filter($uniqueBlockers, static function ($b) {
        return ($b['severity'] ?? '') === 'warning';
    });

    $operationalComplete = $progressComplete === $progressTotal
        && count($criticalBlockers) === 0
        && count($warningBlockers) === 0;

    $closeoutRow = bakery_daily_run_get_closeout($db, $date) ?? [];
    $isClosed = bakery_daily_run_is_closed($closeoutRow);
    $staleCloseout = $isClosed && !$operationalComplete;

    $closeBlockers = [];
    if (!$operationalComplete) {
        foreach ($uniqueBlockers as $b) {
            if (($b['severity'] ?? '') === 'critical' || ($b['severity'] ?? '') === 'warning') {
                $closeBlockers[] = $b;
            }
        }
        foreach ($stages as $stage) {
            if (!in_array($stage['ui_state'], ['complete', 'empty'], true)) {
                $closeBlockers[] = [
                    'severity' => 'info',
                    'stage' => 'closeout',
                    'title' => $stage['label'] . ' not complete',
                    'detail' => $stage['summary'] !== '' ? $stage['summary'] : 'This stage still needs work.',
                    'href' => $stage['href'],
                    'action' => $stage['action_label'],
                ];
            }
        }
    }

    $closeStage = [
        'key' => 'closeout',
        'step' => 8,
        'label' => 'Close the Day',
        'ui_state' => $isClosed ? ($staleCloseout ? 'needs_attention' : 'complete') : ($operationalComplete ? 'ready' : 'needs_attention'),
        'summary' => $isClosed
            ? ($staleCloseout ? 'Closed, but the day now has new exceptions' : 'Manager closeout recorded')
            : ($operationalComplete ? 'Ready to close' : 'Not ready to close'),
        'metrics' => [
            ['label' => 'Stages complete', 'value' => $progressComplete . ' of ' . $progressTotal],
            ['label' => 'Critical blockers', 'value' => count($criticalBlockers)],
            ['label' => 'Warnings', 'value' => count($warningBlockers)],
        ],
        'blockers' => $closeBlockers,
        'action_label' => $isClosed ? 'Review Closeout' : 'Close This Day',
        'href' => $links['daily_run'] . '#closeout',
        'operational_complete' => $operationalComplete,
        'is_closed' => $isClosed,
        'stale_closeout' => $staleCloseout,
        'closeout' => $closeoutRow,
    ];

    $stages[] = $closeStage;

    // Next action: first non-complete stage in sequence, unless a later in_progress stage exists.
    $nextAction = null;
    $laterInProgress = null;
    foreach ($stages as $idx => $stage) {
        if ($stage['key'] === 'closeout') {
            continue;
        }
        if ($stage['ui_state'] === 'in_progress' && $laterInProgress === null && $idx > 0) {
            $laterInProgress = $stage;
        }
        if ($nextAction === null && !in_array($stage['ui_state'], ['complete', 'empty'], true)) {
            $nextAction = $stage;
        }
    }
    if ($nextAction === null && !$isClosed && $operationalComplete) {
        $nextAction = $closeStage;
    }

    return [
        'date' => $date,
        'weekday' => $weekday,
        'week_start' => $weekStart,
        'stages' => $stages,
        'blockers' => $uniqueBlockers,
        'close_blockers' => $closeBlockers,
        'section_errors' => array_merge($sectionErrors, $commandCenter['section_errors'] ?? []),
        'progress' => [
            'complete' => $progressComplete,
            'total' => $progressTotal,
            'label' => $progressComplete . ' of ' . $progressTotal . ' stages complete',
        ],
        'next_action' => $nextAction,
        'later_in_progress' => $laterInProgress,
        'operational_complete' => $operationalComplete,
        'closeout_ready' => bakery_daily_run_closeout_ready($db),
        'closeout' => $closeoutRow,
        'is_closed' => $isClosed,
        'stale_closeout' => $staleCloseout,
        'links' => $links,
        'demand_review' => $demandReview,
        'inventory_ready' => $inventoryReady,
    ];
}
