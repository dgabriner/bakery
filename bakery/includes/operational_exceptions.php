<?php
/**
 * Operational exceptions — shared contract for actionable problems across modules.
 *
 * Small DTO-like arrays; not a workflow framework. Used by dashboard, daily run,
 * daily brief, and destination pages for consistent deep links and inline actions.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** @var array<string, string> Safe internal return targets (key => page stem). */
function bakery_ops_return_targets(): array
{
    return [
        'daily_run' => 'daily_run.php',
        'dashboard' => 'index.php',
        'daily_brief' => 'daily_brief.php',
        'manager' => 'manager.php',
    ];
}

/** @var array<string, string> Human labels for return targets. */
function bakery_ops_return_labels(): array
{
    return [
        'daily_run' => 'Daily Run',
        'dashboard' => 'Operations Dashboard',
        'daily_brief' => 'Daily Brief',
        'manager' => 'Manager Mode',
    ];
}

/**
 * Resolve a safe return target from a short key.
 *
 * @return array{key:string, href:string, label:string, date:string}|null
 */
function bakery_ops_return_resolve(?string $returnKey, string $date): ?array
{
    $key = trim((string)$returnKey);
    if ($key === '') {
        return null;
    }
    $targets = bakery_ops_return_targets();
    if (!isset($targets[$key])) {
        return null;
    }
    $base = defined('BASE_URL') ? BASE_URL : '';
    $labels = bakery_ops_return_labels();
    return [
        'key' => $key,
        'href' => $base . $targets[$key] . '?date=' . rawurlencode($date),
        'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
        'date' => $date,
    ];
}

/**
 * Append return= key to a module URL when navigating from a workflow page.
 */
function bakery_ops_link_append_return(string $href, ?string $returnKey): string
{
    $key = trim((string)$returnKey);
    if ($key === '' || !isset(bakery_ops_return_targets()[$key])) {
        return $href;
    }
    if (preg_match('/(?:^|[?&])return=/', $href)) {
        return $href;
    }
    $sep = strpos($href, '?') !== false ? '&' : '?';
    return $href . $sep . 'return=' . rawurlencode($key);
}

/**
 * Map exception category to Daily Run stage key.
 */
function bakery_ops_category_stage(string $category): string
{
    static $map = [
        'demand' => 'confirm_demand',
        'production' => 'production_plan',
        'pack' => 'pack',
        'load' => 'dispatch',
        'delivery' => 'dispatch',
        'invoice' => 'invoice',
        'service' => 'deliver',
        'ingredient' => 'production_plan',
    ];
    return $map[$category] ?? 'confirm_demand';
}

/**
 * Build a normalized operational exception array.
 *
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function bakery_ops_exception(array $fields): array
{
    $severity = (string)($fields['severity'] ?? 'warning');
    if (!in_array($severity, ['critical', 'warning', 'info'], true)) {
        $severity = 'warning';
    }
    $category = (string)($fields['category'] ?? 'general');
    $type = (string)($fields['type'] ?? $category);

    $ex = [
        'type' => $type,
        'severity' => $severity,
        'category' => $category,
        'stage' => (string)($fields['stage'] ?? bakery_ops_category_stage($category)),
        'title' => (string)($fields['title'] ?? ''),
        'detail' => (string)($fields['detail'] ?? ''),
        'count' => array_key_exists('count', $fields) ? $fields['count'] : null,
        'href' => isset($fields['href']) ? (string)$fields['href'] : null,
        'action' => (string)($fields['action'] ?? 'Open'),
        'resolution' => (string)($fields['resolution'] ?? 'deep_link'),
        'context' => is_array($fields['context'] ?? null) ? $fields['context'] : [],
    ];

    if (!empty($fields['inline_action']) && is_array($fields['inline_action'])) {
        $ex['inline_action'] = $fields['inline_action'];
        if (($ex['resolution'] ?? '') === 'deep_link') {
            $ex['resolution'] = 'inline';
        }
    }

    foreach (['customer_id', 'product_id', 'driver_id', 'daily_order_id', 'invoice_id'] as $idField) {
        if (isset($fields[$idField])) {
            $ex['context'][$idField] = (int)$fields[$idField];
        }
    }

    return $ex;
}

/**
 * Deep-link builders — consistent filter params per destination module.
 */
