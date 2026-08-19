<?php
/**
 * Demand confirmation — the "Tomorrow, confirmed" ritual.
 *
 * A manager confirms that the dated demand for one operating date has been
 * reviewed and is ready for the next stage. When the demand_confirmations
 * table is installed, Daily Run stage 1 is complete only after confirmation,
 * which hard-gates later-stage closeout progress. Drift after confirmation is
 * derived from operational_events, not stored.
 *
 * Runtime-tolerant: when the demand_confirmations table is missing, state
 * lookups report 'available' => false and Daily Run falls back to
 * generation-completeness only (mirrors the operational_timeline / closeout
 * patterns).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/demand_review.php';
if (file_exists(__DIR__ . '/operational_timeline.php')) {
    require_once __DIR__ . '/operational_timeline.php';
}

function bakery_demand_confirmation_ready(PDO $db): bool
{
    return table_exists($db, 'demand_confirmations');
}

/**
 * Create the table from the schema file when missing (local/dev convenience;
 * production gets it via scripts/run_migrations.php).
 */
function bakery_demand_confirmation_ensure(PDO $db): void
{
    if (bakery_demand_confirmation_ready($db)) {
        return;
    }
    $path = dirname(__DIR__) . '/database/schema/031_demand_confirmations.sql';
    if (!is_readable($path)) {
        return;
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        return;
    }
    $db->exec($sql);
}

/**
 * Fetch the confirmation row for a date, or null.
 */
