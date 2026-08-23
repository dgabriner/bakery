<?php
/**
 * Staff alerts — the "what needs my attention" loop for the people who own it.
 *
 * Exceptions are already recomputed live everywhere (dashboard, Daily Run,
 * Manager Mode) and ownership lives in manager_exception_work, but nobody is
 * pinged. This helper merges both sources into one alert list for the signed-in
 * staff member:
 *
 *   live operational facts (today + tomorrow) that are critical/warning
 *   + open exception work assigned to this user (any recent date)
 *
 * Invariants preserved:
 *   - Alerts derive from LIVE exceptions. Completing exception work never
 *     suppresses an alert whose underlying fact is still true.
 *   - An assignment whose fact is gone no longer pings anyone.
 *   - Runtime-tolerant: missing tables degrade to available=false / empty list.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/operational_exceptions.php';

if (!function_exists('bakery_manager_exception_key')) {
    require_once __DIR__ . '/manager_mode.php';
}

/** Roles whose work the alert bell represents (exception owners). */
function bakery_staff_alerts_role_eligible(?array $user): bool
{
    if (!$user) {
        return false;
    }
    return in_array((string)($user['role_slug'] ?? ''), ['administrator', 'manager'], true);
}

/**
 * Pure ranking so the bell reads top-down: mine first, then loudest.
 * Assigned items sort by due date; situational by severity, then date.
 *
 * @param list<array<string, mixed>> $alerts
 * @return list<array<string, mixed>>
 */