function bakery_ops_link_daily_orders(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'daily_orders.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_production_center(string $weekStart, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['week' => $weekStart], $params);
    $href = $base . 'production_center.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_production(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'production.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_inventory(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'inventory.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_pack_list(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'pack_list.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_driver_assignment(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'driver_assignment.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_driver_load(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'driver_load.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_route_closeout(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'route_closeout.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_billing(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $defaults = [
        'panel' => 'invoices',
        'range' => 'custom',
        'start_date' => $date,
        'end_date' => $date,
    ];
    $q = array_merge($defaults, $params);
    $href = $base . 'billing_center.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_service_issues(array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $href = $base . 'service_issues.php?' . http_build_query($params);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_ingredient_planner(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date, 'source' => 'demand'], $params);
    $href = $base . 'ingredient_requirements.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_driver_list(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'driver_list.php?' . http_build_query($q);
    return bakery_ops_link_append_return($href, $returnKey);
}

function bakery_ops_link_manager(string $date, array $params = [], ?string $returnKey = null): string
{
    $base = defined('BASE_URL') ? BASE_URL : '';
    $fragment = '';
    if (isset($params['fragment'])) {
        $fragment = '#' . ltrim((string)$params['fragment'], '#');
        unset($params['fragment']);
    }
    $q = array_merge(['date' => $date], $params);
    $href = $base . 'manager.php?' . http_build_query($q);
    $href = bakery_ops_link_append_return($href, $returnKey);
    return $href . $fragment;
}

/**
 * Enrich legacy/simple exceptions with stage + typed deep links.
 *
 * @param list<array<string, mixed>> $exceptions
 * @return list<array<string, mixed>>
 */
function bakery_ops_enrich_exceptions(array $exceptions, string $date, ?string $returnKey = null): array
{
    if (function_exists('bakery_dashboard_week_start_monday')) {
        $weekStart = bakery_dashboard_week_start_monday($date);
    } elseif (function_exists('bakery_week_start_monday')) {
        $weekStart = bakery_week_start_monday($date);
    } else {
        $ts = strtotime($date) ?: time();
        $weekStart = date('Y-m-d', strtotime('monday this week', $ts));
    }

    $enriched = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        $type = (string)($ex['type'] ?? '');
        $category = (string)($ex['category'] ?? 'general');
        if ($type === '') {
            $type = $category . '_' . preg_replace('/[^a-z0-9]+/', '_', strtolower((string)($ex['title'] ?? 'issue')));
        }

        $fields = $ex;
        $fields['type'] = $type;
        if (empty($fields['stage'])) {
            $fields['stage'] = bakery_ops_category_stage($category);
        }

        // Upgrade generic links to context-preserving deep links when type is known.
        if ($type === 'demand_missing_daily' || strpos($type, 'missing_daily') !== false) {
            $fields['href'] = bakery_ops_link_daily_orders($date, ['review' => 'missing'], $returnKey);
            $fields['action'] = $fields['action'] ?? 'Review missing orders';
            if (empty($fields['inline_action'])) {
                $fields['inline_action'] = [
                    'action' => 'generate_daily_orders',
                    'label' => 'Generate daily orders',
                    'confirm' => 'Generate daily orders from standing for this date? Pauses are respected and dated quantity changes are preserved.',
                ];
            }
        } elseif ($type === 'demand_empty_daily' || $type === 'demand_no_orders') {
            $fields['href'] = bakery_ops_link_daily_orders($date, ['review' => 'empty'], $returnKey);
            if (empty($fields['inline_action'])) {
                $fields['inline_action'] = [
                    'action' => 'generate_daily_orders',
                    'label' => 'Generate daily orders',
                    'confirm' => 'Generate daily orders from standing for this date? Pauses are respected and dated quantity changes are preserved.',
                ];
            }
        } elseif ($type === 'production_fg_shortfall' || $type === 'production_shortfall') {
            $fields['href'] = bakery_ops_link_inventory($date, ['attention' => 'shortfall'], $returnKey);
        } elseif ($type === 'production_plan_short') {
            $fields['href'] = bakery_ops_link_production_center($weekStart, ['attention' => '1', 'date' => $date], $returnKey);
        } elseif ($type === 'load_incomplete') {
            $fields['href'] = bakery_ops_link_driver_load($date, ['attention' => 'incomplete'], $returnKey);
        } elseif ($type === 'route_unreconciled' || $type === 'closeout_routes_open') {
            $fields['href'] = bakery_ops_link_route_closeout($date, ['attention' => 'open'], $returnKey);
            $fields['action'] = $fields['action'] ?? 'Open Route Closeout';
        } elseif ($type === 'delivery_unassigned') {
            $fields['href'] = bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned'], $returnKey);
            if (empty($fields['inline_action'])) {
                $fields['inline_action'] = [
                    'action' => 'assign_from_standing',
                    'label' => 'Build routes from standing',
                    'confirm' => 'Build this date from the standing route? Existing dated assignments for this date will be replaced.',
                ];
            }
        } elseif ($type === 'delivery_failed') {
            $fields['href'] = bakery_ops_link_manager($date, [
                'attention' => 'failed',
                'fragment' => 'failed-stop-recovery',
            ], $returnKey);
            $fields['action'] = $fields['action'] ?? (function_exists('bakery_t') ? bakery_t('ops.chip.recover') : 'Recover');
        } elseif ($type === 'delivery_qty_variance') {
            $fields['href'] = bakery_ops_link_billing($date, ['attention' => 'needs_attention', 'delivered_only' => '1'], $returnKey);
        } elseif ($type === 'invoice_uninvoiced') {
            $fields['href'] = bakery_ops_link_billing($date, ['status' => 'delivered', 'attention' => 'needs_attention'], $returnKey);
        } elseif ($type === 'invoice_unconfirmed') {
            $fields['href'] = bakery_ops_link_billing($date, ['attention' => 'needs_attention'], $returnKey);
        } elseif ($type === 'service_open_issues') {
            $fields['href'] = bakery_ops_link_service_issues(['status' => 'open'], $returnKey);
        } elseif ($type === 'ingredient_alert') {
            $fields['href'] = bakery_ops_link_ingredient_planner($date, ['attention' => 'exceptions'], $returnKey);
        } elseif (!empty($fields['href'])) {
            $fields['href'] = bakery_ops_link_append_return((string)$fields['href'], $returnKey);
        }

        $enriched[] = bakery_ops_exception($fields);
    }

    return $enriched;
}

/**
 * Convert command-center exceptions into Daily Run blockers (dedupe-friendly).
 *
 * @param list<array<string, mixed>> $exceptions
 * @return list<array<string, mixed>>
 */
function bakery_ops_exceptions_to_blockers(array $exceptions): array
{
    $blockers = [];
    foreach ($exceptions as $ex) {
        $blockers[] = [
            'type' => $ex['type'] ?? null,
            'severity' => $ex['severity'] ?? 'warning',
            'stage' => $ex['stage'] ?? bakery_ops_category_stage((string)($ex['category'] ?? '')),
            'category' => $ex['category'] ?? null,
            'title' => $ex['title'] ?? '',
            'detail' => $ex['detail'] ?? '',
            'count' => $ex['count'] ?? null,
            'href' => $ex['href'] ?? null,
            'action' => $ex['action'] ?? 'Open',
            'inline_action' => $ex['inline_action'] ?? null,
            'resolution' => $ex['resolution'] ?? 'deep_link',
            'context' => $ex['context'] ?? [],
        ];
    }
    return $blockers;
}

/**
 * Merge blockers deduplicating by type+stage (preferred) or title+stage.
 *
 * @param list<array<string, mixed>> $primary
 * @param list<array<string, mixed>> $secondary
 * @return list<array<string, mixed>>
 */
function bakery_ops_merge_blockers(array $primary, array $secondary): array
{
    $seen = [];
    $merged = [];
    foreach (array_merge($primary, $secondary) as $b) {
        $type = (string)($b['type'] ?? '');
        $stage = (string)($b['stage'] ?? '');
        $key = $type !== '' ? ($type . '|' . $stage) : (($b['title'] ?? '') . '|' . $stage);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $b;
    }
    $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($merged, static function ($a, $b) use ($severityRank) {
        $ra = $severityRank[$a['severity'] ?? ''] ?? 9;
        $rb = $severityRank[$b['severity'] ?? ''] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        return strcmp($a['title'] ?? '', $b['title'] ?? '');
    });
    return $merged;
}

/**
 * Severity label for manager UI.
 */
function bakery_ops_severity_label(string $severity): string
{
    if ($severity === 'critical') {
        return function_exists('bakery_t') ? bakery_t('dashboard.urgent') : 'Needs action';
    }
    if ($severity === 'warning') {
        return function_exists('bakery_t') ? bakery_t('dashboard.watch') : 'Warning';
    }
    return function_exists('bakery_t') ? bakery_t('dashboard.note') : 'Information';
}

/**
 * Render HTML for one exception row (shared by dashboard, daily run, brief).
 *
 * @param array<string, mixed> $ex
 * @param array{origin?:string, date?:string, show_inline?:bool} $options
 */
function bakery_ops_render_exception_html(array $ex, array $options = []): string
{
    $severity = htmlspecialchars((string)($ex['severity'] ?? 'warning'));
    $title = htmlspecialchars((string)($ex['title'] ?? ''));
    $detail = htmlspecialchars((string)($ex['detail'] ?? ''));
    $count = $ex['count'] ?? null;
    $href = (string)($ex['href'] ?? '');
    $action = htmlspecialchars((string)($ex['action'] ?? (function_exists('bakery_t') ? bakery_t('dashboard.open') : 'Open')));
    $severityLabel = htmlspecialchars(bakery_ops_severity_label((string)($ex['severity'] ?? 'warning')));

    $countHtml = '';
    if ($count !== null) {
        $countHtml = '<span class="ops-exception-count">' . number_format((int)$count) . '</span>';
    } else {
        $countHtml = '<span class="ops-exception-count ops-exception-count-na">?</span>';
    }

    $actionHtml = '';
    if ($href !== '') {
        $actionHtml = '<a class="ops-exception-action" href="' . htmlspecialchars($href) . '">' . $action . '</a>';
    }

    $inlineHtml = '';
    $showInline = !empty($options['show_inline']) && !empty($ex['inline_action']);
    if ($showInline) {
        $ia = $ex['inline_action'];
        $iaAction = htmlspecialchars((string)($ia['action'] ?? ''));
        $iaLabel = htmlspecialchars((string)($ia['label'] ?? 'Fix'));
        $iaConfirm = htmlspecialchars((string)($ia['confirm'] ?? 'Proceed?'));
        $date = htmlspecialchars((string)($options['date'] ?? ($ex['context']['date'] ?? date('Y-m-d'))));
        $returnKey = htmlspecialchars((string)($options['return'] ?? $options['origin'] ?? ''));
        $orderId = (int)($ex['context']['daily_order_id'] ?? ($options['daily_order_id'] ?? 0));
        $inlineHtml = '<form class="ops-inline-action" method="post" action="' . htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'daily_run_api.php') . '"'
            . ' onsubmit="return confirm(' . json_encode($ia['confirm'] ?? 'Proceed?') . ');">'
            . (function_exists('bakery_csrf_field') ? bakery_csrf_field() : '')
            . '<input type="hidden" name="action" value="' . $iaAction . '">'
            . '<input type="hidden" name="operating_date" value="' . $date . '">'
            . '<input type="hidden" name="exception_type" value="' . htmlspecialchars((string)($ex['type'] ?? '')) . '">'
            . ($returnKey !== '' ? '<input type="hidden" name="return" value="' . $returnKey . '">' : '')
            . ($orderId > 0 ? '<input type="hidden" name="daily_order_id" value="' . $orderId . '">' : '')
            . '<button type="submit" class="ops-inline-action-btn">' . $iaLabel . '</button>'
            . '</form>';
    }

    return '<li class="ops-exception severity-' . $severity . '">'
        . '<div class="ops-exception-body">'
        . '<span class="ops-exception-severity">' . $severityLabel . '</span>'
        . '<div class="ops-exception-title">' . $title . '</div>'
        . '<div class="ops-exception-detail">' . $detail . '</div>'
        . '</div>'
        . '<div class="ops-exception-aside">'
        . $countHtml
        . $inlineHtml
        . $actionHtml
        . '</div>'
        . '</li>';
}

