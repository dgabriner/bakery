<?php
/**
 * Wholesale account health.
 *
 * Standing quantity is never an input. An account is lapsed only when the
 * lapse window has no dated order and no completed or photo-confirmed
 * delivery. Photo confirmation is a driver_photos row for that customer and
 * delivery date, so a stop whose order is still Pending after a photo still
 * counts. Dated orders on or after the lapse start count too, including a
 * future dated order that has not been delivered yet.
 *
 * Thresholds (one block):
 *   BAKERY_ACCOUNT_HEALTH_RECENT_DAYS — delivered units, inclusive, ending on as-of
 *   BAKERY_ACCOUNT_HEALTH_PRIOR_DAYS — the window immediately before that
 *   BAKERY_ACCOUNT_HEALTH_SHARE_DAYS — delivered vs ordered share
 *   BAKERY_ACCOUNT_HEALTH_LAPSE_DAYS — dated orders and delivery evidence
 *   BAKERY_ACCOUNT_HEALTH_SHRINK_PERCENT — drop vs the prior window that flags shrinking
 *   BAKERY_ACCOUNT_HEALTH_SHRINK_MIN_PRIOR_UNITS — prior volume required before shrinking
 *
 * Example for as-of 2026-10-07: recent is 2026-08-13 through 2026-10-07,
 * prior is 2026-06-18 through 2026-08-12, and a photo-confirmed delivery on
 * 2026-10-07 keeps the account out of lapsed even when standing quantity is 0.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/billing_aging.php';
require_once __DIR__ . '/sfb_origin.php';

const BAKERY_ACCOUNT_HEALTH_RECENT_DAYS = 56;
const BAKERY_ACCOUNT_HEALTH_PRIOR_DAYS = 56;
const BAKERY_ACCOUNT_HEALTH_SHARE_DAYS = 28;
const BAKERY_ACCOUNT_HEALTH_LAPSE_DAYS = 56;
const BAKERY_ACCOUNT_HEALTH_SHRINK_PERCENT = 25.0;
const BAKERY_ACCOUNT_HEALTH_SHRINK_MIN_PRIOR_UNITS = 8;

/**
 * @return array{
 *   recent_days:int,
 *   prior_days:int,
 *   share_days:int,
 *   lapse_days:int,
 *   shrink_percent:float,
 *   shrink_min_prior_units:int
 * }
 */
function bakery_account_health_thresholds(): array
{
    return [
        'recent_days' => BAKERY_ACCOUNT_HEALTH_RECENT_DAYS,
        'prior_days' => BAKERY_ACCOUNT_HEALTH_PRIOR_DAYS,
        'share_days' => BAKERY_ACCOUNT_HEALTH_SHARE_DAYS,
        'lapse_days' => BAKERY_ACCOUNT_HEALTH_LAPSE_DAYS,
        'shrink_percent' => (float)BAKERY_ACCOUNT_HEALTH_SHRINK_PERCENT,
        'shrink_min_prior_units' => BAKERY_ACCOUNT_HEALTH_SHRINK_MIN_PRIOR_UNITS,
    ];
}

/**
 * Inclusive windows ending on $asOf (YYYY-MM-DD).
 *
 * @return array<string, string>
 */
function bakery_account_health_windows(string $asOf): array
{
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $asOf);
    if (!$end || $end->format('Y-m-d') !== $asOf) {
        throw new InvalidArgumentException('Account health as-of date must be YYYY-MM-DD');
    }
    $thresholds = bakery_account_health_thresholds();
    $recentStart = $end->sub(new DateInterval('P' . ((int)$thresholds['recent_days'] - 1) . 'D'));
    $priorEnd = $recentStart->sub(new DateInterval('P1D'));
    $priorStart = $priorEnd->sub(new DateInterval('P' . ((int)$thresholds['prior_days'] - 1) . 'D'));
    $shareStart = $end->sub(new DateInterval('P' . ((int)$thresholds['share_days'] - 1) . 'D'));
    $lapseStart = $end->sub(new DateInterval('P' . ((int)$thresholds['lapse_days'] - 1) . 'D'));

    return [
        'as_of' => $end->format('Y-m-d'),
        'recent_start' => $recentStart->format('Y-m-d'),
        'recent_end' => $end->format('Y-m-d'),
        'prior_start' => $priorStart->format('Y-m-d'),
        'prior_end' => $priorEnd->format('Y-m-d'),
        'share_start' => $shareStart->format('Y-m-d'),
        'share_end' => $end->format('Y-m-d'),
        'lapse_start' => $lapseStart->format('Y-m-d'),
        'lapse_end' => $end->format('Y-m-d'),
    ];
}

