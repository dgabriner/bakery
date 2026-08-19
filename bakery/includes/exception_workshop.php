<?php
/**
 * Desktop exception workshop — scan, group, bulk-coordinate, and jump to
 * canonical bakery-day work. Not a ticketing product.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/manager_mode.php';
require_once __DIR__ . '/operational_exceptions.php';
require_once __DIR__ . '/operational_timeline.php';
require_once __DIR__ . '/delivery_recovery.php';

const BAKERY_WORKSHOP_COOKIE = 'bakery_ex_workshop';
const BAKERY_WORKSHOP_GROUPS = ['none', 'customer', 'product', 'driver', 'stage'];
const BAKERY_WORKSHOP_COORD = ['', 'new', 'owned', 'completed'];

/**
 * @return array{group:string,severity:string,category:string,assignee:string,coord:string,type:string,hide_completed:bool,key:string}
 */
function bakery_exception_workshop_filters_from_request(): array
{
    $defaults = [
        'group' => 'none',
        'severity' => '',
        'category' => '',
        'assignee' => '',
        'coord' => '',
        'type' => '',
        'hide_completed' => false,
        'key' => '',
    ];
    $fromCookie = [];
    $rawCookie = (string)($_COOKIE[BAKERY_WORKSHOP_COOKIE] ?? '');
    if ($rawCookie !== '') {
        $decoded = json_decode($rawCookie, true);
        if (is_array($decoded)) {
            $fromCookie = $decoded;
        }
    }
    $fromCompact = bakery_exception_workshop_parse_filter_param((string)($_GET['ex_filter'] ?? ''));
    $merged = array_merge($defaults, $fromCookie, $fromCompact);

    $get = static function (string $name, string $fallback) {
        if (!array_key_exists($name, $_GET)) {
            return $fallback;
        }
        return trim((string)$_GET[$name]);
    };

    $group = $get('ex_group', (string)$merged['group']);
    if (!in_array($group, BAKERY_WORKSHOP_GROUPS, true)) {
        $group = 'none';
    }
    $hide = $get('ex_hide_completed', !empty($merged['hide_completed']) ? '1' : '');
    if (isset($_GET['ex_hide_completed'])) {
        $hideCompleted = $hide === '1' || $hide === 'true';
    } else {
        $hideCompleted = !empty($merged['hide_completed']);
    }

    return [
        'group' => $group,
        'severity' => $get('ex_severity', (string)$merged['severity']),
        'category' => $get('ex_category', (string)$merged['category']),
        'assignee' => $get('ex_assignee', (string)$merged['assignee']),
        'coord' => $get('ex_coord', (string)$merged['coord']),
        'type' => $get('ex_type', (string)$merged['type']),
        'hide_completed' => $hideCompleted,
        'key' => $get('ex_key', (string)($merged['key'] ?? '')),
    ];
}