/**
 * Attention banner for destination pages arrived via exception deep link.
 *
 * @param array{key:string, href:string, label:string, date:string}|null $returnTarget
 */
function bakery_ops_render_return_banner(?array $returnTarget, string $attentionLabel = ''): string
{
    if ($returnTarget === null && $attentionLabel === '') {
        return '';
    }
    $html = '<div class="ops-attention-banner">';
    if ($attentionLabel !== '') {
        $html .= '<span class="ops-attention-label">' . htmlspecialchars($attentionLabel) . '</span>';
    }
    if ($returnTarget !== null) {
        $label = (string)$returnTarget['label'];
        $dateBit = !empty($returnTarget['date']) ? date('M j', strtotime($returnTarget['date'])) : '';
        if (function_exists('bakery_t')) {
            $backLabel = $dateBit !== ''
                ? bakery_t('ops.back_to_date', ['label' => $label, 'date' => $dateBit])
                : bakery_t('ops.back_to', ['label' => $label]);
        } else {
            $backLabel = $dateBit !== '' ? ('Back to ' . $label . ' — ' . $dateBit) : ('Back to ' . $label);
        }
        $html .= '<a class="ops-return-link" href="' . htmlspecialchars($returnTarget['href']) . '">'
            . htmlspecialchars($backLabel) . '</a>';
        $clearLabel = function_exists('bakery_t') ? bakery_t('ops.show_all') : 'Show all';
        $html .= ' <a class="ops-clear-filter" href="?' . htmlspecialchars(http_build_query(array_diff_key($_GET, ['return' => 1, 'attention' => 1, 'filter' => 1, 'review' => 1]))) . '">'
            . htmlspecialchars($clearLabel) . '</a>';
    }
    $html .= '</div>';
    $html .= '<script>(function(){var el=document.querySelector(".ops-attention-row,.ops-row-chip");if(el&&el.scrollIntoView){try{el.scrollIntoView({block:"nearest",behavior:"smooth"});}catch(e){el.scrollIntoView(true);}}})();</script>';
    return $html;
}

