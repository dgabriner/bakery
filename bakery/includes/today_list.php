<?php
/**
 * Read-only list of what a manager should act on today and tomorrow.
 *
 * Collects Daily Run blockers (which already include the command center),
 * missed failed stops, daily status routes, pack shortfalls, billing line
 * prices, and account-health past-dated Pending orders. It does not write.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/daily_status.php';
require_once __DIR__ . '/exception_desk.php';
require_once __DIR__ . '/account_health.php';
require_once __DIR__ . '/billing.php';
require_once __DIR__ . '/manager_phone.php';

function bakery_today_method_allowed(string $method): bool
{
    $method = strtoupper(trim($method));
    return $method === 'GET' || $method === 'HEAD';
}

function bakery_today_when_label(string $when): string
{
    $key = 'today.when.' . $when;
    $label = bakery_t($key);
    return $label === $key ? $when : $label;
}

function bakery_today_store_name(string $name): string
{
    $name = trim($name);
    if ($name !== '') {
        return $name;
    }
    return bakery_t('today.item.store_fallback');
}

/**
 * @param array<string, mixed> $blocker
 */
function bakery_today_blocker_sentence(array $blocker, string $when): string
{
    $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($blocker['type'] ?? ''))) ?? '';
    $whenLabel = bakery_today_when_label($when);
    $params = [
        'when' => $whenLabel,
        'count' => (string)(int)($blocker['count'] ?? 0),
        'title' => trim((string)($blocker['title'] ?? '')),
        'detail' => trim((string)($blocker['detail'] ?? '')),
    ];
    if ($type !== '') {
        $key = 'today.blocker.' . $type;
        $translated = bakery_t($key, $params);
        if ($translated !== $key) {
            return $translated;
        }
    }
    $detail = $params['detail'] !== '' ? $params['detail'] : $params['title'];
    return bakery_t('today.blocker.generic', [
        'when' => $whenLabel,
        'detail' => $detail,
    ]);
}

/**
 * @param array<string, mixed> $item
 * @param array<string, bool> $seen
 * @param list<array<string, mixed>> $items
 */
function bakery_today_push(array &$items, array &$seen, array $item): void
{
    $key = implode('|', [
        (string)($item['kind'] ?? ''),
        (string)($item['when'] ?? ''),
        (string)($item['date'] ?? ''),
        (string)($item['type'] ?? ''),
        (string)($item['sentence'] ?? ''),
    ]);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $items[] = $item;
}

/**
 * Severity first (same order as the exception desk), then today, tomorrow, earlier.
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function bakery_today_rank(array $items): array
{
    $whenRank = ['today' => '0', 'tomorrow' => '1', 'past' => '2'];
    foreach ($items as &$item) {
        $when = $whenRank[(string)($item['when'] ?? '')] ?? '9';
        $item['title'] = $when . ' ' . (string)($item['sentence'] ?? '');
    }
    unset($item);
    $ranked = bakery_exception_desk_sort($items);
    foreach ($ranked as &$item) {
        unset($item['title']);
    }
    unset($item);
    return $ranked;
}

/**
 * @param array<string, mixed> $fields
 * @return array<string, mixed>
 */
function bakery_today_item(array $fields): array
{
    return [
        'severity' => bakery_exception_desk_severity($fields),
        'sentence' => (string)($fields['sentence'] ?? ''),
        'href' => (string)($fields['href'] ?? ''),
        'date' => (string)($fields['date'] ?? ''),
        'when' => (string)($fields['when'] ?? ''),
        'kind' => (string)($fields['kind'] ?? ''),
        'type' => (string)($fields['type'] ?? ''),
    ];
}