function bakery_demand_confirmation_get(PDO $db, string $date): ?array
{
    if (!bakery_demand_confirmation_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT operating_date, confirmed_at, confirmed_by_user_id,
               customers_count, units_count
        FROM demand_confirmations
        WHERE operating_date = ?
        LIMIT 1
    ');
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Event types that mean "demand for this date changed". Status progression
 * and delivery events are deliberately excluded — they are execution, not demand.
 */
function bakery_demand_change_event_types(): array
{
    return [
        'daily_order_generated',
        'daily_order_quantity_changed',
        'daily_order_item_added',
        'daily_order_cleared',
        'portal_daily_changed',
        'portal_delivery_skipped',
        'portal_delivery_unskipped',
        'portal_pause_created',
        'portal_pause_removed',
        'portal_change_requested',
    ];
}

/**
 * Demand-affecting events recorded after a confirmation.
 *
 * @return array{count:int, latest:?string, examples:list<string>}
 */
function bakery_demand_changes_since(PDO $db, string $date, string $confirmedAt): array
{
    $empty = ['count' => 0, 'latest' => null, 'examples' => []];
    if (!function_exists('bakery_operational_events_ready') || !bakery_operational_events_ready($db)) {
        return $empty;
    }
    $types = bakery_demand_change_event_types();
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $stmt = $db->prepare("
        SELECT summary, occurred_at
        FROM operational_events
        WHERE operational_date = ?
          AND occurred_at > ?
          AND event_type IN ({$placeholders})
        ORDER BY occurred_at DESC
        LIMIT 50
    ");
    $stmt->execute(array_merge([$date, $confirmedAt], $types));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $examples = [];
    foreach (array_slice($rows, 0, 3) as $row) {
        $examples[] = (string)$row['summary'];
    }

    return [
        'count' => count($rows),
        'latest' => $rows[0]['occurred_at'] ?? null,
        'examples' => $examples,
    ];
}

/**
 * Composite confirmation state for one date (read-only, no review build).
 *
 * @return array{available:bool, confirmation:?array, changed_since:array}
 */
function bakery_demand_confirmation_state(PDO $db, string $date): array
{
    $confirmation = bakery_demand_confirmation_get($db, $date);
    $changedSince = ['count' => 0, 'latest' => null, 'examples' => []];
    if ($confirmation !== null && !empty($confirmation['confirmed_at'])) {
        $changedSince = bakery_demand_changes_since($db, $date, (string)$confirmation['confirmed_at']);
    }
    return [
        'available' => bakery_demand_confirmation_ready($db),
        'confirmation' => $confirmation,
        'changed_since' => $changedSince,
    ];
}

/**
 * Whether a demand-review summary is in a confirmable shape: dated orders
 * exist and no standing customer is left without a dated order. Changed,
 * one-off, and paused rows are review inputs, not blockers.
 */
function bakery_demand_is_confirmable(array $summary): bool
{
    return (int)$summary['customers_with_daily'] > 0
        && (int)$summary['missing_daily'] === 0
        && (int)$summary['empty_daily'] === 0;
}

/**
 * Record (or refresh) the manager's demand confirmation for a date.
 *
 * @throws RuntimeException when the date has no confirmable demand
 */
function bakery_demand_confirmation_confirm(PDO $db, string $date, ?int $userId): array
{
    bakery_demand_confirmation_ensure($db);
    if (!bakery_demand_confirmation_ready($db)) {
        throw new RuntimeException('Demand confirmation is not installed. Run database migrations.');
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new RuntimeException('Invalid operating date');
    }

    $review = bakery_demand_review_build($db, $date, []);
    $summary = $review['summary'];
    if (!bakery_demand_is_confirmable($summary)) {
        throw new RuntimeException(
            'Demand cannot be confirmed yet: generate dated orders for every standing customer first.'
        );
    }

    $customers = (int)$summary['customers_with_daily'];
    $units = (int)$summary['daily_units'];

    $stmt = $db->prepare('
        INSERT INTO demand_confirmations
            (operating_date, confirmed_at, confirmed_by_user_id, customers_count, units_count)
        VALUES (?, NOW(), ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            confirmed_at = NOW(),
            confirmed_by_user_id = VALUES(confirmed_by_user_id),
            customers_count = VALUES(customers_count),
            units_count = VALUES(units_count)
    ');
    $stmt->execute([$date, $userId, $customers, $units]);

    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DEMAND_CONFIRMED')) {
        bakery_record_operational_event($db, BAKERY_OP_DEMAND_CONFIRMED, 'Manager confirmed demand for ' . $date, [
            'operational_date' => $date,
            'metadata' => [
                'customers_count' => $customers,
                'units_count' => $units,
            ],
        ]);
    }

    return [
        'customers_count' => $customers,
        'units_count' => $units,
        'changed' => (int)$summary['changed'],
        'one_off' => (int)$summary['one_off'],
        'paused' => (int)$summary['paused'],
    ];
}

/**
 * Compact readiness snapshot for one date — powers the dashboard
 * tomorrow strip. Reuses the demand-review summary; no new exception system.
 *
 * state: no_demand | not_generated | incomplete | ready_unconfirmed
 *      | confirmed | confirmed_with_changes | unavailable
 */
function bakery_demand_readiness(PDO $db, string $date): array
{
    $dayName = date('l', strtotime($date));
    $base = [
        'date' => $date,
        'day_name' => $dayName,
        'state' => 'unavailable',
        'expected_customers' => 0,
        'customers_with_daily' => 0,
        'daily_units' => 0,
        'changed' => 0,
        'one_off' => 0,
        'paused' => 0,
        'missing_daily' => 0,
        'empty_daily' => 0,
        'confirmable' => false,
        'confirmation' => null,
        'changed_since' => ['count' => 0, 'latest' => null, 'examples' => []],
    ];

    try {
        if (!table_exists($db, 'daily_orders')) {
            return $base;
        }
        $review = bakery_demand_review_build($db, $date, []);
    } catch (Throwable $e) {
        error_log('demand readiness: ' . $e->getMessage());
        return $base;
    }

    $summary = $review['summary'];
    $confirmationState = bakery_demand_confirmation_state($db, $date);

    $base['expected_customers'] = (int)$summary['expected_customers'];
    $base['customers_with_daily'] = (int)$summary['customers_with_daily'];
    $base['daily_units'] = (int)$summary['daily_units'];
    $base['changed'] = (int)$summary['changed'];
    $base['one_off'] = (int)$summary['one_off'];
    $base['paused'] = (int)$summary['paused'];
    $base['missing_daily'] = (int)$summary['missing_daily'];
    $base['empty_daily'] = (int)$summary['empty_daily'];
    $base['confirmable'] = bakery_demand_is_confirmable($summary);
    $base['confirmation'] = $confirmationState['confirmation'];
    $base['changed_since'] = $confirmationState['changed_since'];

    $hasDemand = $base['expected_customers'] > 0 || $base['customers_with_daily'] > 0;
    if (!$hasDemand) {
        $base['state'] = 'no_demand';
    } elseif ($base['customers_with_daily'] === 0) {
        $base['state'] = 'not_generated';
    } elseif ($base['missing_daily'] > 0 || $base['empty_daily'] > 0) {
        $base['state'] = 'incomplete';
    } elseif ($confirmationState['confirmation'] === null) {
        $base['state'] = 'ready_unconfirmed';
    } elseif ($confirmationState['changed_since']['count'] > 0) {
        $base['state'] = 'confirmed_with_changes';
    } else {
        $base['state'] = 'confirmed';
    }

    return $base;
}

/**
 * Manager lookahead: Tuesday bake drives Wednesday's route.
 * Dated demand is already filled by the rolling horizon; this strip only
 * orients the next two operating dates.
 *
 * @param 'dashboard'|'daily_run' $returnKey
 */
function bakery_render_demand_cadence_strip(PDO $db, string $fromDate, string $returnKey = 'dashboard'): void
{
    if (!function_exists('bakery_demand_cadence_dates')) {
        require_once __DIR__ . '/daily_order_generation.php';
    }
    if (!function_exists('bakery_ops_link_daily_orders')) {
        require_once __DIR__ . '/operational_exceptions.php';
    }

    $cadence = bakery_demand_cadence_dates($fromDate);
    $routeDate = $cadence['route_date'];
    $bakeDay = $cadence['bake_day'];
    $productionDate = $cadence['production_date'];
    $readiness = bakery_demand_readiness($db, $routeDate);
    $state = (string)($readiness['state'] ?? 'unavailable');
    $bakeDt = new DateTime($bakeDay);
    $routeDt = new DateTime($routeDate);
    $dayNames = function_exists('bakery_day_names') ? bakery_day_names() : [];
    $bakeDow = function_exists('bakery_standing_day_from_date') ? bakery_standing_day_from_date($bakeDay) : (int)$bakeDt->format('N');
    $routeDow = function_exists('bakery_standing_day_from_date') ? bakery_standing_day_from_date($routeDate) : (int)$routeDt->format('N');
    $bakeLabel = trim(($dayNames[$bakeDow] ?? $bakeDt->format('l')) . ', ' . (function_exists('bakery_localized_month_day') ? bakery_localized_month_day($bakeDt) : $bakeDt->format('M j')));
    $routeLabel = trim(($dayNames[$routeDow] ?? $routeDt->format('l')) . ', ' . (function_exists('bakery_localized_month_day') ? bakery_localized_month_day($routeDt) : $routeDt->format('M j')));
    $units = number_format((int)($readiness['daily_units'] ?? 0));
    $customers = (int)($readiness['customers_with_daily'] ?? 0);

    $confirmHref = bakery_ops_link_daily_orders($routeDate, ['review' => 'differences'], $returnKey);
    $productionHref = bakery_ops_link_production($productionDate, [], $returnKey);
    $routeHref = bakery_ops_link_driver_assignment($routeDate, [], $returnKey);
    $runHref = (defined('BASE_URL') ? BASE_URL : '') . 'daily_run.php?date=' . rawurlencode($routeDate);

    echo '<section class="ops-cadence" aria-label="' . htmlspecialchars(bakery_t('cadence.aria'), ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="ops-cadence-head">';
    echo '<strong>' . htmlspecialchars(bakery_t('cadence.title')) . '</strong>';
    echo '<span>' . htmlspecialchars(bakery_t('cadence.lead', [
        'bake_day' => $bakeLabel,
        'route_day' => $routeLabel,
    ])) . '</span>';
    echo '</div>';
    echo '<ol class="ops-cadence-legs">';

    echo '<li class="ops-cadence-leg">';
    echo '<span class="ops-cadence-kicker">' . htmlspecialchars(bakery_t('cadence.confirm_kicker')) . '</span>';
    echo '<a href="' . htmlspecialchars($confirmHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($routeLabel) . '</a>';
    echo '<span class="ops-cadence-meta">' . htmlspecialchars(bakery_t('cadence.confirm_meta', [
        'customers' => $customers,
        'units' => $units,
        'state' => bakery_t('cadence.state.' . $state),
    ])) . '</span>';
    echo '</li>';

    echo '<li class="ops-cadence-leg">';
    echo '<span class="ops-cadence-kicker">' . htmlspecialchars(bakery_t('cadence.bake_kicker')) . '</span>';
    echo '<a href="' . htmlspecialchars($productionHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($bakeLabel) . '</a>';
    echo '<span class="ops-cadence-meta">' . htmlspecialchars(bakery_t('cadence.bake_meta', [
        'route_day' => $routeLabel,
    ])) . '</span>';
    echo '</li>';

    echo '<li class="ops-cadence-leg">';
    echo '<span class="ops-cadence-kicker">' . htmlspecialchars(bakery_t('cadence.route_kicker')) . '</span>';
    echo '<a href="' . htmlspecialchars($routeHref, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($routeLabel) . '</a>';
    echo '<span class="ops-cadence-meta"><a href="' . htmlspecialchars($runHref, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars(bakery_t('cadence.open_run')) . '</a></span>';
    echo '</li>';

    echo '</ol>';
    echo '<p class="ops-cadence-note">' . htmlspecialchars(bakery_t('cadence.horizon_note', [
        'days' => bakery_demand_horizon_days(),
    ])) . '</p>';
    echo '</section>';
}