function bakery_staff_alerts_rank(array $alerts): array
{
    $severityRank = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($alerts, static function (array $a, array $b) use ($severityRank): int {
        if (!empty($a['assigned']) !== !empty($b['assigned'])) {
            return !empty($a['assigned']) ? -1 : 1;
        }
        $ra = $severityRank[(string)($a['severity'] ?? '')] ?? 9;
        $rb = $severityRank[(string)($b['severity'] ?? '')] ?? 9;
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $dueA = (string)($a['due_at'] ?? '');
        $dueB = (string)($b['due_at'] ?? '');
        if (($dueA !== '') !== ($dueB !== '')) {
            return $dueA !== '' ? -1 : 1;
        }
        if ($dueA !== '' && $dueA !== $dueB) {
            return strcmp($dueA, $dueB);
        }
        $dateCmp = strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
        if ($dateCmp !== 0) {
            return $dateCmp;
        }
        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    return $alerts;
}

/**
 * Server-rendered shell for the nav bell. The badge and panel fill in from
 * staff_alerts_api.php via includes/staff_alerts.js; without JS or when the
 * endpoint is unavailable the button stays hidden.
 */
function bakery_staff_alerts_nav_html(): string
{
    if (!defined('BASE_URL')) {
        return '';
    }
    $t = static function (string $key, string $fallback): string {
        return function_exists('bakery_t') ? (string)bakery_t($key) : $fallback;
    };
    $endpoint = htmlspecialchars(BASE_URL . 'staff_alerts_api.php?action=summary', ENT_QUOTES, 'UTF-8');
    $aria = htmlspecialchars($t('nav.alerts_aria', 'Needs attention'), ENT_QUOTES, 'UTF-8');
    $panelTitle = htmlspecialchars($t('alerts.panel_title', 'Needs attention'), ENT_QUOTES, 'UTF-8');
    $viewAll = htmlspecialchars($t('alerts.view_all', 'Open Operations Dashboard'), ENT_QUOTES, 'UTF-8');

    return '<div class="bakery-nav__alerts js-staff-alerts" data-endpoint="' . $endpoint . '">'
        . '<button type="button" class="bakery-nav__alerts-toggle" aria-expanded="false"'
        . ' aria-controls="staffAlertsPanel" hidden>'
        . '<svg class="bakery-nav__alerts-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false">'
        . '<path fill="currentColor" d="M12 22a2.4 2.4 0 0 0 2.4-2.4H9.6A2.4 2.4 0 0 0 12 22Zm7-5.2v-1l-1.6-1.6v-4A5.6 5.6 0 0 0 13.6 4.7V3.9a1.6 1.6 0 1 0-3.2 0v.8A5.6 5.6 0 0 0 6.6 10.2v4L5 15.8v1Z"/>'
        . '</svg>'
        . '<span class="bakery-nav__alerts-badge" hidden></span>'
        . '<span class="sf-sr-only">' . $aria . '</span>'
        . '</button>'
        . '<div class="bakery-nav__alerts-panel" id="staffAlertsPanel" hidden>'
        . '<p class="bakery-nav__alerts-title">' . $panelTitle . '</p>'
        . '<ul class="bakery-nav__alerts-list"></ul>'
        . '<a class="bakery-nav__alerts-footer" href="' . htmlspecialchars(BASE_URL . 'index.php', ENT_QUOTES, 'UTF-8') . '">' . $viewAll . '</a>'
        . '</div>'
        . '</div>';
}

/**
 * Collect alerts for a staff user across today + tomorrow plus any open
 * assignment dates. See file header for the contract.
 *
 * @param array<string, mixed>|null $user bakery_current_user() shape
 * @return array{
 *   available:bool,
 *   today:string,
 *   counts:array{total:int,critical:int,warning:int,assigned:int},
 *   alerts:list<array<string,mixed>>
 * }
 */
function bakery_staff_alerts_collect(PDO $db, ?array $user, ?string $today = null): array
{
    $today = $today ?: date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));
    $result = [
        'available' => false,
        'today' => $today,
        'counts' => ['total' => 0, 'critical' => 0, 'warning' => 0, 'assigned' => 0],
        'alerts' => [],
    ];
    if (!bakery_staff_alerts_role_eligible($user)) {
        return $result;
    }

    // 1. Dates to look at: the two operating days that matter most, plus any
    //    dates where this user still owns open work (recent past included).
    $dates = [$today, $tomorrow];
    $assignmentsByDate = [];
    try {
        $assignmentsByDate = bakery_staff_alerts_open_assignments($db, (int)$user['id'], $today);
        foreach (array_keys($assignmentsByDate) as $assignedDate) {
            if (!in_array($assignedDate, $dates, true)) {
                $dates[] = $assignedDate;
            }
        }
    } catch (Throwable $e) {
        error_log('staff alerts assignments: ' . $e->getMessage());
    }

    // 2. Live exceptions per date (computed once, shared).
    $liveByDate = [];
    foreach ($dates as $date) {
        try {
            $exceptions = bakery_ops_exceptions_for_date($db, $date, null);
        } catch (Throwable $e) {
            error_log('staff alerts exceptions ' . $date . ': ' . $e->getMessage());
            continue;
        }
        $byKey = [];
        foreach ($exceptions as $exception) {
            if (!is_array($exception)) {
                continue;
            }
            $byKey[bakery_manager_exception_key($exception, $date)] = $exception;
        }
        $liveByDate[$date] = ['exceptions' => $exceptions, 'by_key' => $byKey];
    }

    // 3. Situational alerts: critical/warning facts on the two focus dates.
    $alerts = [];
    $seen = [];
    foreach ([$today, $tomorrow] as $focusDate) {
        if (!isset($liveByDate[$focusDate])) {
            continue;
        }
        foreach ($liveByDate[$focusDate]['exceptions'] as $exception) {
            $severity = (string)($exception['severity'] ?? '');
            if (!in_array($severity, ['critical', 'warning'], true)) {
                continue;
            }
            $key = bakery_manager_exception_key($exception, $focusDate);
            $seen[$key] = true;
            $alerts[] = bakery_staff_alerts_build_alert($exception, $focusDate, $today);
        }
    }

    // 4. Personal alerts: open work assigned to me whose fact is STILL live.
    //    Includes info-severity facts I own; drops assignments whose fact is gone.
    foreach ($assignmentsByDate as $assignedDate => $rows) {
        if (!isset($liveByDate[$assignedDate])) {
            continue;
        }
        foreach ($rows as $row) {
            $key = (string)$row['exception_key'];
            if (!isset($liveByDate[$assignedDate]['by_key'][$key])) {
                continue; // fact resolved — nobody should keep being pinged
            }
            if (isset($seen[$key])) {
                // Already listed; upgrade it to "mine".
                foreach ($alerts as &$alert) {
                    if ((string)($alert['key'] ?? '') === $key && (string)($alert['date'] ?? '') === (string)$assignedDate) {
                        $alert['assigned'] = true;
                        $alert['acknowledged'] = !empty($row['acknowledged_at']);
                        $alert['due_at'] = $row['due_at'] ? (string)$row['due_at'] : '';
                        $alert['due_label'] = bakery_staff_alerts_due_label($row['due_at']);
                    }
                }
                unset($alert);
                continue;
            }
            $seen[$key] = true;
            $alert = bakery_staff_alerts_build_alert(
                $liveByDate[$assignedDate]['by_key'][$key],
                (string)$assignedDate,
                $today
            );
            $alert['assigned'] = true;
            $alert['acknowledged'] = !empty($row['acknowledged_at']);
            $alert['due_at'] = $row['due_at'] ? (string)$row['due_at'] : '';
            $alert['due_label'] = bakery_staff_alerts_due_label($row['due_at']);
            $alerts[] = $alert;
        }
    }

    $result['available'] = $liveByDate !== [];
    $alerts = bakery_staff_alerts_rank($alerts);

    $counts = ['total' => count($alerts), 'critical' => 0, 'warning' => 0, 'assigned' => 0];
    foreach ($alerts as $alert) {
        if (($alert['severity'] ?? '') === 'critical') {
            $counts['critical']++;
        }
        if (($alert['severity'] ?? '') === 'warning') {
            $counts['warning']++;
        }
        if (!empty($alert['assigned'])) {
            $counts['assigned']++;
        }
    }
    $result['counts'] = $counts;

    $maxAlerts = 12;
    if (count($alerts) > $maxAlerts) {
        $overflow = [
            'key' => 'overflow',
            'date' => '',
            'severity' => 'info',
            'title' => '+' . (count($alerts) - $maxAlerts),
            'detail' => function_exists('bakery_t')
                ? (string)bakery_t('alerts.overflow_detail')
                : 'more situations on the dashboard',
            'href' => (defined('BASE_URL') ? BASE_URL : '') . 'index.php',
            'assigned' => false,
            'acknowledged' => false,
            'due_at' => '',
        ];
        $alerts = array_slice($alerts, 0, $maxAlerts);
        $alerts[] = $overflow;
    }

    $result['alerts'] = $alerts;
    return $result;
}