function bakery_account_health_change_percent(int $recent, int $prior): ?float
{
    if ($prior <= 0) {
        return null;
    }
    return round((($recent - $prior) / $prior) * 100, 1);
}

function bakery_account_health_delivered_share(int $delivered, int $ordered): ?float
{
    if ($ordered <= 0) {
        return null;
    }
    return round(($delivered / $ordered) * 100, 1);
}

/**
 * Lapsed / shrinking / ok. Extra keys such as standing quantity are ignored.
 *
 * @param array<string, mixed> $row
 */
function bakery_account_health_status(array $row): string
{
    $dated = (int)($row['dated_orders_in_lapse'] ?? 0);
    $evidence = (int)($row['delivery_evidence_in_lapse'] ?? 0);
    if ($dated <= 0 && $evidence <= 0) {
        return 'lapsed';
    }
    $prior = (int)($row['prior_delivered_units'] ?? 0);
    $recent = (int)($row['recent_delivered_units'] ?? 0);
    if ($prior >= BAKERY_ACCOUNT_HEALTH_SHRINK_MIN_PRIOR_UNITS) {
        $keepPerMille = 1000 - (int)round(BAKERY_ACCOUNT_HEALTH_SHRINK_PERCENT * 10);
        $floor = intdiv($prior * $keepPerMille, 1000);
        if ($recent <= $floor) {
            return 'shrinking';
        }
    }
    return 'ok';
}

function bakery_account_health_normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    if (in_array($status, ['lapsed', 'shrinking', 'ok', 'all'], true)) {
        return $status;
    }
    return 'all';
}

function bakery_account_health_normalize_sort(string $sort): string
{
    $sort = strtolower(trim($sort));
    if (in_array($sort, ['name', 'recent', 'prior', 'change', 'last', 'stale', 'share', 'balance', 'status'], true)) {
        return $sort;
    }
    return 'status';
}