/**
 * @param list<array<string, mixed>> $blockers
 * @param array<string, bool> $skipTypes
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_blockers(array $blockers, array $skipTypes, string $date, string $when, array &$items, array &$seen): void
{
    foreach ($blockers as $blocker) {
        if (!is_array($blocker)) {
            continue;
        }
        $type = (string)($blocker['type'] ?? '');
        if ($type !== '' && isset($skipTypes[$type])) {
            continue;
        }
        $href = trim((string)($blocker['href'] ?? ''));
        if ($href === '') {
            $blocker['_date'] = $date;
            $href = bakery_exception_desk_fix_href($blocker);
        }
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => (string)($blocker['severity'] ?? 'warning'),
            'sentence' => bakery_today_blocker_sentence($blocker, $when),
            'href' => $href,
            'date' => $date,
            'when' => $when,
            'kind' => 'daily_run',
            'type' => $type,
        ]));
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_routes(PDO $db, string $date, string $when, array &$items, array &$seen): void
{
    $whenLabel = bakery_today_when_label($when);
    $routes = bakery_daily_status_load_routes($db, $date);
    foreach (bakery_daily_status_unassigned_stops($routes['orders'] ?? []) as $stop) {
        $store = bakery_today_store_name((string)($stop['store_name'] ?? ''));
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'critical',
            'sentence' => bakery_t('today.item.unassigned', ['when' => $whenLabel, 'store' => $store]),
            'href' => bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned'], 'today'),
            'date' => $date,
            'when' => $when,
            'kind' => 'unassigned',
            'type' => 'delivery_unassigned',
        ]));
    }
    foreach (bakery_daily_status_standing_orders_without_route($db, $date) as $stop) {
        $store = bakery_today_store_name((string)($stop['store_name'] ?? ''));
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'warning',
            'sentence' => bakery_t('today.item.standing_gap', ['when' => $whenLabel, 'store' => $store]),
            'href' => bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned'], 'today'),
            'date' => $date,
            'when' => $when,
            'kind' => 'standing_gap',
            'type' => 'standing_without_route',
        ]));
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_missed(PDO $db, string $date, string $when, array &$items, array &$seen): void
{
    $whenLabel = bakery_today_when_label($when);
    $missed = bakery_manager_phone_missed($db, $date, ['unassigned_by_zone' => []]);
    foreach ($missed['failed'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $store = bakery_today_store_name((string)($row['customer_name'] ?? ''));
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'critical',
            'sentence' => bakery_t('today.item.failed', ['when' => $whenLabel, 'store' => $store]),
            'href' => bakery_ops_link_append_return(bakery_manager_phone_href($date, 'missed'), 'today'),
            'date' => $date,
            'when' => $when,
            'kind' => 'failed',
            'type' => 'delivery_failed',
        ]));
    }

    foreach (bakery_daily_status_stops($db, $date) as $stop) {
        if ((string)($stop['delivery_status'] ?? '') !== 'delivered' || !empty($stop['photo_present'])) {
            continue;
        }
        $store = bakery_today_store_name((string)($stop['store_name'] ?? ''));
        $driverId = (int)($stop['driver_id'] ?? 0);
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'warning',
            'sentence' => bakery_t('today.item.unphotographed', ['when' => $whenLabel, 'store' => $store]),
            'href' => bakery_ops_link_append_return(bakery_route_summary_query($date, $driverId, 'missing'), 'today'),
            'date' => $date,
            'when' => $when,
            'kind' => 'unphotographed',
            'type' => 'delivery_unphotographed',
        ]));
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_pack(PDO $db, string $date, string $when, array &$items, array &$seen): void
{
    $whenLabel = bakery_today_when_label($when);
    foreach (bakery_exception_desk_product_shortages($db, $date) as $shortage) {
        if (!is_array($shortage)) {
            continue;
        }
        $product = trim((string)($shortage['product_name'] ?? ''));
        if ($product === '') {
            $product = bakery_t('today.item.product_fallback');
        }
        $short = (int)($shortage['short'] ?? 0);
        $card = bakery_exception_desk_shortage_exception($shortage, $date);
        $href = trim((string)($card['href'] ?? ''));
        if ($href === '' || strpos($href, 'attention=shortfall') === false) {
            $href = bakery_ops_link_pack_list($date, ['attention' => 'shortfall'], 'today');
        } else {
            $href = bakery_ops_link_append_return($href, 'today');
        }
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'critical',
            'sentence' => bakery_t('today.item.pack', [
                'when' => $whenLabel,
                'product' => $product,
                'count' => (string)$short,
            ]),
            'href' => $href,
            'date' => $date,
            'when' => $when,
            'kind' => 'pack',
            'type' => 'production_fg_shortfall',
        ]));
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_billing(PDO $db, string $date, string $when, array &$items, array &$seen): void
{
    $whenLabel = bakery_today_when_label($when);
    $orders = bakery_billing_query_orders($db, [
        'start_date' => $date,
        'end_date' => $date,
        'status' => 'all',
    ]);
    $ids = [];
    foreach ($orders as $order) {
        $ids[] = (int)($order['id'] ?? 0);
    }
    $enriched = bakery_billing_enrich_orders($orders, bakery_billing_load_items($db, $ids));
    foreach ($enriched as $order) {
        $store = bakery_today_store_name((string)($order['customer_name'] ?? ''));
        $customerId = (int)($order['customer_id'] ?? 0);
        $flags = is_array($order['flags'] ?? null) ? $order['flags'] : [];
        if (in_array('missing_invoice', $flags, true)) {
            bakery_today_push($items, $seen, bakery_today_item([
                'severity' => 'info',
                'sentence' => bakery_t('today.item.unconfirmed', ['when' => $whenLabel, 'store' => $store]),
                'href' => bakery_ops_link_billing($date, [
                    'attention' => 'needs_attention',
                    'customer_id' => $customerId,
                ], 'today'),
                'date' => $date,
                'when' => $when,
                'kind' => 'unconfirmed',
                'type' => 'invoice_unconfirmed',
            ]));
        }
        $lines = is_array($order['items'] ?? null) ? $order['items'] : [];
        foreach ($lines as $line) {
            if (!is_array($line) || !empty($line['has_price']) || (int)($line['quantity'] ?? 0) <= 0) {
                continue;
            }
            $product = trim((string)($line['product_name'] ?? ''));
            if ($product === '') {
                $product = bakery_t('today.item.product_fallback');
            }
            bakery_today_push($items, $seen, bakery_today_item([
                'severity' => 'warning',
                'sentence' => bakery_t('today.item.unpriced', [
                    'when' => $whenLabel,
                    'store' => $store,
                    'product' => $product,
                ]),
                'href' => bakery_ops_link_billing($date, [
                    'attention' => 'needs_attention',
                    'customer_id' => $customerId,
                ], 'today'),
                'date' => $date,
                'when' => $when,
                'kind' => 'unpriced',
                'type' => 'unpriced_line',
            ]));
        }
    }
}

/**
 * @param list<array<string, mixed>> $items
 * @param array<string, bool> $seen
 */
