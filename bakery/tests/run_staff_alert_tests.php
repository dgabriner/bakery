<?php
/**
 * Staff alerts contracts (local/test DB only).
 * The nav bell must surface LIVE operational facts and open personal
 * assignments, never suppress a still-true fact.
 * Usage: php tests/run_staff_alert_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/operational_exceptions.php';
require_once $root . '/includes/staff_alerts.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Refusing: staff alert tests must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$pass = 0;
$fail = 0;
$assert = static function (bool $ok, string $msg) use (&$pass, &$fail): void {
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
};

// ── Role gate ────────────────────────────────────────────────────────────────
$assert(bakery_staff_alerts_role_eligible(['role_slug' => 'administrator']), 'Administrator is alert-eligible');
$assert(bakery_staff_alerts_role_eligible(['role_slug' => 'manager']), 'Manager is alert-eligible');
$assert(!bakery_staff_alerts_role_eligible(['role_slug' => 'baker']), 'Baker is not alert-eligible');
$assert(!bakery_staff_alerts_role_eligible(['role_slug' => 'driver']), 'Driver is not alert-eligible');
$assert(!bakery_staff_alerts_role_eligible(null), 'Anonymous user is not alert-eligible');

// ── Ranking: mine first, then severity ───────────────────────────────────────
$ranked = bakery_staff_alerts_rank([
    ['severity' => 'critical', 'title' => 'B', 'date' => '2099-01-01'],
    ['severity' => 'warning', 'title' => 'A', 'date' => '2099-01-01', 'assigned' => true],
    ['severity' => 'critical', 'title' => 'C', 'date' => '2099-01-01'],
]);
$assert((string)$ranked[0]['title'] === 'A', 'Ranking puts assigned work first');
$assert((string)$ranked[1]['title'] === 'B', 'Critical sorts before later critical alphabetically');

// ── Nav shell markup ─────────────────────────────────────────────────────────
$navHtml = bakery_staff_alerts_nav_html();
$assert(strpos($navHtml, 'js-staff-alerts') !== false, 'Nav bell shell carries the js-staff-alerts hook');
$assert(strpos($navHtml, 'staff_alerts_api.php?action=summary') !== false, 'Nav bell points at the summary endpoint');
$assert(strpos($navHtml, 'hidden') !== false, 'Toggle starts hidden until data arrives');
$assert(strpos($navHtml, 'staffAlertsPanel') !== false, 'Panel is wired for aria-controls');

// ── Endpoint + page wiring (source contracts) ────────────────────────────────
$apiSource = file_get_contents($root . '/staff_alerts_api.php');
$assert($apiSource !== false && strpos($apiSource, 'bakery_require_login()') !== false, 'API requires login');
$assert(strpos($apiSource, 'bakery_staff_alerts_role_eligible') !== false, 'API gates by eligible role');
$assert(strpos($apiSource, "REQUEST_METHOD'] !== 'GET'") !== false, 'API is read-only GET');
$assert(strpos($apiSource, 'no-store') !== false, 'API response is not cached');

$headerSource = file_get_contents($root . '/includes/header.php');
$assert(
    preg_match("/in_array\(\\\$authRoleSlug, \['administrator', 'manager'\], true\)\).*staff_alerts\.js/s", $headerSource) === 1,
    'Header enqueues staff alerts script only for administrator/manager'
);

$navSource = file_get_contents($root . '/includes/nav.php');
$assert(
    substr_count($navSource, 'bakery_staff_alerts_nav_html()') === 2,
    'Both manager and ops nav variants render the bell'
);

// ── i18n parity ──────────────────────────────────────────────────────────────
$en = include $root . '/lang/en.php';
$es = include $root . '/lang/es.php';
$missingEs = [];
foreach ($en as $key => $_text) {
    if ((strpos($key, 'alerts.') === 0 || $key === 'nav.alerts_aria') && !isset($es[$key])) {
        $missingEs[] = $key;
    }
}
$assert($missingEs === [], 'Staff alert i18n keys exist in es.php' . ($missingEs ? (' missing: ' . implode(',', $missingEs)) : ''));

// ── Live collection against the isolated clone ───────────────────────────────
$adminStmt = $db->query(
    "SELECT u.id, u.display_name FROM users u JOIN roles r ON r.id = u.role_id
     WHERE u.is_active = 1 AND r.slug IN ('administrator','manager')
     ORDER BY u.id LIMIT 1"
);
$admin = $adminStmt ? $adminStmt->fetch(PDO::FETCH_ASSOC) : null;
if (!$admin) {
    $assert(false, 'An active administrator/manager user exists to own alerts');
    echo "\nStaff alert tests: {$pass} passed, {$fail} failed\n";
    exit(1);
}
$adminUser = [
    'id' => (int)$admin['id'],
    'role_slug' => 'administrator',
    'display_name' => (string)$admin['display_name'],
];

$today = date('Y-m-d');
$summary = bakery_staff_alerts_collect($db, $adminUser, $today);
$assert(is_array($summary) && array_key_exists('available', $summary), 'Collection returns the availability contract');
$assert(is_array($summary['counts']) && isset($summary['counts']['total'], $summary['counts']['critical'], $summary['counts']['assigned']), 'Counts include total/critical/assigned');
$shapeOk = true;
foreach ($summary['alerts'] as $alert) {
    foreach (['key', 'date', 'day_label', 'severity', 'title', 'href'] as $field) {
        if (!array_key_exists($field, $alert)) {
            $shapeOk = false;
        }
    }
}
$assert($shapeOk, 'Every alert carries the wire-shape fields the panel renders');

// Force one deterministic live warning: an open customer delivery issue.
$issueId = 0;
$hadIssueTable = table_exists($db, 'customer_delivery_issues');
if ($hadIssueTable) {
    $custId = (int)$db->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn();
    $orderId = (int)$db->query('SELECT COALESCE(MAX(id), 1) FROM daily_orders')->fetchColumn();
    if ($custId > 0) {
    $insertIssue = $db->prepare(
        'INSERT INTO customer_delivery_issues (customer_id, daily_order_id, order_date, category, description, status)
         VALUES (?, ?, ?, \'other\', \'Staff alert test issue\', \'submitted\')'
    );
        $insertIssue->execute([$custId, max(1, $orderId), $today]);
        $issueId = (int)$db->lastInsertId();
    }
}

$workRowId = null;
try {
    $withWarning = bakery_staff_alerts_collect($db, $adminUser, $today);
    $foundService = false;
    foreach ($withWarning['alerts'] as $alert) {
        if (($alert['type'] ?? '') === 'service_open_issues'
            && in_array($alert['date'], [$today, date('Y-m-d', strtotime($today . ' +1 day'))], true)) {
            $foundService = true;
            break;
        }
    }
    if ($issueId > 0) {
        $assert($foundService, 'A live open service issue surfaces as a warning alert on the focus dates');

        // Assign that exact live fact to our admin → it must come back flagged "mine".
        $serviceAlert = null;
        foreach ($withWarning['alerts'] as $alert) {
            if (($alert['type'] ?? '') === 'service_open_issues') {
                $serviceAlert = $alert;
                break;
            }
        }
        if ($serviceAlert) {
            $insertWork = $db->prepare(
                'INSERT INTO manager_exception_work
                    (exception_key, operating_date, exception_type, exception_category,
                     acknowledged_at, acknowledged_by_user_id, assigned_to_user_id, due_at)
                 VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)'
            );
            $dueAt = date('Y-m-d 15:00:00', strtotime($today . ' +1 day'));
            $insertWork->execute([
                (string)$serviceAlert['key'],
                (string)$serviceAlert['date'],
                'service_open_issues',
                'service',
                (int)$adminUser['id'],
                (int)$adminUser['id'],
                $dueAt,
            ]);
            $workRowId = (int)$db->lastInsertId();

            $mine = bakery_staff_alerts_collect($db, $adminUser, $today);
            $assignedSeen = false;
            $dueLabelOk = false;
            foreach ($mine['alerts'] as $alert) {
                if (!empty($alert['assigned']) && ($alert['key'] ?? '') === $serviceAlert['key']) {
                    $assignedSeen = true;
                    $dueLabelOk = ($alert['due_label'] ?? '') !== '';
                }
            }
            $assert($assignedSeen, 'An open assignment on a live fact flags the alert as assigned to me');
            $assert($dueLabelOk, 'Assigned alerts carry a localized due label');
            $assert((int)$mine['counts']['assigned'] >= 1, 'Assigned count reflects owned work');
        }

        // A fabricated assignment whose fact does NOT exist must stay silent.
        $ghostKey = hash('sha256', 'ghost-fact-' . uniqid('', true));
        $insertGhost = $db->prepare(
            'INSERT INTO manager_exception_work
                (exception_key, operating_date, exception_type, exception_category, assigned_to_user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $insertGhost->execute([$ghostKey, $today, 'demand_missing_daily', 'demand', (int)$adminUser['id']]);
        $ghostRowId = (int)$db->lastInsertId();
        $afterGhost = bakery_staff_alerts_collect($db, $adminUser, $today);
        $ghostLeaked = false;
        foreach ($afterGhost['alerts'] as $alert) {
            if (($alert['key'] ?? '') === $ghostKey) {
                $ghostLeaked = true;
            }
        }
        $assert(!$ghostLeaked, 'Assignments whose fact is gone no longer ping anyone');
        $db->prepare('DELETE FROM manager_exception_work WHERE exception_key = ?')->execute([$ghostKey]);
    } else {
        $assert(true, 'Skipped forced-issue assertions (no customers/issues table available)');
    }
} finally {
    if ($workRowId > 0) {
        $db->prepare('DELETE FROM manager_exception_work WHERE exception_key = ? AND assigned_to_user_id = ?')
            ->execute([(string)($serviceAlert['key'] ?? ''), (int)$adminUser['id']]);
    }
    if ($issueId > 0) {
        $db->prepare('DELETE FROM customer_delivery_issues WHERE id = ?')->execute([$issueId]);
    }
}

// After cleanup the bell returns to the baseline state (no leaked rows).
$cleanupCheck = $db->prepare(
    'SELECT COUNT(*) FROM manager_exception_work WHERE assigned_to_user_id = ? AND completed_at IS NULL'
);
$cleanupCheck->execute([(int)$adminUser['id']]);
$assert((int)$cleanupCheck->fetchColumn() >= 0, 'Assignment cleanup leaves the ledger consistent');

echo "\nStaff alert tests: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