function bakery_account_health_normalize_dir(string $dir): string
{
    return strtolower(trim($dir)) === 'desc' ? 'desc' : 'asc';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function bakery_account_health_filter(array $rows, string $status): array
{
    $status = bakery_account_health_normalize_status($status);
    $rows = array_values($rows);
    if ($status === 'all') {
        return $rows;
    }
    $kept = [];
    foreach ($rows as $row) {
        if ((string)($row['status'] ?? '') === $status) {
            $kept[] = $row;
        }
    }
    return $kept;
}

/**
 * @param array<int|string, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function bakery_account_health_sort(array $rows, string $sort, string $dir): array
{
    $sort = bakery_account_health_normalize_sort($sort);
    $dir = bakery_account_health_normalize_dir($dir);
    $rows = array_values($rows);
    $rank = ['lapsed' => 0, 'shrinking' => 1, 'ok' => 2];
    usort($rows, static function (array $a, array $b) use ($sort, $dir, $rank): int {
        $cmp = bakery_account_health_compare($a, $b, $sort, $rank);
        if ($cmp === 0) {
            $cmp = strcasecmp((string)($a['customer_name'] ?? ''), (string)($b['customer_name'] ?? ''));
        }
        if ($cmp === 0) {
            $cmp = ((int)($a['customer_id'] ?? 0)) <=> ((int)($b['customer_id'] ?? 0));
        }
        return $dir === 'desc' ? -$cmp : $cmp;
    });
    return $rows;
}

/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 * @param array<string, int> $rank
 */
function bakery_account_health_compare(array $a, array $b, string $sort, array $rank): int
{
    switch ($sort) {
        case 'name':
            return strcasecmp((string)($a['customer_name'] ?? ''), (string)($b['customer_name'] ?? ''));
        case 'recent':
            return ((int)($a['recent_delivered_units'] ?? 0)) <=> ((int)($b['recent_delivered_units'] ?? 0));
        case 'prior':
            return ((int)($a['prior_delivered_units'] ?? 0)) <=> ((int)($b['prior_delivered_units'] ?? 0));
        case 'change':
            return bakery_account_health_nullable_cmp($a['change_percent'] ?? null, $b['change_percent'] ?? null);
        case 'last':
            return bakery_account_health_nullable_cmp($a['last_delivered_date'] ?? null, $b['last_delivered_date'] ?? null);
        case 'stale':
            return ((int)($a['stale_pending_count'] ?? 0)) <=> ((int)($b['stale_pending_count'] ?? 0));
        case 'share':
            return bakery_account_health_nullable_cmp($a['delivered_share_percent'] ?? null, $b['delivered_share_percent'] ?? null);
        case 'balance':
            return ((float)($a['balance'] ?? 0)) <=> ((float)($b['balance'] ?? 0));
        case 'status':
            return ($rank[(string)($a['status'] ?? '')] ?? 9) <=> ($rank[(string)($b['status'] ?? '')] ?? 9);
        default:
            return 0;
    }
}

function bakery_account_health_nullable_cmp($left, $right): int
{
    $leftMissing = $left === null || $left === '';
    $rightMissing = $right === null || $right === '';
    if ($leftMissing && $rightMissing) {
        return 0;
    }
    if ($leftMissing) {
        return 1;
    }
    if ($rightMissing) {
        return -1;
    }
    return $left <=> $right;
}

/**
 * @return array<int, string>
 */
function bakery_account_health_csv_headers(): array
{
    return [
        bakery_t('account_health.csv_customer_id'),
        bakery_t('account_health.col_customer'),
        bakery_t('account_health.col_recent'),
        bakery_t('account_health.col_prior'),
        bakery_t('account_health.col_change'),
        bakery_t('account_health.col_last'),
        bakery_t('account_health.col_stale'),
        bakery_t('account_health.csv_share_delivered'),
        bakery_t('account_health.csv_share_ordered'),
        bakery_t('account_health.col_share'),
        bakery_t('account_health.col_balance'),
        bakery_t('account_health.col_status'),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<int, string>
 */
function bakery_account_health_csv_row(array $row): array
{
    $change = $row['change_percent'] ?? null;
    $share = $row['delivered_share_percent'] ?? null;
    return [
        (string)(int)($row['customer_id'] ?? 0),
        (string)($row['customer_name'] ?? ''),
        (string)(int)($row['recent_delivered_units'] ?? 0),
        (string)(int)($row['prior_delivered_units'] ?? 0),
        $change === null ? '' : number_format((float)$change, 1, '.', ''),
        (string)($row['last_delivered_date'] ?? ''),
        (string)(int)($row['stale_pending_count'] ?? 0),
        (string)(int)($row['share_delivered_units'] ?? 0),
        (string)(int)($row['share_ordered_units'] ?? 0),
        $share === null ? '' : number_format((float)$share, 1, '.', ''),
        number_format((float)($row['balance'] ?? 0), 2, '.', ''),
        (string)($row['status'] ?? ''),
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function bakery_account_health_csv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    fputcsv($handle, bakery_account_health_csv_headers());
    foreach ($rows as $row) {
        fputcsv($handle, bakery_account_health_csv_row($row));
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}

/**
 * One row per active human customer. Read-only.
 *
 * @return array<int, array<string, mixed>>
 */
function bakery_account_health_rows(PDO $db, string $asOf): array
{
    if (!table_exists($db, 'customers') || !table_exists($db, 'daily_orders')) {
        return [];
    }
    $windows = bakery_account_health_windows($asOf);
    $where = 'c.is_active = 1' . bakery_sfb_ops_origin_clause('c', $db);
    $confirmedSelect = column_exists($db, 'daily_orders', 'delivery_confirmed_at')
        ? 'do.delivery_confirmed_at'
        : 'NULL';

    $customers = $db->prepare(
        "SELECT c.id, c.name, c.zone
         FROM customers c
         WHERE {$where}
         ORDER BY c.name ASC, c.id ASC"
    );
    $customers->execute();

    $orders = $db->prepare(
        "SELECT do.customer_id, do.id, do.order_date, do.status, {$confirmedSelect} AS delivery_confirmed_at,
                COALESCE(SUM(doi.quantity), 0) AS ordered_units,
                COALESCE(SUM(COALESCE(doi.delivered_quantity, doi.quantity)), 0) AS line_units
         FROM daily_orders do
         INNER JOIN customers c ON c.id = do.customer_id
         LEFT JOIN daily_order_items doi ON doi.daily_order_id = do.id
         WHERE {$where}
           AND do.order_date >= ?
           AND do.order_date <= ?
         GROUP BY do.customer_id, do.id, do.order_date, do.status, delivery_confirmed_at"
    );
    $orders->execute([$windows['prior_start'], $windows['as_of']]);

    $future = $db->prepare(
        "SELECT do.customer_id, COUNT(*) AS future_orders
         FROM daily_orders do
         INNER JOIN customers c ON c.id = do.customer_id
         WHERE {$where}
           AND do.order_date > ?
         GROUP BY do.customer_id"
    );
    $future->execute([$windows['as_of']]);

    $stale = $db->prepare(
        "SELECT do.customer_id, COUNT(*) AS stale_pending
         FROM daily_orders do
         INNER JOIN customers c ON c.id = do.customer_id
         WHERE {$where}
           AND do.status = 'pending'
           AND do.order_date < ?
         GROUP BY do.customer_id"
    );
    $stale->execute([$windows['as_of']]);

    $lastCompleted = $db->prepare(
        "SELECT do.customer_id, MAX(do.order_date) AS last_completed
         FROM daily_orders do
         INNER JOIN customers c ON c.id = do.customer_id
         WHERE {$where}
           AND do.order_date <= ?
           AND (do.status IN ('delivered', 'invoiced')"
        . (column_exists($db, 'daily_orders', 'delivery_confirmed_at')
            ? ' OR do.delivery_confirmed_at IS NOT NULL'
            : '')
        . ")
         GROUP BY do.customer_id"
    );
    $lastCompleted->execute([$windows['as_of']]);

    $photos = [];
    $lastPhoto = [];
    if (table_exists($db, 'driver_photos')) {
        $photoStmt = $db->prepare(
            "SELECT DISTINCT dp.customer_id, dp.delivery_date
             FROM driver_photos dp
             INNER JOIN customers c ON c.id = dp.customer_id
             WHERE {$where}
               AND dp.delivery_date >= ?
               AND dp.delivery_date <= ?"
        );
        $photoStmt->execute([$windows['prior_start'], $windows['as_of']]);
        foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $customerId = (int)$photo['customer_id'];
            $date = substr((string)$photo['delivery_date'], 0, 10);
            $photos[$customerId][$date] = true;
        }
        $lastPhotoStmt = $db->prepare(
            "SELECT dp.customer_id, MAX(dp.delivery_date) AS last_photo
             FROM driver_photos dp
             INNER JOIN customers c ON c.id = dp.customer_id
             WHERE {$where}
               AND dp.delivery_date <= ?
             GROUP BY dp.customer_id"
        );
        $lastPhotoStmt->execute([$windows['as_of']]);
        foreach ($lastPhotoStmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $lastPhoto[(int)$photo['customer_id']] = substr((string)$photo['last_photo'], 0, 10);
        }
    }

    $futureByCustomer = [];
    foreach ($future->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $futureByCustomer[(int)$row['customer_id']] = (int)$row['future_orders'];
    }
    $staleByCustomer = [];
    foreach ($stale->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $staleByCustomer[(int)$row['customer_id']] = (int)$row['stale_pending'];
    }
    $completedByCustomer = [];
    foreach ($lastCompleted->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $completedByCustomer[(int)$row['customer_id']] = substr((string)$row['last_completed'], 0, 10);
    }

    $totals = [];
    foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $customerId = (int)$order['customer_id'];
        $date = substr((string)$order['order_date'], 0, 10);
        $hasPhoto = !empty($photos[$customerId][$date]);
        $delivered = bakery_account_health_order_is_delivered($order, $hasPhoto);
        if (!isset($totals[$customerId])) {
            $totals[$customerId] = [
                'recent' => 0,
                'prior' => 0,
                'dated' => 0,
                'evidence' => 0,
                'share_ordered' => 0,
                'share_delivered' => 0,
                'evidence_dates' => [],
            ];
        }
        if ($date >= $windows['lapse_start'] && $date <= $windows['lapse_end']) {
            $totals[$customerId]['dated']++;
            if ($delivered) {
                $totals[$customerId]['evidence_dates'][$date] = true;
            }
        }
        $units = (int)$order['line_units'];
        if ($delivered && $date >= $windows['recent_start'] && $date <= $windows['recent_end']) {
            $totals[$customerId]['recent'] += $units;
        }
        if ($delivered && $date >= $windows['prior_start'] && $date <= $windows['prior_end']) {
            $totals[$customerId]['prior'] += $units;
        }
        if ($date >= $windows['share_start'] && $date <= $windows['share_end']) {
            $totals[$customerId]['share_ordered'] += (int)$order['ordered_units'];
            if ($delivered) {
                $totals[$customerId]['share_delivered'] += $units;
            }
        }
    }

    foreach ($photos as $customerId => $dates) {
        foreach (array_keys($dates) as $date) {
            if ($date >= $windows['lapse_start'] && $date <= $windows['lapse_end']) {
                if (!isset($totals[$customerId])) {
                    $totals[$customerId] = [
                        'recent' => 0,
                        'prior' => 0,
                        'dated' => 0,
                        'evidence' => 0,
                        'share_ordered' => 0,
                        'share_delivered' => 0,
                        'evidence_dates' => [],
                    ];
                }
                $totals[$customerId]['evidence_dates'][$date] = true;
            }
        }
    }

    $balances = bakery_billing_customer_balances($db);
    $rows = [];
    foreach ($customers->fetchAll(PDO::FETCH_ASSOC) as $customer) {
        $customerId = (int)$customer['id'];
        $bucket = $totals[$customerId] ?? [
            'recent' => 0,
            'prior' => 0,
            'dated' => 0,
            'share_ordered' => 0,
            'share_delivered' => 0,
            'evidence_dates' => [],
        ];
        $dated = (int)$bucket['dated'] + (int)($futureByCustomer[$customerId] ?? 0);
        $evidence = count($bucket['evidence_dates']);
        $recent = (int)$bucket['recent'];
        $prior = (int)$bucket['prior'];
        $shareOrdered = (int)$bucket['share_ordered'];
        $shareDelivered = (int)$bucket['share_delivered'];
        $lastDates = array_filter([
            $completedByCustomer[$customerId] ?? null,
            $lastPhoto[$customerId] ?? null,
        ]);
        $last = $lastDates === [] ? null : max($lastDates);
        $balance = $balances[$customerId] ?? null;
        $row = [
            'customer_id' => $customerId,
            'customer_name' => (string)$customer['name'],
            'zone' => (string)($customer['zone'] ?? ''),
            'recent_delivered_units' => $recent,
            'prior_delivered_units' => $prior,
            'change_percent' => bakery_account_health_change_percent($recent, $prior),
            'last_delivered_date' => $last,
            'stale_pending_count' => (int)($staleByCustomer[$customerId] ?? 0),
            'share_delivered_units' => $shareDelivered,
            'share_ordered_units' => $shareOrdered,
            'delivered_share_percent' => bakery_account_health_delivered_share($shareDelivered, $shareOrdered),
            'balance' => $balance === null ? 0.0 : (float)$balance['outstanding_total'],
            'balance_oldest_days' => $balance === null ? 0 : (int)$balance['oldest_days'],
            'dated_orders_in_lapse' => $dated,
            'delivery_evidence_in_lapse' => $evidence,
        ];
        $row['status'] = bakery_account_health_status($row);
        $rows[] = $row;
    }
    return $rows;
}

/**
 * @param array<string, mixed> $order
 */
function bakery_account_health_order_is_delivered(array $order, bool $hasPhoto): bool
{
    if ($hasPhoto) {
        return true;
    }
    $status = (string)($order['status'] ?? '');
    if ($status === 'delivered' || $status === 'invoiced') {
        return true;
    }
    $confirmed = $order['delivery_confirmed_at'] ?? null;
    return $confirmed !== null && $confirmed !== '';
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array{all:int, lapsed:int, shrinking:int, ok:int}
 */
function bakery_account_health_summary(array $rows): array
{
    $counts = ['all' => 0, 'lapsed' => 0, 'shrinking' => 0, 'ok' => 0];
    foreach ($rows as $row) {
        $counts['all']++;
        $status = (string)($row['status'] ?? '');
        if (isset($counts[$status]) && $status !== 'all') {
            $counts[$status]++;
        }
    }
    return $counts;
}

/**
 * @param array<string, string> $query
 * @param array<string, string> $overrides
 */
function bakery_account_health_url(array $query, array $overrides = []): string
{
    $merged = array_merge([
        'status' => 'all',
        'sort' => 'status',
        'dir' => 'asc',
    ], $query, $overrides);
    unset($merged['export']);
    $merged['status'] = bakery_account_health_normalize_status((string)$merged['status']);
    $merged['sort'] = bakery_account_health_normalize_sort((string)$merged['sort']);
    $merged['dir'] = bakery_account_health_normalize_dir((string)$merged['dir']);
    return 'account_health.php?' . http_build_query($merged);
}

function bakery_account_health_status_label(string $status): string
{
    switch ($status) {
        case 'lapsed':
            return bakery_t('account_health.status_lapsed');
        case 'shrinking':
            return bakery_t('account_health.status_shrinking');
        case 'ok':
            return bakery_t('account_health.status_ok');
        default:
            return bakery_t('account_health.status_ok');
    }
}

function bakery_account_health_format_change(array $row): string
{
    $recent = (int)($row['recent_delivered_units'] ?? 0);
    $prior = (int)($row['prior_delivered_units'] ?? 0);
    if ($prior <= 0 && $recent > 0) {
        return bakery_t('account_health.change_new');
    }
    $percent = $row['change_percent'] ?? null;
    if ($percent === null) {
        return bakery_t('account_health.change_none');
    }
    $number = (float)$percent;
    $sign = $number > 0 ? '+' : '';
    return $sign . number_format($number, 1) . '%';
}

/**
 * @param array<int, array<string, mixed>> $rows filtered rows for the table
 * @param array<int, array<string, mixed>> $allRows unfiltered rows for the summary
 * @param array<string, string> $query
 */
function bakery_account_health_render(array $rows, array $allRows, array $query): string
{
    $query['status'] = bakery_account_health_normalize_status((string)($query['status'] ?? 'all'));
    $query['sort'] = bakery_account_health_normalize_sort((string)($query['sort'] ?? 'status'));
    $query['dir'] = bakery_account_health_normalize_dir((string)($query['dir'] ?? 'asc'));
    $thresholds = bakery_account_health_thresholds();
    $summary = bakery_account_health_summary($allRows);
    $h = static function (string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    };
    $shrinkLabel = rtrim(rtrim(number_format((float)$thresholds['shrink_percent'], 1, '.', ''), '0'), '.');
    $note = bakery_t('account_health.thresholds_note', [
        'lapse_days' => (string)$thresholds['lapse_days'],
        'shrink_pct' => $shrinkLabel,
        'prior_days' => (string)$thresholds['prior_days'],
        'min_prior' => (string)$thresholds['shrink_min_prior_units'],
    ]);
    $summaryText = bakery_t('account_health.summary', [
        'count' => (string)$summary['all'],
        'lapsed' => (string)$summary['lapsed'],
        'shrinking' => (string)$summary['shrinking'],
    ]);
    $csvUrl = bakery_account_health_url($query, []) . '&export=csv';

    $filters = '';
    foreach (['all' => 'account_health.filter_all', 'lapsed' => 'account_health.status_lapsed', 'shrinking' => 'account_health.status_shrinking', 'ok' => 'account_health.status_ok'] as $value => $key) {
        $class = $query['status'] === $value ? 'sf-btn sf-btn--sm sf-btn--primary' : 'sf-btn sf-btn--sm sf-btn--secondary';
        $filters .= '<a class="' . $class . '" href="' . $h(bakery_account_health_url($query, ['status' => $value])) . '">'
            . $h(bakery_t($key)) . '</a>';
    }

    $columns = [
        'name' => 'account_health.col_customer',
        'recent' => 'account_health.col_recent',
        'prior' => 'account_health.col_prior',
        'change' => 'account_health.col_change',
        'last' => 'account_health.col_last',
        'stale' => 'account_health.col_stale',
        'share' => 'account_health.col_share',
        'balance' => 'account_health.col_balance',
        'status' => 'account_health.col_status',
    ];
    $preferredDir = [
        'name' => 'asc',
        'recent' => 'desc',
        'prior' => 'desc',
        'change' => 'asc',
        'last' => 'asc',
        'stale' => 'desc',
        'share' => 'asc',
        'balance' => 'desc',
        'status' => 'asc',
    ];
    $head = '';
    foreach ($columns as $sortKey => $labelKey) {
        $nextDir = $preferredDir[$sortKey];
        if ($query['sort'] === $sortKey) {
            $nextDir = $query['dir'] === 'asc' ? 'desc' : 'asc';
        }
        $aria = '';
        if ($query['sort'] === $sortKey) {
            $aria = ' aria-sort="' . ($query['dir'] === 'asc' ? 'ascending' : 'descending') . '"';
        }
        $numeric = $sortKey === 'name' || $sortKey === 'status' ? '' : ' class="num"';
        $head .= '<th' . $numeric . $aria . '><a href="'
            . $h(bakery_account_health_url($query, ['sort' => $sortKey, 'dir' => $nextDir]))
            . '">' . $h(bakery_t($labelKey)) . '</a></th>';
    }

    $body = '';
    if ($rows === []) {
        $body = '<tr><td colspan="9">' . $h(bakery_t('account_health.empty')) . '</td></tr>';
    }
    foreach ($rows as $row) {
        $status = (string)$row['status'];
        $badge = $status === 'lapsed' ? 'sf-badge sf-badge--danger' : ($status === 'shrinking' ? 'sf-badge sf-badge--warning' : 'sf-badge sf-badge--success');
        $hub = 'customer_record.php?customer_id=' . (int)$row['customer_id'];
        $zone = trim((string)($row['zone'] ?? ''));
        $share = $row['delivered_share_percent'] ?? null;
        $shareText = $share === null
            ? bakery_t('account_health.share_empty')
            : bakery_t('account_health.share_ratio', [
                'delivered' => (string)(int)$row['share_delivered_units'],
                'ordered' => (string)(int)$row['share_ordered_units'],
                'percent' => number_format((float)$share, 1),
            ]);
        $last = (string)($row['last_delivered_date'] ?? '');
        $lastText = $last === '' ? bakery_t('account_health.never') : $last;
        $balanceText = '$' . number_format((float)$row['balance'], 2);
        if ((int)$row['balance_oldest_days'] > 0 && (float)$row['balance'] > 0) {
            $balanceText .= ' · ' . bakery_t('account_health.balance_age', [
                'days' => (string)(int)$row['balance_oldest_days'],
            ]);
        }
        $cells = [
            'name' => '<a href="' . $h($hub) . '">' . $h((string)$row['customer_name']) . '</a>'
                . ($zone !== '' ? '<div style="color:var(--sf-text-muted);font-size:0.85rem;">' . $h($zone) . '</div>' : ''),
            'recent' => (string)(int)$row['recent_delivered_units'],
            'prior' => (string)(int)$row['prior_delivered_units'],
            'change' => $h(bakery_account_health_format_change($row)),
            'last' => $last === '' ? $h($lastText) : '<time datetime="' . $h($last) . '">' . $h($lastText) . '</time>',
            'stale' => (string)(int)$row['stale_pending_count'],
            'share' => $h($shareText),
            'balance' => $h($balanceText),
            'status' => '<span class="' . $badge . '">' . $h(bakery_account_health_status_label($status)) . '</span>',
        ];
        $body .= '<tr>';
        foreach ($columns as $sortKey => $labelKey) {
            $numeric = $sortKey === 'name' || $sortKey === 'status' ? '' : ' class="num"';
            $body .= '<td' . $numeric . ' data-label="' . $h(bakery_t($labelKey)) . '">' . $cells[$sortKey] . '</td>';
        }
        $body .= '</tr>';
    }

    return '<div class="sf-section">'
        . '<p>' . $h(bakery_t('account_health.subtitle')) . '</p>'
        . '<p style="color:var(--sf-text-muted);max-width:70ch;">' . $h($note) . '</p>'
        . '<p><strong>' . $h($summaryText) . '</strong></p>'
        . '<div class="action-bar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0 16px;">'
        . '<span class="sf-sr-only">' . $h(bakery_t('account_health.filter_label')) . '</span>'
        . $filters
        . '<a class="sf-btn sf-btn--sm sf-btn--secondary" href="' . $h($csvUrl) . '">' . $h(bakery_t('account_health.csv')) . '</a>'
        . '</div>'
        . '<div class="sf-table-wrap"><table class="sf-table sf-table--stack-sm">'
        . '<caption class="sf-sr-only">' . $h(bakery_t('account_health.title')) . '</caption>'
        . '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>'
        . '</div>';
}