/** @return list<string> */
function bakery_ops_known_exception_types(): array
{
    return [
        'demand_missing_daily',
        'demand_empty_daily',
        'demand_no_orders',
        'production_fg_shortfall',
        'production_plan_short',
        'load_incomplete',
        'route_unreconciled',
        'closeout_routes_open',
        'delivery_unassigned',
        'delivery_failed',
        'delivery_qty_variance',
        'invoice_uninvoiced',
        'invoice_unconfirmed',
        'service_open_issues',
        'ingredient_alert',
    ];
}

/** @return list<string> Canonical destination page stems. */
function bakery_ops_canonical_pages(): array
{
    return [
        'daily_orders.php',
        'production_center.php',
        'production.php',
        'inventory.php',
        'pack_list.php',
        'driver_assignment.php',
        'driver_load.php',
        'route_closeout.php',
        'billing_center.php',
        'service_issues.php',
        'ingredient_requirements.php',
        'manager.php',
    ];
}

function bakery_ops_href_page(string $href): string
{
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $href;
    }
    return basename($path);
}

/**
 * Preserve exception-workflow query keys on destination date navigation.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function bakery_ops_workflow_query(array $extra = []): array
{
    $keep = [];
    foreach (['return', 'attention', 'filter', 'review'] as $key) {
        if (isset($_GET[$key]) && (string)$_GET[$key] !== '') {
            $keep[$key] = (string)$_GET[$key];
        }
    }
    return array_merge($keep, $extra);
}

/**
 * Live exceptions for a date (same contract as dashboard / Daily Run).
 *
 * @return list<array<string, mixed>>
 */