/** @return array<string,string> */
function bakery_exception_workshop_parse_filter_param(string $raw): array
{
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || strpos($part, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $part, 2);
        $k = trim($k);
        $v = trim($v);
        if (in_array($k, ['severity', 'category', 'assignee', 'coord', 'type', 'hide_completed', 'group'], true)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

function bakery_exception_workshop_remember_filters(array $filters): void
{
    $payload = json_encode([
        'group' => (string)($filters['group'] ?? 'none'),
        'severity' => (string)($filters['severity'] ?? ''),
        'category' => (string)($filters['category'] ?? ''),
        'assignee' => (string)($filters['assignee'] ?? ''),
        'coord' => (string)($filters['coord'] ?? ''),
        'type' => (string)($filters['type'] ?? ''),
        'hide_completed' => !empty($filters['hide_completed']),
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false || headers_sent()) {
        return;
    }
    setcookie(BAKERY_WORKSHOP_COOKIE, $payload, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'samesite' => 'Lax',
        'httponly' => false,
    ]);
}

function bakery_exception_workshop_query(string $date, array $filters, array $extra = []): string
{
    $q = array_merge([
        'date' => $date,
        'ex_group' => (string)($filters['group'] ?? 'none'),
        'ex_severity' => (string)($filters['severity'] ?? ''),
        'ex_category' => (string)($filters['category'] ?? ''),
        'ex_assignee' => (string)($filters['assignee'] ?? ''),
        'ex_coord' => (string)($filters['coord'] ?? ''),
        'ex_type' => (string)($filters['type'] ?? ''),
        'ex_hide_completed' => !empty($filters['hide_completed']) ? '1' : '',
        'ex_key' => (string)($filters['key'] ?? ''),
    ], $extra);
    $q['ex_filter'] = bakery_exception_workshop_compact_filter($q);
    foreach ($q as $k => $v) {
        if ($v === '' || $v === null) {
            unset($q[$k]);
        }
    }
    return http_build_query($q);
}

function bakery_exception_workshop_compact_filter(array $q): string
{
    $parts = [];
    foreach (['severity' => 'ex_severity', 'category' => 'ex_category', 'assignee' => 'ex_assignee', 'coord' => 'ex_coord', 'type' => 'ex_type'] as $label => $key) {
        $v = trim((string)($q[$key] ?? ''));
        if ($v !== '') {
            $parts[] = $label . '=' . $v;
        }
    }
    if (!empty($q['ex_hide_completed'])) {
        $parts[] = 'hide_completed=1';
    }
    return implode(',', $parts);
}

function bakery_exception_workshop_context_id(array $exception, string $field): int
{
    $ctx = is_array($exception['context'] ?? null) ? $exception['context'] : [];
    return (int)($ctx[$field] ?? $exception[$field] ?? 0);
}

function bakery_exception_workshop_coordination_state(array $exception): string
{
    $work = is_array($exception['work'] ?? null) ? $exception['work'] : null;
    if ($work && !empty($work['completed_at'])) {
        return 'completed';
    }
    if ($work && ((int)($work['assigned_to_user_id'] ?? 0) > 0 || !empty($work['acknowledged_at']))) {
        return 'owned';
    }
    return 'new';
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>>
 */
function bakery_exception_workshop_filter(array $exceptions, array $filters, int $currentUserId = 0): array
{
    $severity = (string)($filters['severity'] ?? '');
    $category = (string)($filters['category'] ?? '');
    $assignee = (string)($filters['assignee'] ?? '');
    $coord = (string)($filters['coord'] ?? '');
    $type = (string)($filters['type'] ?? '');
    $hideCompleted = !empty($filters['hide_completed']);
    $out = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        $state = bakery_exception_workshop_coordination_state($ex);
        if ($hideCompleted && $state === 'completed') {
            continue;
        }
        if ($severity !== '' && (string)($ex['severity'] ?? '') !== $severity) {
            continue;
        }
        $exCategory = (string)($ex['category'] ?? '');
        $exStage = (string)($ex['stage'] ?? '');
        if ($category !== '' && $exCategory !== $category && $exStage !== $category) {
            continue;
        }
        if ($type !== '' && (string)($ex['type'] ?? '') !== $type) {
            continue;
        }
        if ($coord !== '' && $state !== $coord) {
            continue;
        }
        $work = is_array($ex['work'] ?? null) ? $ex['work'] : [];
        $assigned = (int)($work['assigned_to_user_id'] ?? 0);
        if ($assignee === 'me' && ($currentUserId <= 0 || $assigned !== $currentUserId)) {
            continue;
        }
        if ($assignee === 'unassigned' && $assigned > 0) {
            continue;
        }
        if ($assignee !== '' && $assignee !== 'me' && $assignee !== 'unassigned' && $assigned !== (int)$assignee) {
            continue;
        }
        $out[] = $ex;
    }
    return $out;
}

/**
 * Group exceptions. Default "none" is a single bucket.
 *
 * @param list<array<string,mixed>> $exceptions
 * @return list<array{key:string,label:string,count:int,exceptions:list<array<string,mixed>>}>
 */
function bakery_exception_workshop_group(array $exceptions, string $by = 'none'): array
{
    if (!in_array($by, BAKERY_WORKSHOP_GROUPS, true)) {
        $by = 'none';
    }
    $buckets = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        $ctx = is_array($ex['context'] ?? null) ? $ex['context'] : [];
        if ($by === 'none') {
            $key = 'all';
            $label = function_exists('bakery_t') ? bakery_t('workshop.group_all') : 'All situations';
        } elseif ($by === 'stage') {
            $key = (string)($ex['stage'] ?? $ex['category'] ?? 'general');
            $label = str_replace('_', ' ', $key);
        } else {
            $idField = $by . '_id';
            $nameField = $by . '_name';
            $id = (int)($ctx[$idField] ?? 0);
            $key = $id > 0 ? $by . ':' . $id : $by . ':none';
            if ($id > 0) {
                $name = trim((string)($ctx[$nameField] ?? ''));
                $label = $name !== '' ? $name : (ucfirst($by) . ' #' . $id);
            } else {
                $label = function_exists('bakery_t') ? bakery_t('workshop.ungrouped') : 'Ungrouped';
            }
        }
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['key' => $key, 'label' => $label, 'count' => 0, 'exceptions' => []];
        }
        $buckets[$key]['exceptions'][] = $ex;
        $buckets[$key]['count']++;
    }
    return array_values($buckets);
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @return list<array<string,mixed>>
 */
function bakery_exception_workshop_related(array $exceptions, array $selected): array
{
    $selectedKey = (string)($selected['work_key'] ?? '');
    $ids = [
        'customer_id' => bakery_exception_workshop_context_id($selected, 'customer_id'),
        'product_id' => bakery_exception_workshop_context_id($selected, 'product_id'),
        'driver_id' => bakery_exception_workshop_context_id($selected, 'driver_id'),
        'daily_order_id' => bakery_exception_workshop_context_id($selected, 'daily_order_id'),
    ];
    $hasAny = $ids['customer_id'] + $ids['product_id'] + $ids['driver_id'] + $ids['daily_order_id'] > 0;
    if (!$hasAny) {
        return [];
    }
    $related = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        if ($selectedKey !== '' && hash_equals($selectedKey, (string)($ex['work_key'] ?? ''))) {
            continue;
        }
        foreach ($ids as $field => $id) {
            if ($id > 0 && bakery_exception_workshop_context_id($ex, $field) === $id) {
                $related[] = $ex;
                break;
            }
        }
    }
    return $related;
}