function bakery_today_push_stale_pending(PDO $db, string $asOf, array &$items, array &$seen): void
{
    $href = bakery_ops_link_append_return(
        (defined('BASE_URL') ? BASE_URL : '') . bakery_account_health_url([], [
            'sort' => 'stale',
            'dir' => 'desc',
        ]),
        'today'
    );
    foreach (bakery_account_health_rows($db, $asOf) as $row) {
        $count = (int)($row['stale_pending_count'] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $store = bakery_today_store_name((string)($row['customer_name'] ?? ''));
        bakery_today_push($items, $seen, bakery_today_item([
            'severity' => 'warning',
            'sentence' => bakery_t('today.item.stale_pending', [
                'store' => $store,
                'count' => (string)$count,
            ]),
            'href' => $href,
            'date' => '',
            'when' => 'past',
            'kind' => 'stale_pending',
            'type' => 'stale_pending',
        ]));
    }
}

/**
 * @return array{ok:bool,complete:bool,date:string,tomorrow:string,items:list<array<string,mixed>>}
 */
function bakery_today_collect(PDO $db, string $asOf): array
{
    $asOf = bakery_driver_validate_delivery_date($asOf);
    $tomorrow = date('Y-m-d', strtotime($asOf . ' +1 day'));
    $items = [];
    $seen = [];
    $complete = true;
    $dates = ['today' => $asOf, 'tomorrow' => $tomorrow];

    foreach ($dates as $when => $date) {
        $added = [];
        $addedSeen = [];
        $steps = [
            'routes' => static function () use ($db, $date, $when, &$added, &$addedSeen): void {
                bakery_today_push_routes($db, $date, $when, $added, $addedSeen);
            },
            'missed' => static function () use ($db, $date, $when, &$added, &$addedSeen): void {
                bakery_today_push_missed($db, $date, $when, $added, $addedSeen);
            },
            'pack' => static function () use ($db, $date, $when, &$added, &$addedSeen): void {
                bakery_today_push_pack($db, $date, $when, $added, $addedSeen);
            },
            'billing' => static function () use ($db, $date, $when, &$added, &$addedSeen): void {
                bakery_today_push_billing($db, $date, $when, $added, $addedSeen);
            },
        ];
        foreach ($steps as $label => $step) {
            try {
                $step();
            } catch (Throwable $e) {
                $complete = false;
                error_log('today ' . $label . ' ' . $date . ': ' . $e->getMessage());
            }
        }

        $skip = [];
        foreach ($added as $item) {
            $type = (string)($item['type'] ?? '');
            if (in_array($type, ['delivery_unassigned', 'delivery_failed', 'production_fg_shortfall', 'invoice_unconfirmed'], true)) {
                $skip[$type] = true;
            }
        }
        try {
            $run = bakery_daily_run_build($db, $date);
            bakery_today_push_blockers($run['blockers'] ?? [], $skip, $date, $when, $added, $addedSeen);
        } catch (Throwable $e) {
            $complete = false;
            error_log('today daily_run ' . $date . ': ' . $e->getMessage());
        }
        foreach ($added as $item) {
            bakery_today_push($items, $seen, $item);
        }
    }

    try {
        bakery_today_push_stale_pending($db, $asOf, $items, $seen);
    } catch (Throwable $e) {
        $complete = false;
        error_log('today stale_pending: ' . $e->getMessage());
    }

    return [
        'ok' => true,
        'complete' => $complete,
        'date' => $asOf,
        'tomorrow' => $tomorrow,
        'items' => bakery_today_rank($items),
    ];
}

/**
 * @param list<array<string, mixed>> $items
 */
function bakery_today_render_items(array $items, bool $complete = true): string
{
    if ($items === [] && $complete) {
        return '<p class="today-clear" role="status">'
            . htmlspecialchars(bakery_t('today.all_clear'), ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
    if ($items === []) {
        return '<p class="today-unavailable" role="alert">'
            . htmlspecialchars(bakery_t('today.unavailable'), ENT_QUOTES, 'UTF-8')
            . '</p>';
    }

    $html = '';
    if (!$complete) {
        $html .= '<p class="today-partial" role="status">'
            . htmlspecialchars(bakery_t('today.partial'), ENT_QUOTES, 'UTF-8')
            . '</p>';
    }
    $html .= '<ol class="today-list">';
    foreach ($items as $item) {
        $severity = bakery_exception_desk_severity($item);
        $severityLabel = bakery_t('today.severity.' . $severity);
        $whenLabel = bakery_today_when_label((string)($item['when'] ?? ''));
        $html .= '<li class="today-item today-item--' . htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<p class="today-item__meta"><span class="today-item__severity">'
            . htmlspecialchars($severityLabel, ENT_QUOTES, 'UTF-8')
            . '</span> <span class="today-item__when">'
            . htmlspecialchars($whenLabel, ENT_QUOTES, 'UTF-8')
            . '</span></p>';
        $html .= '<p class="today-item__sentence">'
            . htmlspecialchars((string)($item['sentence'] ?? ''), ENT_QUOTES, 'UTF-8')
            . '</p>';
        $href = trim((string)($item['href'] ?? ''));
        if ($href !== '') {
            $html .= '<a class="today-item__fix" href="'
                . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                . '">'
                . htmlspecialchars(bakery_t('today.fix'), ENT_QUOTES, 'UTF-8')
                . '</a>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>';
    return $html;
}

/**
 * @param array{ok:bool,complete:bool,date:string,tomorrow:string,items:list<array<string,mixed>>} $collected
 * @return array<string, mixed>
 */
function bakery_today_payload(array $collected): array
{
    $public = [];
    foreach ($collected['items'] as $item) {
        $public[] = [
            'severity' => bakery_exception_desk_severity($item),
            'sentence' => (string)($item['sentence'] ?? ''),
            'href' => (string)($item['href'] ?? ''),
            'date' => (string)($item['date'] ?? ''),
            'when' => (string)($item['when'] ?? ''),
            'kind' => (string)($item['kind'] ?? ''),
            'type' => (string)($item['type'] ?? ''),
        ];
    }
    $complete = !empty($collected['complete']);
    return [
        'ok' => true,
        'date' => (string)$collected['date'],
        'tomorrow' => (string)$collected['tomorrow'],
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'complete' => $complete,
        'clear' => $complete && $public === [],
        'items' => $public,
    ];
}

/**
 * JSON entry. Session or bearer token, same rule as daily_status_api.
 *
 * @param array<string, mixed> $server
 * @return array{status:int,body:array<string,mixed>}
 */
function bakery_today_dispatch(PDO $db, string $method, string $dateRaw, array $server): array
{
    if (!bakery_today_method_allowed($method)) {
        return ['status' => 405, 'body' => ['ok' => false, 'error' => 'method_not_allowed']];
    }
    $auth = bakery_daily_status_auth_status($server);
    if ($auth !== 200) {
        return [
            'status' => $auth,
            'body' => ['ok' => false, 'error' => $auth === 403 ? 'forbidden' : 'unauthorized'],
        ];
    }
    if ($dateRaw !== '') {
        try {
            $date = bakery_driver_validate_delivery_date($dateRaw);
        } catch (RuntimeException $e) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_date']];
        }
    } else {
        $date = date('Y-m-d');
    }
    try {
        return ['status' => 200, 'body' => bakery_today_payload(bakery_today_collect($db, $date))];
    } catch (Throwable $e) {
        error_log('today: ' . $e->getMessage());
        return ['status' => 500, 'body' => ['ok' => false, 'error' => 'unavailable']];
    }
}