function bakery_ops_exceptions_for_date(PDO $db, string $date, ?string $returnKey = null): array
{
    try {
        if (!function_exists('bakery_dashboard_command_center')) {
            require_once __DIR__ . '/dashboard_command_center.php';
        }
        $center = bakery_dashboard_command_center($db, $date);
        return bakery_ops_enrich_exceptions($center['exceptions'] ?? [], $date, $returnKey);
    } catch (Throwable $e) {
        error_log('ops exceptions for date: ' . $e->getMessage());
        return [];
    }
}

/**
 * Catalog of one-verb situation actions. Inline only when the mutation already exists.
 *
 * @return array<string, array<string, mixed>>
 */
function bakery_ops_situation_catalog(): array
{
    $generateConfirm = function_exists('bakery_t')
        ? bakery_t('dashboard.generate_confirm')
        : 'Generate daily orders from standing for this date? Pauses are respected and dated quantity changes are preserved.';
    $assignConfirm = 'Build this date from the standing route? Existing dated assignments for this date will be replaced.';
    $invoiceConfirm = 'Mark this delivered order invoiced? Amounts stay as the delivery snapshot.';

    return [
        'demand_missing_daily' => [
            'verb_key' => 'ops.chip.generate',
            'inline' => 'generate_daily_orders',
            'confirm' => $generateConfirm,
        ],
        'demand_empty_daily' => [
            'verb_key' => 'ops.chip.generate',
            'inline' => 'generate_daily_orders',
            'confirm' => $generateConfirm,
        ],
        'demand_no_orders' => [
            'verb_key' => 'ops.chip.generate',
            'inline' => 'generate_daily_orders',
            'confirm' => $generateConfirm,
        ],
        'production_plan_short' => [
            'verb_key' => 'ops.chip.plan',
            'inline' => null,
        ],
        'production_fg_shortfall' => [
            'verb_key' => 'ops.chip.bake',
            'inline' => null,
        ],
        'load_incomplete' => [
            'verb_key' => 'ops.chip.load',
            'inline' => null,
        ],
        'delivery_unassigned' => [
            'verb_key' => 'ops.chip.assign',
            'inline' => 'assign_from_standing',
            'confirm' => $assignConfirm,
        ],
        'delivery_failed' => [
            'verb_key' => 'ops.chip.recover',
            'inline' => null,
        ],
        'delivery_qty_variance' => [
            'verb_key' => 'ops.chip.review',
            'inline' => null,
        ],
        'invoice_uninvoiced' => [
            'verb_key' => 'ops.chip.invoice',
            'inline' => 'mark_invoiced',
            'confirm' => $invoiceConfirm,
            'needs_order_id' => true,
        ],
        'invoice_unconfirmed' => [
            'verb_key' => 'ops.chip.review',
            'inline' => null,
        ],
        'service_open_issues' => [
            'verb_key' => 'ops.chip.issues',
            'inline' => null,
        ],
        'route_unreconciled' => [
            'verb_key' => 'ops.chip.closeout',
            'inline' => null,
        ],
        'closeout_routes_open' => [
            'verb_key' => 'ops.chip.closeout',
            'inline' => null,
        ],
        'ingredient_alert' => [
            'verb_key' => 'ops.chip.review',
            'inline' => null,
        ],
    ];
}