/**
 * Open exception work assigned to one user, grouped by operating_date.
 * Recent past stays included so yesterday's handoff can still ping.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function bakery_staff_alerts_open_assignments(PDO $db, int $userId, string $today): array
{
    if ($userId <= 0 || !table_exists($db, 'manager_exception_work')) {
        return [];
    }
    $from = date('Y-m-d', strtotime($today . ' -2 days'));
    $to = date('Y-m-d', strtotime($today . ' +7 days'));
    $stmt = $db->prepare(
        "SELECT exception_key, operating_date, acknowledged_at, due_at
         FROM manager_exception_work
         WHERE assigned_to_user_id = ? AND completed_at IS NULL
           AND operating_date BETWEEN ? AND ?
         ORDER BY operating_date, due_at"
    );
    $stmt->execute([$userId, $from, $to]);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $grouped[(string)$row['operating_date']][] = $row;
    }
    return $grouped;
}

/**
 * Normalize one exception into the wire shape the bell panel renders.
 *
 * @param array<string, mixed> $exception
 * @return array<string, mixed>
 */
function bakery_staff_alerts_build_alert(array $exception, string $date, string $today): array
{
    return [
        'key' => bakery_manager_exception_key($exception, $date),
        'type' => (string)($exception['type'] ?? ''),
        'date' => $date,
        'day_label' => bakery_staff_alerts_day_label($date, $today),
        'severity' => in_array((string)($exception['severity'] ?? ''), ['critical', 'warning', 'info'], true)
            ? (string)$exception['severity']
            : 'warning',
        'title' => (string)($exception['title'] ?? ''),
        'detail' => (string)($exception['detail'] ?? ''),
        'count' => $exception['count'] ?? null,
        'href' => (string)($exception['href'] ?? ''),
        'stage' => (string)($exception['stage'] ?? ''),
        'assigned' => false,
        'acknowledged' => false,
        'due_at' => '',
        'due_label' => '',
    ];
}

/** Server-side localized "due" phrase so the client never formats strings. */
function bakery_staff_alerts_due_label($dueAt): string
{
    $dueAt = trim((string)$dueAt);
    if ($dueAt === '' || !function_exists('bakery_t')) {
        return '';
    }
    $when = function_exists('format_date') ? format_date($dueAt, 'M j g:i A') : $dueAt;
    return (string)bakery_t('alerts.due', ['date' => $when]);
}

function bakery_staff_alerts_day_label(string $date, string $today): string
{
    if ($date === $today) {
        return function_exists('bakery_t') ? (string)bakery_t('common.today') : 'Today';
    }
    if ($date === date('Y-m-d', strtotime($today . ' +1 day'))) {
        return function_exists('bakery_t') ? (string)bakery_t('alerts.tomorrow') : 'Tomorrow';
    }
    return function_exists('format_date') ? format_date($date, 'D, M j') : $date;
}