function bakery_exception_workshop_due_for_save(?array $work): string
{
    $due = trim((string)($work['due_at'] ?? ''));
    if ($due === '') {
        return '';
    }
    $ts = strtotime($due);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param list<string> $workKeys
 * @return list<array<string,mixed>>
 */
function bakery_exception_workshop_match_keys(array $exceptions, array $workKeys): array
{
    $wanted = [];
    foreach ($workKeys as $key) {
        $key = (string)$key;
        if ($key !== '') {
            $wanted[$key] = true;
        }
    }
    if ($wanted === []) {
        throw new InvalidArgumentException(function_exists('bakery_t') ? bakery_t('workshop.error_none_selected') : 'Select at least one situation.');
    }
    $matched = [];
    foreach ($exceptions as $ex) {
        $key = (string)($ex['work_key'] ?? '');
        if ($key !== '' && isset($wanted[$key])) {
            $matched[] = $ex;
        }
    }
    if ($matched === []) {
        throw new RuntimeException(function_exists('bakery_t') ? bakery_t('workshop.error_stale') : 'Those situations are no longer in the current operating-day queue.');
    }
    return $matched;
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param list<string> $workKeys
 */
function bakery_exception_workshop_bulk_assign(PDO $db, array $exceptions, string $date, array $workKeys, array $input): int
{
    $matched = bakery_exception_workshop_match_keys($exceptions, $workKeys);
    $assigned = (int)($input['assigned_to_user_id'] ?? 0);
    $actorId = (int)(bakery_current_user()['id'] ?? 0);
    if (!empty($input['mine'])) {
        $assigned = $actorId;
    }
    $count = 0;
    foreach ($matched as $ex) {
        $work = is_array($ex['work'] ?? null) ? $ex['work'] : [];
        bakery_manager_exception_save($db, $ex, $date, [
            'acknowledge' => '1',
            'assigned_to_user_id' => $assigned > 0 ? $assigned : '',
            'due_at' => bakery_exception_workshop_due_for_save($work),
            'resolution_note' => (string)($work['resolution_note'] ?? ''),
        ]);
        $count++;
    }
    return $count;
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param list<string> $workKeys
 */
function bakery_exception_workshop_bulk_complete(PDO $db, array $exceptions, string $date, array $workKeys, array $input): int
{
    $note = bakery_delivery_recovery_note($input['resolution_note'] ?? '');
    if ($note === '') {
        throw new InvalidArgumentException(function_exists('bakery_t') ? bakery_t('workshop.error_note_required') : 'A resolution note is required before completion');
    }
    $matched = bakery_exception_workshop_match_keys($exceptions, $workKeys);
    $actorId = (int)(bakery_current_user()['id'] ?? 0);
    $count = 0;
    foreach ($matched as $ex) {
        $work = is_array($ex['work'] ?? null) ? $ex['work'] : [];
        $assigned = (int)($work['assigned_to_user_id'] ?? 0) ?: $actorId;
        bakery_manager_exception_save($db, $ex, $date, [
            'acknowledge' => '1',
            'assigned_to_user_id' => $assigned > 0 ? $assigned : '',
            'due_at' => bakery_exception_workshop_due_for_save($work),
            'resolution_note' => $note,
            'complete' => '1',
        ]);
        $count++;
    }
    return $count;
}

/**
 * Selected ids that are delivered (confirmation snapshot present) and not invoiced.
 *
 * @param list<int|string> $selectedIds
 * @return list<int>
 */
function bakery_exception_workshop_delivered_order_ids(PDO $db, array $selectedIds): array
{
    $ids = [];
    foreach ($selectedIds as $raw) {
        $id = (int)$raw;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    if ($ids === [] || !table_exists($db, 'daily_orders')) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id FROM daily_orders
            WHERE id IN ({$marks})
              AND delivery_confirmed_at IS NOT NULL
              AND status <> 'invoiced'";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_values($ids));
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $allowed = array_flip($found);
    $ordered = [];
    foreach ($ids as $id) {
        if (isset($allowed[$id])) {
            $ordered[] = $id;
        }
    }
    return $ordered;
}

/** @return list<int> */
function bakery_exception_workshop_uninvoiced_ids_for_date(PDO $db, string $date): array
{
    if (!table_exists($db, 'daily_orders')) {
        return [];
    }
    $stmt = $db->prepare(
        "SELECT id FROM daily_orders
         WHERE order_date = ?
           AND delivery_confirmed_at IS NOT NULL
           AND status <> 'invoiced'
         ORDER BY id"
    );
    $stmt->execute([$date]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @return list<int>
 */
function bakery_exception_workshop_invoice_ids_for_exceptions(PDO $db, string $date, array $exceptions): array
{
    $ids = [];
    $needsDateSweep = false;
    foreach ($exceptions as $ex) {
        $orderId = bakery_exception_workshop_context_id($ex, 'daily_order_id');
        if ($orderId > 0) {
            $ids[] = $orderId;
        }
        if ((string)($ex['type'] ?? '') === 'invoice_uninvoiced') {
            $needsDateSweep = true;
        }
    }
    if ($needsDateSweep) {
        $ids = array_merge($ids, bakery_exception_workshop_uninvoiced_ids_for_date($db, $date));
    }
    return bakery_exception_workshop_delivered_order_ids($db, $ids);
}

/**
 * Mark only the selected delivered order ids invoiced. Never prices or sends.
 *
 * @param list<int|string> $selectedIds
 * @return array{marked:list<int>,skipped:list<int>}
 */
function bakery_exception_workshop_mark_invoiced(PDO $db, array $selectedIds, ?int $userId = null): array
{
    require_once __DIR__ . '/billing.php';
    $eligible = bakery_exception_workshop_delivered_order_ids($db, $selectedIds);
    $eligibleMap = array_flip($eligible);
    $marked = [];
    $skipped = [];
    $seen = [];
    foreach ($selectedIds as $raw) {
        $id = (int)$raw;
        if ($id <= 0 || isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        if (!isset($eligibleMap[$id])) {
            $skipped[] = $id;
            continue;
        }
        try {
            bakery_billing_mark_invoiced($db, $id, $userId);
            $marked[] = $id;
        } catch (Throwable $e) {
            $skipped[] = $id;
        }
    }
    return ['marked' => $marked, 'skipped' => $skipped];
}

/** Generate dated orders; overwrite_changed is forced off. */
function bakery_exception_workshop_generate_orders(PDO $db, string $date): array
{
    require_once __DIR__ . '/daily_order_generation.php';
    return bakery_generate_daily_orders_from_standing($db, $date, [
        'overwrite_changed' => false,
    ]);
}

function bakery_exception_workshop_build_routes(PDO $db, string $date): array
{
    require_once __DIR__ . '/driver_assignments.php';
    return bakery_driver_assign_from_standing_routes($db, $date);
}

/**
 * Handle workshop POST mutations. Returns false when the mutation is not ours.
 * Redirects and exits on success.
 *
 * @param list<array<string,mixed>> $exceptions
 */
function bakery_exception_workshop_handle_post(PDO $db, string $date, array $exceptions, array $post): bool
{
    $mutation = (string)($post['manager_mutation'] ?? '');
    if (strpos($mutation, 'workshop_') !== 0) {
        return false;
    }
    bakery_require_role(['administrator', 'manager']);
    $filters = bakery_exception_workshop_filters_from_request();
    if (!empty($post['ex_group']) || !empty($post['ex_filter'])) {
        $filters['group'] = (string)($post['ex_group'] ?? $filters['group']);
        $filters = array_merge($filters, bakery_exception_workshop_parse_filter_param((string)($post['ex_filter'] ?? '')));
    }
    foreach (['severity' => 'ex_severity', 'category' => 'ex_category', 'assignee' => 'ex_assignee', 'coord' => 'ex_coord', 'type' => 'ex_type', 'key' => 'ex_key'] as $field => $postKey) {
        if (isset($post[$postKey]) && $post[$postKey] !== '') {
            $filters[$field] = (string)$post[$postKey];
        }
    }
    if (isset($post['ex_hide_completed'])) {
        $filters['hide_completed'] = (string)$post['ex_hide_completed'] === '1';
    }
    bakery_exception_workshop_remember_filters($filters);

    $workKeys = $post['work_keys'] ?? [];
    if (!is_array($workKeys) && $workKeys !== '') {
        $workKeys = [$workKeys];
    }
    if ($workKeys === [] && !empty($post['work_key'])) {
        $workKeys = [(string)$post['work_key']];
    }

    $notice = '';
    $userId = (int)(bakery_current_user()['id'] ?? 0) ?: null;

    switch ($mutation) {
        case 'workshop_work':
            $matched = bakery_exception_workshop_match_keys($exceptions, $workKeys);
            bakery_manager_exception_save($db, $matched[0], $date, $post);
            $notice = !empty($post['complete'])
                ? (function_exists('bakery_t') ? bakery_t('workshop.notice_completed') : 'Exception work marked complete.')
                : (function_exists('bakery_t') ? bakery_t('workshop.notice_saved') : 'Exception work saved.');
            $filters['key'] = (string)($matched[0]['work_key'] ?? '');
            break;
        case 'workshop_bulk_mine':
            $count = bakery_exception_workshop_bulk_assign($db, $exceptions, $date, $workKeys, ['mine' => '1']);
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_mine', ['count' => $count])
                : ($count . ' situation(s) assigned to you.');
            break;
        case 'workshop_bulk_assign':
            $count = bakery_exception_workshop_bulk_assign($db, $exceptions, $date, $workKeys, $post);
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_assigned', ['count' => $count])
                : ($count . ' situation(s) assigned.');
            break;
        case 'workshop_bulk_complete':
            $count = bakery_exception_workshop_bulk_complete($db, $exceptions, $date, $workKeys, $post);
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_bulk_completed', ['count' => $count])
                : ($count . ' situation(s) marked complete.');
            break;
        case 'workshop_generate':
            $result = bakery_exception_workshop_generate_orders($db, $date);
            $notice = (string)($result['message'] ?? (function_exists('bakery_t') ? bakery_t('workshop.notice_generated') : 'Dated orders generated. Edited quantities were preserved.'));
            break;
        case 'workshop_assign_from_standing':
            $result = bakery_exception_workshop_build_routes($db, $date);
            $stops = (int)($result['stop_count'] ?? 0);
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_routes', ['count' => $stops])
                : ('Built ' . $stops . ' route stop(s) from standing.');
            break;
        case 'workshop_mark_invoiced':
            $matched = bakery_exception_workshop_match_keys($exceptions, $workKeys);
            $allowed = bakery_exception_workshop_invoice_ids_for_exceptions($db, $date, $matched);
            $requested = $post['order_ids'] ?? [];
            if (!is_array($requested)) {
                $requested = [$requested];
            }
            $requested = array_map('intval', $requested);
            if ($requested === [] || $requested === [0]) {
                $requested = $allowed;
            }
            $selected = array_values(array_intersect($requested, $allowed));
            $result = bakery_exception_workshop_mark_invoiced($db, $selected, $userId);
            $marked = count($result['marked']);
            if ($marked === 0) {
                throw new RuntimeException(function_exists('bakery_t') ? bakery_t('workshop.error_no_invoices') : 'No selected delivered orders were eligible to mark invoiced.');
            }
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_invoiced', ['count' => $marked])
                : ($marked . ' delivered order(s) marked invoiced.');
            break;
        case 'workshop_confirm_demand':
            require_once __DIR__ . '/demand_confirmation.php';
            $result = bakery_demand_confirmation_confirm($db, $date, $userId);
            $notice = function_exists('bakery_t')
                ? bakery_t('workshop.notice_confirmed', ['customers' => (int)($result['customers_count'] ?? 0), 'units' => (int)($result['units_count'] ?? 0)])
                : 'Demand confirmed again.';
            break;
        default:
            throw new RuntimeException('Unknown workshop action');
    }

    $href = (defined('BASE_URL') ? BASE_URL : '') . 'manager.php?' . bakery_exception_workshop_query($date, $filters)
        . '&notice=' . rawurlencode($notice);
    header('Location: ' . $href);
    exit;
}

/**
 * @param list<array<string,mixed>> $exceptions
 */
function bakery_exception_workshop_render(PDO $db, string $date, array $exceptions, array $options = []): void
{
    if (!empty($options['mobile_only'])) {
        return;
    }

    $filters = $options['filters'] ?? bakery_exception_workshop_filters_from_request();
    bakery_exception_workshop_remember_filters($filters);

    $currentUser = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $currentUserId = (int)($currentUser['id'] ?? 0);
    $assignable = bakery_manager_assignable_users($db);
    $visible = bakery_exception_workshop_filter($exceptions, $filters, $currentUserId);
    $groups = bakery_exception_workshop_group($visible, (string)$filters['group']);

    $selected = null;
    $wantKey = (string)($filters['key'] ?? '');
    foreach ($visible as $ex) {
        if ($wantKey !== '' && hash_equals($wantKey, (string)($ex['work_key'] ?? ''))) {
            $selected = $ex;
            break;
        }
    }
    if ($selected === null && $visible !== []) {
        $selected = $visible[0];
    }

    $severities = [];
    $categories = [];
    $types = [];
    foreach ($exceptions as $ex) {
        if (!is_array($ex)) {
            continue;
        }
        $severities[(string)($ex['severity'] ?? 'warning')] = true;
        $categories[(string)($ex['category'] ?? '')] = true;
        if ((string)($ex['stage'] ?? '') !== '') {
            $categories[(string)$ex['stage']] = true;
        }
        $types[(string)($ex['type'] ?? '')] = true;
    }
    ksort($severities);
    ksort($categories);
    ksort($types);

    $recoveryCases = [];
    $untriaged = [];
    try {
        $recoveryCases = bakery_delivery_recovery_cases_for_date($db, $date);
        $untriaged = bakery_delivery_recovery_untriaged_failed_stops($db, $date);
    } catch (Throwable $e) {
        $recoveryCases = [];
        $untriaged = [];
    }

    $t = static function (string $key, array $params = []) {
        return function_exists('bakery_t') ? bakery_t($key, $params) : $key;
    };
    $h = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $base = defined('BASE_URL') ? BASE_URL : '';
    $self = $base . 'manager.php';
    $userIdAttr = $currentUserId > 0 ? (string)$currentUserId : '';

    echo '<link rel="stylesheet" href="' . $h(function_exists('bakery_asset_href') ? bakery_asset_href('css/exception_workshop.css') : 'css/exception_workshop.css') . '">';
    echo '<section class="exception-workshop" data-workshop="1" data-user-id="' . $h($userIdAttr) . '" data-date="' . $h($date) . '" aria-labelledby="exception-workshop-title">';
    echo '<div class="exception-workshop__header">';
    echo '<div><p class="manager-eyebrow">' . $h($t('workshop.eyebrow')) . '</p>';
    echo '<h2 id="exception-workshop-title">' . $h($t('workshop.title')) . '</h2>';
    echo '<p class="exception-workshop__lede">' . $h($t('workshop.lede')) . '</p></div>';
    echo '<p class="exception-workshop__keys">' . $h($t('workshop.keyboard')) . '</p>';
    echo '</div>';

    echo '<form class="exception-workshop__filters" method="get" action="' . $h($self) . '">';
    echo '<input type="hidden" name="date" value="' . $h($date) . '">';
    echo '<label>' . $h($t('workshop.filter_severity')) . '<select name="ex_severity">';
    echo '<option value="">' . $h($t('workshop.filter_any')) . '</option>';
    foreach (array_keys($severities) as $sev) {
        if ($sev === '') {
            continue;
        }
        echo '<option value="' . $h($sev) . '"' . ($filters['severity'] === $sev ? ' selected' : '') . '>' . $h(ucfirst($sev)) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.filter_stage')) . '<select name="ex_category">';
    echo '<option value="">' . $h($t('workshop.filter_any')) . '</option>';
    foreach (array_keys($categories) as $cat) {
        if ($cat === '') {
            continue;
        }
        echo '<option value="' . $h($cat) . '"' . ($filters['category'] === $cat ? ' selected' : '') . '>' . $h(str_replace('_', ' ', $cat)) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.filter_assignee')) . '<select name="ex_assignee">';
    echo '<option value="">' . $h($t('workshop.filter_any')) . '</option>';
    echo '<option value="me"' . ($filters['assignee'] === 'me' ? ' selected' : '') . '>' . $h($t('workshop.assignee_me')) . '</option>';
    echo '<option value="unassigned"' . ($filters['assignee'] === 'unassigned' ? ' selected' : '') . '>' . $h($t('workshop.assignee_unassigned')) . '</option>';
    foreach ($assignable as $manager) {
        $mid = (string)(int)$manager['id'];
        echo '<option value="' . $h($mid) . '"' . ((string)$filters['assignee'] === $mid ? ' selected' : '') . '>' . $h((string)$manager['display_name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.filter_coord')) . '<select name="ex_coord">';
    echo '<option value="">' . $h($t('workshop.filter_any')) . '</option>';
    foreach (['new', 'owned', 'completed'] as $coord) {
        echo '<option value="' . $h($coord) . '"' . ($filters['coord'] === $coord ? ' selected' : '') . '>' . $h($t('workshop.coord_' . $coord)) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.filter_type')) . '<select name="ex_type">';
    echo '<option value="">' . $h($t('workshop.filter_any')) . '</option>';
    foreach (array_keys($types) as $type) {
        if ($type === '') {
            continue;
        }
        echo '<option value="' . $h($type) . '"' . ($filters['type'] === $type ? ' selected' : '') . '>' . $h(str_replace('_', ' ', $type)) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.filter_group')) . '<select name="ex_group">';
    foreach (BAKERY_WORKSHOP_GROUPS as $group) {
        echo '<option value="' . $h($group) . '"' . ($filters['group'] === $group ? ' selected' : '') . '>' . $h($t('workshop.group_' . $group)) . '</option>';
    }
    echo '</select></label>';
    echo '<label class="exception-workshop__toggle"><input type="checkbox" name="ex_hide_completed" value="1"' . (!empty($filters['hide_completed']) ? ' checked' : '') . '> ' . $h($t('workshop.hide_completed')) . '</label>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit">' . $h($t('workshop.apply_filters')) . '</button>';
    echo '</form>';

    echo '<form class="exception-workshop__bulk" method="post" action="' . $h($self . '?' . bakery_exception_workshop_query($date, $filters)) . '" id="exception-workshop-bulk">';
    if (function_exists('bakery_csrf_field')) {
        echo bakery_csrf_field();
    }
    echo '<input type="hidden" name="manager_mutation" id="workshop-bulk-mutation" value="workshop_bulk_mine">';
    echo '<input type="hidden" name="ex_group" value="' . $h($filters['group']) . '">';
    echo '<input type="hidden" name="ex_filter" value="' . $h(bakery_exception_workshop_compact_filter(['ex_severity' => $filters['severity'], 'ex_category' => $filters['category'], 'ex_assignee' => $filters['assignee'], 'ex_coord' => $filters['coord'], 'ex_type' => $filters['type'], 'ex_hide_completed' => !empty($filters['hide_completed']) ? '1' : ''])) . '">';
    echo '<div class="exception-workshop__bulk-coord">';
    echo '<strong>' . $h($t('workshop.bulk_coord')) . '</strong>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit" name="manager_mutation" value="workshop_bulk_mine">' . $h($t('workshop.bulk_mine')) . '</button>';
    echo '<label class="exception-workshop__assign">' . $h($t('workshop.bulk_assign'));
    echo '<select name="assigned_to_user_id"><option value="">' . $h($t('workshop.assignee_unassigned')) . '</option>';
    foreach ($assignable as $manager) {
        echo '<option value="' . (int)$manager['id'] . '">' . $h((string)$manager['display_name']) . '</option>';
    }
    echo '</select></label>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit" name="manager_mutation" value="workshop_bulk_assign">' . $h($t('workshop.bulk_assign_go')) . '</button>';
    echo '<label class="exception-workshop__note">' . $h($t('workshop.bulk_note'));
    echo '<input type="text" name="resolution_note" maxlength="2000" placeholder="' . $h($t('workshop.bulk_note_ph')) . '"></label>';
    echo '<button class="sf-btn sf-btn--primary sf-btn--sm" type="submit" name="manager_mutation" value="workshop_bulk_complete">' . $h($t('workshop.bulk_complete')) . '</button>';
    echo '</div>';
    echo '<div class="exception-workshop__bulk-ops">';
    echo '<strong>' . $h($t('workshop.bulk_ops')) . '</strong>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit" name="manager_mutation" value="workshop_generate" data-confirm="' . $h($t('workshop.confirm_generate')) . '">' . $h($t('workshop.op_generate')) . '</button>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit" name="manager_mutation" value="workshop_assign_from_standing" data-confirm="' . $h($t('workshop.confirm_routes')) . '">' . $h($t('workshop.op_routes')) . '</button>';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit" name="manager_mutation" value="workshop_mark_invoiced" data-confirm="' . $h($t('workshop.confirm_invoiced')) . '">' . $h($t('workshop.op_invoiced')) . '</button>';
    echo '</div></form>';

    echo '<div class="exception-workshop__split">';
    echo '<div class="exception-workshop__queue" role="list">';
    if ($visible === []) {
        echo '<div class="manager-clear-state"><strong>' . $h($t('workshop.empty')) . '</strong><span>' . $h($t('workshop.empty_detail')) . '</span></div>';
    }
    $rowIndex = 0;
    foreach ($groups as $group) {
        if ((string)$filters['group'] !== 'none') {
            echo '<div class="exception-workshop__group" data-group-key="' . $h($group['key']) . '">';
            echo '<h3>' . $h($group['label']) . ' <span>' . (int)$group['count'] . '</span></h3>';
        }
        foreach ($group['exceptions'] as $ex) {
            $key = (string)($ex['work_key'] ?? '');
            $severity = (string)($ex['severity'] ?? 'warning');
            $state = bakery_exception_workshop_coordination_state($ex);
            $work = is_array($ex['work'] ?? null) ? $ex['work'] : [];
            $hasNote = trim((string)($work['resolution_note'] ?? '')) !== '';
            $isSelected = $selected && hash_equals((string)($selected['work_key'] ?? ''), $key);
            $href = (string)($ex['href'] ?? '');
            $orderId = bakery_exception_workshop_context_id($ex, 'daily_order_id');
            echo '<article class="exception-workshop__row exception-workshop__row--' . $h($severity) . ($isSelected ? ' is-selected' : '') . '" role="listitem" tabindex="0" data-work-key="' . $h($key) . '" data-row-index="' . $rowIndex . '" data-has-note="' . ($hasNote ? '1' : '0') . '" data-fix-href="' . $h($href) . '" data-order-id="' . (int)$orderId . '">';
            echo '<label class="exception-workshop__check"><input type="checkbox" name="work_keys[]" value="' . $h($key) . '" form="exception-workshop-bulk"><span class="sf-sr-only">' . $h($t('workshop.select_row')) . '</span></label>';
            echo '<div class="exception-workshop__row-copy">';
            echo '<span class="exception-workshop__badge">' . $h(ucfirst($severity)) . '</span>';
            echo '<span class="exception-workshop__state">' . $h($t('workshop.coord_' . $state)) . '</span>';
            echo '<h3>' . $h((string)($ex['title'] ?? $t('workshop.untitled'))) . '</h3>';
            echo '<p>' . $h((string)($ex['detail'] ?? '')) . '</p>';
            echo '</div></article>';
            $rowIndex++;
        }
        if ((string)$filters['group'] !== 'none') {
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<aside class="exception-workshop__panel">';
    foreach ($visible as $ex) {
        $isSelected = $selected && hash_equals((string)($selected['work_key'] ?? ''), (string)($ex['work_key'] ?? ''));
        bakery_exception_workshop_render_detail($db, $date, $ex, $exceptions, $assignable, $recoveryCases, $untriaged, $filters, $isSelected);
    }
    if ($visible === []) {
        echo '<div class="exception-workshop__detail is-empty"><p>' . $h($t('workshop.pick_row')) . '</p></div>';
    }
    echo '</aside>';
    echo '</div>';

    bakery_exception_workshop_render_script($date, $filters);
    echo '</section>';
}

/**
 * @param list<array<string,mixed>> $exceptions
 * @param list<array<string,mixed>> $assignable
 * @param list<array<string,mixed>> $recoveryCases
 * @param list<array<string,mixed>> $untriaged
 */
function bakery_exception_workshop_render_detail(
    PDO $db,
    string $date,
    array $ex,
    array $exceptions,
    array $assignable,
    array $recoveryCases,
    array $untriaged,
    array $filters,
    bool $isSelected
): void {
    $t = static function (string $key, array $params = []) {
        return function_exists('bakery_t') ? bakery_t($key, $params) : $key;
    };
    $h = static function ($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };
    $key = (string)($ex['work_key'] ?? '');
    $work = is_array($ex['work'] ?? null) ? $ex['work'] : [];
    $href = (string)($ex['href'] ?? '');
    $action = (string)($ex['action'] ?? $t('workshop.fix'));
    $type = (string)($ex['type'] ?? '');
    $customerId = bakery_exception_workshop_context_id($ex, 'customer_id');
    $productId = bakery_exception_workshop_context_id($ex, 'product_id');
    $driverId = bakery_exception_workshop_context_id($ex, 'driver_id');
    $orderId = bakery_exception_workshop_context_id($ex, 'daily_order_id');
    $related = bakery_exception_workshop_related($exceptions, $ex);
    $base = defined('BASE_URL') ? BASE_URL : '';
    $self = $base . 'manager.php?' . bakery_exception_workshop_query($date, array_merge($filters, ['key' => $key]));

    $issues = [];
    if ($customerId > 0) {
        try {
            require_once __DIR__ . '/customer_delivery_issues.php';
            if (function_exists('bakery_delivery_issues_manager_queue')) {
                $issues = bakery_delivery_issues_manager_queue($db, [
                    'status' => 'open',
                    'customer_id' => $customerId,
                    'limit' => 8,
                ]);
            }
        } catch (Throwable $e) {
            $issues = [];
        }
    }

    $matchedCases = [];
    foreach ($recoveryCases as $case) {
        $caseOrder = (int)($case['daily_order_id'] ?? 0);
        if ($orderId > 0 && $caseOrder === $orderId) {
            $matchedCases[] = $case;
        } elseif ($orderId <= 0 && $type === 'delivery_failed') {
            $matchedCases[] = $case;
        }
    }

    $timeline = [];
    try {
        $tf = ['operational_date' => $date, 'limit' => 8];
        if ($orderId > 0) {
            $tf['daily_order_id'] = $orderId;
            $timeline = bakery_operational_timeline_fetch($db, $tf);
        } elseif ($customerId > 0) {
            $tf['customer_id'] = $customerId;
            $timeline = bakery_operational_timeline_fetch($db, $tf);
        }
    } catch (Throwable $e) {
        $timeline = [];
    }

    echo '<article class="exception-workshop__detail' . ($isSelected ? ' is-active' : '') . '" data-detail-key="' . $h($key) . '"' . ($isSelected ? '' : ' hidden') . '>';
    echo '<div class="exception-workshop__detail-top">';
    echo '<span class="exception-workshop__badge">' . $h(ucfirst((string)($ex['severity'] ?? 'warning'))) . '</span>';
    echo '<h3>' . $h((string)($ex['title'] ?? $t('workshop.untitled'))) . '</h3>';
    echo '</div>';
    echo '<p>' . $h((string)($ex['detail'] ?? '')) . '</p>';
    if ($href !== '') {
        echo '<a class="sf-btn sf-btn--primary exception-workshop__fix" href="' . $h($href) . '">' . $h($action) . '</a>';
    }

    echo '<form method="post" class="exception-workshop__work" action="' . $h($self) . '">';
    if (function_exists('bakery_csrf_field')) {
        echo bakery_csrf_field();
    }
    echo '<input type="hidden" name="manager_mutation" value="workshop_work">';
    echo '<input type="hidden" name="work_key" value="' . $h($key) . '">';
    echo '<input type="hidden" name="ex_group" value="' . $h($filters['group']) . '">';
    echo '<h4>' . $h($t('workshop.work_heading')) . '</h4>';
    echo '<label><input type="checkbox" name="acknowledge" value="1"' . (!empty($work['acknowledged_at']) ? ' checked' : '') . '> ' . $h($t('workshop.ack')) . '</label>';
    echo '<label>' . $h($t('workshop.assignee')) . '<select name="assigned_to_user_id"><option value="">' . $h($t('workshop.assignee_unassigned')) . '</option>';
    foreach ($assignable as $manager) {
        $sel = (int)($work['assigned_to_user_id'] ?? 0) === (int)$manager['id'] ? ' selected' : '';
        echo '<option value="' . (int)$manager['id'] . '"' . $sel . '>' . $h((string)$manager['display_name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . $h($t('workshop.due')) . '<input type="datetime-local" name="due_at" value="' . $h(bakery_exception_workshop_due_for_save($work)) . '"></label>';
    echo '<label>' . $h($t('workshop.note')) . '<textarea name="resolution_note" rows="3" maxlength="2000">' . $h((string)($work['resolution_note'] ?? '')) . '</textarea></label>';
    echo '<div class="exception-workshop__work-actions">';
    echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit">' . $h($t('workshop.save_work')) . '</button>';
    echo '<button class="sf-btn sf-btn--primary sf-btn--sm" type="submit" name="complete" value="1">' . $h($t('workshop.complete')) . '</button>';
    echo '</div>';
    echo '<p class="exception-workshop__hint">' . $h($t('workshop.complete_does_not_clear')) . '</p>';
    echo '</form>';

    echo '<div class="exception-workshop__create">';
    echo '<h4>' . $h($t('workshop.create_heading')) . '</h4>';
    echo '<p>' . $h($t('workshop.create_lede')) . '</p>';
    echo '<div class="exception-workshop__create-actions">';
    if ($type === 'delivery_failed' || $untriaged !== [] || $matchedCases !== []) {
        echo '<a class="sf-btn sf-btn--outline sf-btn--sm" href="#failed-stop-recovery">' . $h($t('workshop.open_recovery')) . '</a>';
    }
    if ($customerId > 0 && function_exists('bakery_ops_link_service_issues')) {
        echo '<a class="sf-btn sf-btn--outline sf-btn--sm" href="' . $h(bakery_ops_link_service_issues(['status' => 'open', 'customer_id' => $customerId], 'manager')) . '">' . $h($t('workshop.open_service')) . '</a>';
    } elseif (function_exists('bakery_ops_link_service_issues')) {
        echo '<a class="sf-btn sf-btn--outline sf-btn--sm" href="' . $h(bakery_ops_link_service_issues(['status' => 'open'], 'manager')) . '">' . $h($t('workshop.open_service')) . '</a>';
    }
    if ($type === 'demand_changed_since') {
        echo '<form method="post" class="exception-workshop__inline" action="' . $h($self) . '" onsubmit="return confirm(' . $h(json_encode($t('workshop.confirm_demand'))) . ');">';
        if (function_exists('bakery_csrf_field')) {
            echo bakery_csrf_field();
        }
        echo '<input type="hidden" name="manager_mutation" value="workshop_confirm_demand">';
        echo '<button class="sf-btn sf-btn--outline sf-btn--sm" type="submit">' . $h($t('workshop.confirm_demand_again')) . '</button></form>';
    }
    echo '</div></div>';

    echo '<div class="exception-workshop__related">';
    echo '<h4>' . $h($t('workshop.related_heading')) . '</h4>';
    if ($related === []) {
        echo '<p class="exception-workshop__muted">' . $h($t('workshop.related_none')) . '</p>';
    } else {
        echo '<ul>';
        foreach ($related as $rel) {
            $relHref = (string)($rel['href'] ?? '');
            echo '<li><strong>' . $h((string)($rel['title'] ?? '')) . '</strong>';
            echo '<span>' . $h((string)($rel['detail'] ?? '')) . '</span>';
            if ($relHref !== '') {
                echo '<a href="' . $h($relHref) . '">' . $h((string)($rel['action'] ?? $t('workshop.fix'))) . '</a>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    if ($issues !== []) {
        echo '<h4>' . $h($t('workshop.service_heading')) . '</h4><ul>';
        foreach ($issues as $issue) {
            $issueHref = $base . 'service_issues.php?id=' . (int)($issue['id'] ?? 0) . '&return=manager';
            echo '<li><a href="' . $h($issueHref) . '">' . $h((string)($issue['category_label'] ?? $issue['category'] ?? 'Issue')) . '</a> · ' . $h((string)($issue['status_label'] ?? $issue['status'] ?? '')) . '</li>';
        }
        echo '</ul>';
    }
    if ($matchedCases !== []) {
        echo '<h4>' . $h($t('workshop.recovery_heading')) . '</h4><ul>';
        foreach ($matchedCases as $case) {
            echo '<li><a href="#failed-stop-recovery">' . $h((string)($case['customer_name'] ?? $t('workshop.recovery_case'))) . '</a> · ' . $h(str_replace('_', ' ', (string)($case['workflow_state'] ?? ''))) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';

    echo '<div class="exception-workshop__timeline">';
    echo '<h4>' . $h($t('workshop.tried_heading')) . '</h4>';
    if ($timeline === []) {
        echo '<p class="exception-workshop__muted">' . $h($t('workshop.tried_none')) . '</p>';
    } else {
        echo '<ol>';
        foreach ($timeline as $event) {
            echo '<li><time datetime="' . $h((string)($event['occurred_at'] ?? '')) . '">' . $h(!empty($event['occurred_at']) ? date('g:i A', strtotime((string)$event['occurred_at'])) : '') . '</time>';
            echo '<div><strong>' . $h((string)($event['summary'] ?? '')) . '</strong>';
            if (function_exists('bakery_operational_actor_label')) {
                echo '<small>' . $h(bakery_operational_actor_label($event)) . '</small>';
            }
            echo '</div></li>';
        }
        echo '</ol>';
    }
    echo '<a href="' . $h($base . 'operational_timeline.php?context=day&date=' . rawurlencode($date)) . '">' . $h($t('workshop.full_timeline')) . '</a>';
    echo '</div></article>';
}

function bakery_exception_workshop_render_script(string $date, array $filters): void
{
    $cookie = BAKERY_WORKSHOP_COOKIE;
    $group = json_encode((string)$filters['group']);
    ?>
<script>
(function () {
  var root = document.querySelector('.exception-workshop');
  if (!root) return;
  var rows = Array.prototype.slice.call(root.querySelectorAll('.exception-workshop__row'));
  var details = Array.prototype.slice.call(root.querySelectorAll('.exception-workshop__detail'));
  var active = 0;
  rows.forEach(function (row, i) {
    if (row.classList.contains('is-selected')) active = i;
  });
  function show(index) {
    if (!rows.length) return;
    active = (index + rows.length) % rows.length;
    rows.forEach(function (row, i) {
      row.classList.toggle('is-selected', i === active);
    });
    var key = rows[active].getAttribute('data-work-key');
    details.forEach(function (panel) {
      var on = panel.getAttribute('data-detail-key') === key;
      panel.hidden = !on;
      panel.classList.toggle('is-active', on);
    });
  }
  function typing() {
    var el = document.activeElement;
    if (!el) return false;
    var tag = (el.tagName || '').toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
  }
  rows.forEach(function (row, i) {
    row.addEventListener('click', function (ev) {
      if (ev.target && ev.target.closest && ev.target.closest('input')) return;
      show(i);
    });
  });
  document.addEventListener('keydown', function (ev) {
    if (!root.offsetParent || typing()) return;
    if (ev.key === 'j') { ev.preventDefault(); show(active + 1); }
    if (ev.key === 'k') { ev.preventDefault(); show(active - 1); }
    if (ev.key === 'Enter' && rows[active]) {
      var href = rows[active].getAttribute('data-fix-href');
      if (href) { ev.preventDefault(); window.location = href; }
    }
    if (ev.key === 'a' && rows[active]) {
      ev.preventDefault();
      var box = rows[active].querySelector('input[type="checkbox"]');
      if (box) box.checked = true;
      var form = document.getElementById('exception-workshop-bulk');
      if (form) {
        var mut = form.querySelector('#workshop-bulk-mutation');
        if (mut) mut.value = 'workshop_bulk_mine';
        form.submit();
      }
    }
    if (ev.key === 'c' && rows[active]) {
      var panel = root.querySelector('.exception-workshop__detail.is-active, .exception-workshop__detail:not([hidden])');
      var noteEl = panel ? panel.querySelector('textarea[name="resolution_note"]') : null;
      var typed = noteEl && String(noteEl.value).replace(/^\s+|\s+$/g, '') !== '';
      if (rows[active].getAttribute('data-has-note') !== '1' && !typed) return;
      ev.preventDefault();
      if (panel) {
        var complete = panel.querySelector('button[name="complete"]');
        if (complete) complete.click();
      }
    }
  });
  var bulk = document.getElementById('exception-workshop-bulk');
  if (bulk) {
    bulk.addEventListener('submit', function (ev) {
      var btn = ev.submitter;
      var msg = btn && btn.getAttribute('data-confirm');
      if (msg && !window.confirm(msg)) {
        ev.preventDefault();
      }
    });
  }
  try {
    var payload = {
      group: <?php echo $group; ?>,
      severity: <?php echo json_encode((string)$filters['severity']); ?>,
      category: <?php echo json_encode((string)$filters['category']); ?>,
      assignee: <?php echo json_encode((string)$filters['assignee']); ?>,
      coord: <?php echo json_encode((string)$filters['coord']); ?>,
      type: <?php echo json_encode((string)$filters['type']); ?>,
      hide_completed: <?php echo !empty($filters['hide_completed']) ? 'true' : 'false'; ?>
    };
    document.cookie = <?php echo json_encode($cookie); ?> + '=' + encodeURIComponent(JSON.stringify(payload)) + '; path=/; max-age=2592000; samesite=lax';
  } catch (e) {}
  document.querySelectorAll('.manager-driver-board a[href*="driver_id="]').forEach(function (link) {
    var match = link.getAttribute('href').match(/driver_id=(\d+)/);
    if (!match || link.parentElement.querySelector('.exception-workshop-jump')) return;
    var a = document.createElement('a');
    a.className = 'exception-workshop-jump';
    a.href = <?php echo json_encode((defined('BASE_URL') ? BASE_URL : '') . 'manager.php?date=' . rawurlencode($date) . '&ex_group=driver'); ?>;
    a.textContent = <?php echo json_encode(function_exists('bakery_t') ? bakery_t('workshop.jump_driver') : 'Situations'); ?>;
    link.parentElement.appendChild(a);
  });
})();
</script>
    <?php
}