function bakery_ops_row_has_ids(array $row): bool
{
    foreach (['customer_id', 'product_id', 'driver_id', 'daily_order_id'] as $field) {
        if ((int)($row[$field] ?? 0) > 0) {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string, mixed> $exception
 * @param array<string, mixed> $row
 */
function bakery_ops_exception_matches_row(array $exception, array $row): bool
{
    $ctx = is_array($exception['context'] ?? null) ? $exception['context'] : [];
    $idFields = ['customer_id', 'product_id', 'driver_id', 'daily_order_id'];
    $matchedOnId = false;
    $hadExId = false;
    foreach ($idFields as $field) {
        $rowId = (int)($row[$field] ?? 0);
        $exId = (int)($ctx[$field] ?? 0);
        $exIds = [];
        $plural = $field . 's';
        if (isset($ctx[$plural]) && is_array($ctx[$plural])) {
            $exIds = array_map('intval', $ctx[$plural]);
        }
        if ($exId > 0 || $exIds !== []) {
            $hadExId = true;
        }
        if ($rowId > 0 && ($exId === $rowId || in_array($rowId, $exIds, true))) {
            $matchedOnId = true;
        }
    }
    if ($matchedOnId) {
        return true;
    }
    if ($hadExId) {
        return false;
    }

    $flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
    $type = (string)($exception['type'] ?? '');
    $flagMap = [
        'demand_missing_daily' => 'missing_daily',
        'demand_empty_daily' => 'empty_daily',
        'demand_no_orders' => 'empty_daily',
        'production_plan_short' => 'plan_short',
        'production_fg_shortfall' => 'fg_shortfall',
        'load_incomplete' => 'load_incomplete',
        'delivery_unassigned' => 'unassigned',
        'delivery_failed' => 'failed_delivery',
        'delivery_qty_variance' => 'qty_variance',
        'invoice_uninvoiced' => 'uninvoiced',
        'invoice_unconfirmed' => 'unconfirmed',
        'route_unreconciled' => 'route_open',
        'closeout_routes_open' => 'route_open',
        'service_open_issues' => 'open_issue',
        'ingredient_alert' => 'ingredient_alert',
    ];
    $need = $flagMap[$type] ?? null;
    if ($need === null) {
        return false;
    }
    if (!empty($flags[$need]) || !empty($row[$need])) {
        return true;
    }
    return in_array($need, $flags, true);
}

/**
 * Compact chips for a destination-page row. No-op when row context ids are missing.
 *
 * @param list<array<string, mixed>> $exceptions
 * @param array<string, mixed> $row
 * @return list<array<string, mixed>>
 */
function bakery_ops_chips_for_row(array $exceptions, array $row): array
{
    if (!bakery_ops_row_has_ids($row)) {
        return [];
    }
    $chips = [];
    $seen = [];
    foreach ($exceptions as $exception) {
        if (!is_array($exception)) {
            continue;
        }
        $type = (string)($exception['type'] ?? '');
        if ($type === '' || isset($seen[$type])) {
            continue;
        }
        if (!bakery_ops_exception_matches_row($exception, $row)) {
            continue;
        }
        $seen[$type] = true;
        $chips[] = $exception;
    }
    return $chips;
}

/**
 * @param array<string, mixed> $exception
 * @param array{date?:string, return?:string, daily_order_id?:int} $options
 */
function bakery_ops_render_row_chip(array $exception, array $options = []): string
{
    $type = (string)($exception['type'] ?? '');
    $catalog = bakery_ops_situation_catalog()[$type] ?? [
        'verb_key' => 'dashboard.open',
        'inline' => null,
    ];
    $severity = (string)($exception['severity'] ?? 'warning');
    if (!in_array($severity, ['critical', 'warning', 'info'], true)) {
        $severity = 'warning';
    }
    $sevLabel = htmlspecialchars(bakery_ops_severity_label($severity));
    $verb = function_exists('bakery_t')
        ? bakery_t((string)$catalog['verb_key'])
        : (string)($exception['action'] ?? 'Open');
    $openLabel = function_exists('bakery_t') ? bakery_t('ops.open_full_screen') : 'Open full screen';
    $href = (string)($exception['href'] ?? '');
    $date = (string)($options['date'] ?? ($exception['context']['date'] ?? ''));
    $returnKey = (string)($options['return'] ?? '');
    $orderId = (int)($options['daily_order_id'] ?? ($exception['context']['daily_order_id'] ?? 0));

    $primary = '';
    $inlineAction = (string)($catalog['inline'] ?? '');
    $needsOrder = !empty($catalog['needs_order_id']);
    $canInline = $inlineAction !== '' && empty($options['link_only']) && (!$needsOrder || $orderId > 0);
    if ($canInline) {
        $confirm = (string)($catalog['confirm'] ?? 'Proceed?');
        $primary = '<form class="ops-row-chip-form" method="post" action="' . htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'daily_run_api.php') . '"'
            . ' onsubmit="return confirm(' . json_encode($confirm) . ');">'
            . (function_exists('bakery_csrf_field') ? bakery_csrf_field() : '')
            . '<input type="hidden" name="action" value="' . htmlspecialchars($inlineAction) . '">'
            . '<input type="hidden" name="operating_date" value="' . htmlspecialchars($date) . '">'
            . '<input type="hidden" name="exception_type" value="' . htmlspecialchars($type) . '">'
            . ($returnKey !== '' ? '<input type="hidden" name="return" value="' . htmlspecialchars($returnKey) . '">' : '')
            . ($orderId > 0 ? '<input type="hidden" name="daily_order_id" value="' . $orderId . '">' : '')
            . '<button type="submit" class="ops-row-chip-verb">' . htmlspecialchars($verb) . '</button>'
            . '</form>';
    } elseif ($href !== '') {
        $primary = '<a class="ops-row-chip-verb" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($verb) . '</a>';
    } else {
        $primary = '<span class="ops-row-chip-verb">' . htmlspecialchars($verb) . '</span>';
    }

    $openHtml = '';
    if ($href !== '') {
        $openHtml = '<a class="ops-row-chip-open" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($openLabel) . '</a>';
    }

    return '<span class="ops-row-chip severity-' . htmlspecialchars($severity) . '" data-exception-type="' . htmlspecialchars($type) . '">'
        . '<span class="ops-row-chip-sev">' . $sevLabel . '</span>'
        . $primary
        . $openHtml
        . '</span>';
}

/**
 * @param list<array<string, mixed>> $exceptions
 * @param array<string, mixed> $row
 * @param array{date?:string, return?:string, daily_order_id?:int} $options
 */
function bakery_ops_render_row_chips(array $exceptions, array $row, array $options = []): string
{
    $chips = bakery_ops_chips_for_row($exceptions, $row);
    if ($chips === []) {
        return '';
    }
    if (empty($options['daily_order_id']) && (int)($row['daily_order_id'] ?? 0) > 0) {
        $options['daily_order_id'] = (int)$row['daily_order_id'];
    }
    $html = '<span class="ops-row-chips">';
    foreach ($chips as $chip) {
        $html .= bakery_ops_render_row_chip($chip, $options);
    }
    $html .= '</span>';
    return $html;
}

/** @return array{type:string, message:string}|null */
function bakery_ops_read_flash(): ?array
{
    $msg = trim((string)($_GET['msg'] ?? ''));
    if ($msg === '') {
        return null;
    }
    return [
        'type' => (($_GET['flash'] ?? '') === 'error') ? 'error' : 'success',
        'message' => $msg,
    ];
}

function bakery_ops_render_flash(?array $flash): string
{
    if ($flash === null || trim((string)($flash['message'] ?? '')) === '') {
        return '';
    }
    $cls = (($flash['type'] ?? '') === 'error') ? 'danger' : 'success';
    return '<div class="ops-alert ops-alert-' . $cls . '" role="status">'
        . htmlspecialchars((string)$flash['message'])
        . '</div>';
}

/**
 * Customer Hub related strip: summaries + deep links only.
 *
 * @return list<array{title:string, detail:string, href:string, severity:string}>
 */
function bakery_ops_customer_related_situations(PDO $db, int $customerId, string $date, ?string $returnKey = null): array
{
    if ($customerId <= 0 || $date === '') {
        return [];
    }
    $items = [];
    $exceptions = bakery_ops_exceptions_for_date($db, $date, $returnKey);
    $row = [
        'customer_id' => $customerId,
        'flags' => [],
    ];

    try {
        if (!function_exists('bakery_customer_record_date_context')) {
            require_once __DIR__ . '/customer_record.php';
        }
        $dayOfWeek = function_exists('bakery_standing_day_from_date')
            ? bakery_standing_day_from_date($date)
            : (int)date('N', strtotime($date));
        $routes = function_exists('bakery_customer_record_standing_routes')
            ? bakery_customer_record_standing_routes($db, $customerId)
            : [];
        $ctx = bakery_customer_record_date_context($db, $customerId, $date, $dayOfWeek, $routes);
        $state = (string)($ctx['state'] ?? '');
        if ($state === 'missing_daily') {
            $row['flags']['missing_daily'] = true;
        }
        if ($state === 'empty_daily') {
            $row['flags']['empty_daily'] = true;
        }
        $dailyOrderId = (int)($ctx['daily_order_id'] ?? 0);
        if ($dailyOrderId > 0) {
            $row['daily_order_id'] = $dailyOrderId;
        }
        if ($dailyOrderId > 0 && empty($ctx['dated_route'])) {
            $row['flags']['unassigned'] = true;
        }
        if (($ctx['assignment_status'] ?? '') === 'failed') {
            $row['flags']['failed_delivery'] = true;
        }
        if (($ctx['status'] ?? '') === 'delivered') {
            $row['flags']['uninvoiced'] = true;
        }
        foreach ($ctx['daily_lines'] ?? [] as $line) {
            $ordered = $line['daily_qty'] ?? null;
            $delivered = $line['delivered_quantity'] ?? null;
            if ($delivered !== null && $delivered !== '' && (int)$delivered !== (int)$ordered) {
                $row['flags']['qty_variance'] = true;
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('ops customer situations context: ' . $e->getMessage());
    }

    $matched = bakery_ops_chips_for_row($exceptions, $row);
    foreach ($matched as $ex) {
        $items[] = [
            'kind' => 'exception',
            'title' => (string)($ex['title'] ?? ''),
            'detail' => (string)($ex['detail'] ?? ''),
            'href' => (string)($ex['href'] ?? ''),
            'severity' => (string)($ex['severity'] ?? 'warning'),
        ];
    }

    try {
        if (!function_exists('bakery_delivery_issues_manager_queue')) {
            require_once __DIR__ . '/customer_delivery_issues.php';
        }
        $issues = bakery_delivery_issues_manager_queue($db, [
            'customer_id' => $customerId,
            'status' => 'open',
        ]);
        $openCount = is_array($issues) ? count($issues) : 0;
        if ($openCount > 0) {
            $items[] = [
                'kind' => 'service',
                'title' => function_exists('bakery_t') ? bakery_t('ops.related_issues') : 'Open service issues',
                'detail' => (string)$openCount,
                'href' => bakery_ops_link_service_issues([
                    'status' => 'open',
                    'customer_id' => $customerId,
                ], $returnKey),
                'severity' => 'warning',
            ];
        }
    } catch (Throwable $e) {
        // Service issues table may not exist yet.
    }

    try {
        if (!function_exists('bakery_delivery_recovery_cases_for_date')) {
            require_once __DIR__ . '/delivery_recovery.php';
        }
        $cases = bakery_delivery_recovery_cases_for_date($db, $date);
        $untriaged = bakery_delivery_recovery_untriaged_failed_stops($db, $date);
        $failedForCustomer = 0;
        $dailyOrderId = (int)($row['daily_order_id'] ?? 0);
        foreach ($cases as $case) {
            if ($dailyOrderId > 0 && (int)($case['daily_order_id'] ?? 0) === $dailyOrderId) {
                $failedForCustomer++;
            }
        }
        if ($failedForCustomer === 0) {
            foreach ($untriaged as $stop) {
                if ($dailyOrderId > 0 && (int)($stop['daily_order_id'] ?? 0) === $dailyOrderId) {
                    $failedForCustomer++;
                }
            }
        }
        if ($failedForCustomer === 0 && !empty($row['flags']['failed_delivery'])) {
            $failedForCustomer = 1;
        }
        if ($failedForCustomer > 0) {
            $items[] = [
                'kind' => 'failed',
                'title' => function_exists('bakery_t') ? bakery_t('ops.related_failed') : 'Failed-stop recovery',
                'detail' => (string)$failedForCustomer,
                'href' => bakery_ops_link_manager($date, [
                    'attention' => 'failed',
                    'fragment' => 'failed-stop-recovery',
                ], $returnKey),
                'severity' => 'critical',
            ];
        }
    } catch (Throwable $e) {
        if (!empty($row['flags']['failed_delivery'])) {
            $items[] = [
                'kind' => 'failed',
                'title' => function_exists('bakery_t') ? bakery_t('ops.related_failed') : 'Failed-stop recovery',
                'detail' => '1',
                'href' => bakery_ops_link_manager($date, [
                    'attention' => 'failed',
                    'fragment' => 'failed-stop-recovery',
                ], $returnKey),
                'severity' => 'critical',
            ];
        }
    }

    if (!empty($row['flags']['uninvoiced'])) {
        $billingParams = ['status' => 'delivered', 'attention' => 'needs_attention'];
        if ($customerId > 0) {
            $billingParams['customer_id'] = $customerId;
        }
        $items[] = [
            'kind' => 'uninvoiced',
            'title' => function_exists('bakery_t') ? bakery_t('ops.related_uninvoiced') : 'Uninvoiced delivery',
            'detail' => function_exists('bakery_t') ? bakery_t('ops.related_uninvoiced_detail') : 'Delivered, not yet marked invoiced.',
            'href' => bakery_ops_link_billing($date, $billingParams, $returnKey),
            'severity' => 'warning',
        ];
    }

    return $items;
}

/**
 * @param list<array{title:string, detail:string, href:string, severity:string}> $items
 */
function bakery_ops_render_customer_related_strip(array $items, string $date): string
{
    $heading = function_exists('bakery_t')
        ? bakery_t('ops.related_heading', ['date' => date('M j', strtotime($date))])
        : ('Open situations for ' . date('M j', strtotime($date)));
    $html = '<section class="ops-related-strip" aria-label="' . htmlspecialchars($heading) . '">';
    $html .= '<h2>' . htmlspecialchars($heading) . '</h2>';
    if ($items === []) {
        $none = function_exists('bakery_t') ? bakery_t('ops.related_none') : 'No open situations for this customer on this date.';
        $html .= '<p class="ops-related-none">' . htmlspecialchars($none) . '</p>';
        $html .= '</section>';
        return $html;
    }
    $html .= '<ul class="ops-related-list">';
    foreach ($items as $item) {
        $sev = htmlspecialchars((string)($item['severity'] ?? 'warning'));
        $title = htmlspecialchars((string)($item['title'] ?? ''));
        $detail = htmlspecialchars((string)($item['detail'] ?? ''));
        $href = (string)($item['href'] ?? '');
        $html .= '<li class="ops-related-item severity-' . $sev . '">';
        $html .= '<span class="ops-related-title">' . $title . '</span>';
        if ($detail !== '') {
            $html .= '<span class="ops-related-detail">' . $detail . '</span>';
        }
        if ($href !== '') {
            $open = function_exists('bakery_t') ? bakery_t('dashboard.open') : 'Open';
            $html .= '<a class="ops-related-link" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($open) . '</a>';
        }
        $html .= '</li>';
    }
    $html .= '</ul></section>';
    return $html;
}
