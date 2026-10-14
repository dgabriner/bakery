<?php
/**
 * Actor identity, stale driver context, failed logins, admin sign-in flags,
 * quiet accounts, full CSV export, and the driver shift session floor.
 *
 * Usage: php bakery/tests/run_actor_audit_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
bakery_reset_isolated_test_db($root);

$seedOut = [];
exec('"' . PHP_BINARY . '" ' . escapeshellarg($root . '/scripts/seed_local_users.php'), $seedOut, $seedCode);
if ($seedCode !== 0) {
    fwrite(STDERR, "seed_local_users failed\n" . implode("\n", $seedOut) . "\n");
    exit(1);
}

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/operational_timeline.php';
require_once $root . '/includes/user_activity.php';
require_once $root . '/includes/admin_signin_alerts.php';
require_once $root . '/includes/login_history_insights.php';

if (!IS_LOCAL) {
    fwrite(STDERR, "Must run with APP_ENV=local\n");
    exit(1);
}

$db = check_mysql_connection();
bakery_assert_local_test_target($db);
$pass = 0;
$fail = 0;

function actor_assert($ok, $msg) {
    global $pass, $fail;
    if ($ok) {
        echo "PASS  $msg\n";
        $pass++;
    } else {
        echo "FAIL  $msg\n";
        $fail++;
    }
}

function actor_reset_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (headers_sent()) {
        $_SESSION = [];
        return;
    }
    session_id('actor-audit-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION = [];
}

function actor_user_id(PDO $db, string $code) {
    $stmt = $db->prepare('SELECT id FROM users WHERE login_code = ? LIMIT 1');
    $stmt->execute([$code]);
    return (int)$stmt->fetchColumn();
}

function actor_last_event(PDO $db) {
    $stmt = $db->query(
        'SELECT id, event_type, actor_user_id, actor_role, actor_type, driver_id, summary, metadata
         FROM operational_events ORDER BY id DESC LIMIT 1'
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

actor_reset_session();
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_USER_AGENT'] = 'ActorAudit/1.0';
$_SERVER['SCRIPT_NAME'] = '/bakery/users.php';
$_GET = [];
$_POST = [];

echo "=== Schema numbers ===\n";
actor_assert(is_file($root . '/database/schema/100_actor_identity.sql'), 'migration 100 exists');
actor_assert(is_file($root . '/database/schema/101_admin_signin_alerts.sql'), 'migration 101 exists');
actor_assert(!is_file($root . '/database/schema/093_actor_identity.sql'), 'migration 093 was not used');
actor_assert(column_exists($db, 'users', 'is_bot'), 'users.is_bot column exists');
actor_assert(column_exists($db, 'operational_events', 'actor_type'), 'operational_events.actor_type exists');
actor_assert(column_exists($db, 'login_audit', 'actor_type'), 'login_audit.actor_type exists');
actor_assert(table_exists($db, 'admin_signin_alerts'), 'admin_signin_alerts table exists');

echo "=== Bot actor ===\n";
actor_assert(bakery_upsert_code_user($db, [
    'email' => 'newbot@local.test',
    'display_name' => 'New Bot',
    'role' => 'manager',
    'code' => '9188',
    'driver_id' => null,
]) === true, 'New Bot user can be saved');
$botId = actor_user_id($db, '9188');
$botFlag = $db->prepare('SELECT is_bot FROM users WHERE id = ?');
$botFlag->execute([$botId]);
actor_assert((int)$botFlag->fetchColumn() === 1, 'New Bot is marked is_bot');

actor_assert(bakery_login($db, '9188') === true, 'New Bot can sign in');
$recorded = bakery_record_operational_event($db, 'legacy_actor_audit', 'Bot recorded a change');
actor_assert($recorded > 0, 'bot action was recorded');
$botEvent = actor_last_event($db);
actor_assert(($botEvent['actor_type'] ?? '') === 'bot', 'timeline row actor_type is bot');
actor_assert((int)($botEvent['driver_id'] ?? 0) === 0, 'bot action on users.php has no driver');

$db->prepare('UPDATE users SET is_bot = 0, display_name = ? WHERE id = ?')->execute(['Cursor Agent', $botId]);
$_SESSION['user_display_name'] = 'Cursor Agent';
bakery_record_operational_event($db, 'legacy_actor_audit', 'Cursor Agent recorded a change');
actor_assert((actor_last_event($db)['actor_type'] ?? '') === 'bot', 'Cursor Agent display name is still a bot');

bakery_logout();
actor_reset_session();
$_SERVER['SCRIPT_NAME'] = '/bakery/users.php';
$_COOKIE['bakery_selected_driver_id'] = '2';
$_SESSION['selected_driver_id'] = 2;
bakery_record_operational_event($db, 'legacy_actor_audit', 'Nightly close wrote this');
$systemEvent = actor_last_event($db);
actor_assert(($systemEvent['actor_type'] ?? '') === 'system', 'action with no user is system');
actor_assert((int)($systemEvent['driver_id'] ?? 0) === 0, 'system action ignores a leftover driver cookie');

bakery_record_operational_event($db, 'portal_pause_created', 'Customer paused a day', ['actor_role' => 'customer']);
actor_assert((actor_last_event($db)['actor_type'] ?? '') === 'customer', 'portal action with no staff user is customer');

echo "=== Stale driver context ===\n";
actor_reset_session();
actor_assert(bakery_login($db, '9002') === true, 'manager signs in');
$_SERVER['SCRIPT_NAME'] = '/bakery/users.php';
$_GET['driver_id'] = '2';
$_POST['driver_id'] = '2';
$_SESSION['selected_driver_id'] = 2;
$_COOKIE['bakery_selected_driver_id'] = '2';
bakery_record_operational_event($db, 'legacy_actor_audit', 'Manager edited a user');
$managerEvent = actor_last_event($db);
actor_assert(($managerEvent['actor_type'] ?? '') === 'human', 'manager action is human');
actor_assert((int)($managerEvent['driver_id'] ?? 0) === 0, 'manager action does not keep a leftover driver');

$_SERVER['SCRIPT_NAME'] = '/bakery/driver.php';
$_GET['driver_id'] = '2';
bakery_record_operational_event($db, 'legacy_actor_audit', 'Manager opened a route');
actor_assert((int)(actor_last_event($db)['driver_id'] ?? 0) === 2, 'route screen request driver is attached');

bakery_record_operational_event($db, 'legacy_actor_audit', 'Explicit driver wins', ['driver_id' => 1]);
actor_assert((int)(actor_last_event($db)['driver_id'] ?? 0) === 1, 'an explicit driver id is kept');

bakery_logout();
actor_reset_session();
actor_assert(bakery_login($db, '9003') === true, 'driver signs in');
$_SERVER['SCRIPT_NAME'] = '/bakery/users.php';
$_GET['driver_id'] = '2';
$_POST['driver_id'] = '2';
$_SESSION['selected_driver_id'] = 2;
bakery_record_operational_event($db, 'legacy_actor_audit', 'Driver saved something off the route');
actor_assert((int)(actor_last_event($db)['driver_id'] ?? 0) === 1, 'a driver keeps their own route id off a route screen');

echo "=== Failed logins ===\n";
bakery_logout();
actor_reset_session();
$_SERVER['REMOTE_ADDR'] = '203.0.113.44';
$_SERVER['HTTP_USER_AGENT'] = 'FailedLogin/9';
$_SERVER['SCRIPT_NAME'] = '/bakery/login.php';
bakery_login_audit_record_failure($db, 'staff', 'Staff login', '0000');
$unknown = $db->query(
    "SELECT principal, user_id, ip_address, user_agent, actor_type, failure_reason
     FROM login_audit WHERE outcome = 'failure' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
actor_assert(($unknown['principal'] ?? '') === 'unknown', 'unknown code is recorded as unknown');
actor_assert(empty($unknown['user_id']), 'unknown code has no user id');
actor_assert(($unknown['ip_address'] ?? '') === '203.0.113.44', 'failed login stores the IP');
actor_assert(($unknown['user_agent'] ?? '') === 'FailedLogin/9', 'failed login stores the user agent');
actor_assert(($unknown['failure_reason'] ?? '') === 'Invalid credentials', 'first failure is invalid credentials');

bakery_login_audit_record_failure($db, 'staff', 'Staff login', '9002');
$knownFail = $db->query(
    "SELECT principal, user_id FROM login_audit WHERE outcome = 'failure' ORDER BY id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
actor_assert(($knownFail['principal'] ?? '') === 'Local Manager', 'a real code records that user');
actor_assert((int)($knownFail['user_id'] ?? 0) === actor_user_id($db, '9002'), 'failed login stores the matched user id');

$roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1")->fetchColumn();
$db->prepare(
    'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active)
     VALUES (?, ?, ?, ?, ?, 0)'
)->execute(['quiet-code@local.test', password_hash('9191', PASSWORD_DEFAULT), '9191', 'Inactive Code', $roleId]);
bakery_login_audit_record_failure($db, 'staff', 'Staff login', '9191');
$inactiveFail = $db->query("SELECT principal FROM login_audit WHERE outcome = 'failure' ORDER BY id DESC LIMIT 1")->fetchColumn();
actor_assert($inactiveFail === 'Inactive Code', 'an inactive matching code is still named');

$signup = bakery_login_failure_subject($db, 'customer', '0000', 'Customer portal signup');
actor_assert($signup['principal'] === 'Customer portal signup', 'signup failure keeps its principal label');

$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
$_SERVER['HTTP_USER_AGENT'] = 'Lockout/1';
for ($i = 0; $i < 3; $i++) {
    bakery_login_audit_record_failure($db, 'staff', 'Staff login', '0000');
}
$warning = bakery_login_failure_pressure($db, 'staff');
actor_assert((int)$warning['failures'] >= 3 && empty($warning['locked']), 'three failures warn before lockout');
actor_assert(
    strpos(bakery_t('login.lockout_warning', [
        'failures' => (int)$warning['failures'],
        'limit' => (int)$warning['limit'],
        'minutes' => (int)$warning['window_minutes'],
    ]), (string)$warning['limit']) !== false,
    'lockout warning names the limit'
);
for ($n = 0; $n < 8 && (int)bakery_login_failure_pressure($db, 'staff')['failures'] < bakery_login_failure_limit(); $n++) {
    bakery_login_audit_record_failure($db, 'staff', 'Staff login', '0000');
}
actor_assert(!empty(bakery_login_failure_pressure($db, 'staff')['locked']), 'repeated failures from one IP lock');
bakery_login_audit_record_failure($db, 'staff', 'Staff login', '9001');
$limited = $db->query("SELECT failure_reason FROM login_audit WHERE outcome = 'failure' ORDER BY id DESC LIMIT 1")->fetchColumn();
actor_assert($limited === 'rate_limited', 'a failure during lockout is marked rate_limited');
actor_assert(bakery_login($db, '9001') === false, 'a valid code is refused while that IP is locked');

echo "=== Admin new device flag ===\n";
$_SERVER['REMOTE_ADDR'] = '203.0.113.80';
$_SERVER['HTTP_USER_AGENT'] = 'AdminPhone/1';
actor_reset_session();
$adminId = actor_user_id($db, '9001');
actor_assert(bakery_login($db, '9001') === true, 'administrator signs in from a new address');
$open = bakery_admin_signin_open_alerts($db);
$adminOpen = array_values(array_filter($open, static function ($row) use ($adminId) {
    return (int)$row['user_id'] === $adminId && ($row['ip_address'] ?? '') === '203.0.113.80';
}));
actor_assert(count($adminOpen) === 1, 'new administrator IP and device is flagged');
actor_assert(bakery_admin_signin_dismiss($db, (int)$adminOpen[0]['id'], $adminId) === true, 'administrator can dismiss the flag');
$stillOpen = array_filter(bakery_admin_signin_open_alerts($db), static function ($row) use ($adminId) {
    return (int)$row['user_id'] === $adminId && ($row['ip_address'] ?? '') === '203.0.113.80';
});
actor_assert($stillOpen === [], 'dismissed flag leaves the dashboard');
$dismissEvent = $db->query(
    "SELECT event_type FROM operational_events WHERE event_type = 'admin_signin_alert_dismissed' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
actor_assert($dismissEvent === 'admin_signin_alert_dismissed', 'dismiss is audited');

bakery_logout();
actor_reset_session();
actor_assert(bakery_login($db, '9001') === true, 'same address and device signs in again');
$repeat = array_filter(bakery_admin_signin_open_alerts($db), static function ($row) use ($adminId) {
    return (int)$row['user_id'] === $adminId && ($row['ip_address'] ?? '') === '203.0.113.80';
});
actor_assert($repeat === [], 'a seen address and device does not flag again');

$_SERVER['HTTP_USER_AGENT'] = 'AdminLaptop/2';
bakery_logout();
actor_reset_session();
actor_assert(bakery_login($db, '9001') === true, 'same address on a new device signs in');
$newDevice = array_filter(bakery_admin_signin_open_alerts($db), static function ($row) use ($adminId) {
    return (int)$row['user_id'] === $adminId && ($row['user_agent'] ?? '') === 'AdminLaptop/2';
});
actor_assert(count($newDevice) === 1, 'a new device is flagged');

$seenIp = '203.0.113.90';
$db->prepare(
    "INSERT INTO login_audit (auth_type, user_id, principal, outcome, login_at, ip_address, user_agent)
     VALUES ('staff', ?, 'admin@local.test', 'success', DATE_SUB(NOW(), INTERVAL 10 DAY), ?, 'SeenDevice/1')"
)->execute([$adminId, $seenIp]);
$_SERVER['REMOTE_ADDR'] = $seenIp;
$_SERVER['HTTP_USER_AGENT'] = 'SeenDevice/1';
bakery_logout();
actor_reset_session();
$beforeSeen = count(bakery_admin_signin_open_alerts($db));
actor_assert(bakery_login($db, '9001') === true, 'administrator returns on a device seen within 30 days');
actor_assert(count(bakery_admin_signin_open_alerts($db)) === $beforeSeen, 'a device seen within 30 days is not flagged');

bakery_logout();
actor_reset_session();
$_SERVER['REMOTE_ADDR'] = '203.0.113.91';
$_SERVER['HTTP_USER_AGENT'] = 'ManagerPhone/1';
actor_assert(bakery_login($db, '9002') === true, 'manager signs in from a new address');
$managerId = actor_user_id($db, '9002');
$managerAlerts = array_filter(bakery_admin_signin_open_alerts($db), static function ($row) use ($managerId) {
    return (int)$row['user_id'] === $managerId;
});
actor_assert($managerAlerts === [], 'a manager sign-in is not an administrator flag');

echo "=== Quiet accounts ===\n";
$insertUser = $db->prepare(
    'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active, created_at, last_login_at)
     VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
);
$hash = password_hash('9170', PASSWORD_DEFAULT);
$daysAgo = static function (int $days) {
    return date('Y-m-d H:i:s', time() - ($days * 86400));
};
$insertUser->execute(['stale61@local.test', $hash, '9170', 'Stale Sixty', $roleId, $daysAgo(61), null]);
$staleId = (int)$db->lastInsertId();
$insertUser->execute(['recent59@local.test', $hash, '9171', 'Recent Fifty', $roleId, $daysAgo(90), $daysAgo(59)]);
$recentId = (int)$db->lastInsertId();
$insertUser->execute(['event10@local.test', $hash, '9172', 'Event Ten', $roleId, $daysAgo(90), null]);
$eventUserId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO operational_events (event_type, occurred_at, actor_user_id, actor_role, summary)
     VALUES (?, ?, ?, ?, ?)'
)->execute(['legacy_actor_audit', $daysAgo(10), $eventUserId, 'manager', 'Recent recorded action']);
$insertUser->execute(['failonly@local.test', $hash, '9173', 'Fail Only', $roleId, $daysAgo(90), null]);
$failOnlyId = (int)$db->lastInsertId();
$db->prepare(
    "INSERT INTO login_audit (auth_type, user_id, principal, outcome, login_at, ip_address)
     VALUES ('staff', ?, 'Fail Only', 'failure', NOW(), '203.0.113.12')"
)->execute([$failOnlyId]);

$inactiveIds = array_map(static function ($row) {
    return (int)$row['id'];
}, bakery_users_inactive_accounts($db));
actor_assert(in_array($staleId, $inactiveIds, true), '61 days with no activity is listed');
actor_assert(in_array($failOnlyId, $inactiveIds, true), 'a failed login does not count as activity');
actor_assert(!in_array($recentId, $inactiveIds, true), 'a sign-in 59 days ago is not listed');
actor_assert(!in_array($eventUserId, $inactiveIds, true), 'a recorded action 10 days ago is not listed');
actor_assert((int)$db->query('SELECT is_active FROM users WHERE id = ' . $staleId)->fetchColumn() === 1, 'listing does not deactivate');

$_SESSION['user_id'] = $staleId;
$_SESSION['user_role_slug'] = 'manager';
$self = bakery_users_deactivate_account($db, $staleId, 'inactive_60_days');
actor_assert(empty($self['ok']), 'an account cannot deactivate itself');
actor_assert((int)$db->query('SELECT is_active FROM users WHERE id = ' . $staleId)->fetchColumn() === 1, 'self-deactivate leaves the account active');

$_SESSION['user_id'] = $adminId;
$_SESSION['user_role_slug'] = 'administrator';
$_SESSION['user_display_name'] = 'Local Admin';
$done = bakery_users_deactivate_account($db, $staleId, 'inactive_60_days');
actor_assert(!empty($done['ok']), 'administrator can deactivate a quiet account');
actor_assert((int)$db->query('SELECT is_active FROM users WHERE id = ' . $staleId)->fetchColumn() === 0, 'quiet account is inactive after the click');
$auditMeta = $db->query(
    "SELECT metadata FROM operational_events WHERE event_type = 'user_deactivated' ORDER BY id DESC LIMIT 1"
)->fetchColumn();
actor_assert(is_string($auditMeta) && strpos($auditMeta, 'inactive_60_days') !== false, 'deactivation is audited with the quiet-account reason');

echo "=== CSV export ===\n";
$eventInsert = $db->prepare(
    'INSERT INTO operational_events (event_type, operational_date, occurred_at, actor_user_id, actor_role, summary)
     VALUES (?, ?, ?, ?, ?, ?)'
);
for ($i = 0; $i < 160; $i++) {
    $eventInsert->execute(['actor_audit_csv', '2035-06-01', '2035-06-01 08:00:00', $adminId, 'administrator', 'csv row ' . $i]);
}
for ($i = 0; $i < 10; $i++) {
    $eventInsert->execute(['actor_audit_csv', '2035-07-01', '2035-07-01 08:00:00', $managerId, 'manager', 'other csv ' . $i]);
}
$view = bakery_operational_timeline_fetch($db, [
    'operational_date' => '2035-06-01',
    'actor_user_id' => $adminId,
    'limit' => bakery_operational_timeline_view_limit(),
]);
$viewCount = 0;
foreach ($view as $row) {
    if (($row['event_type'] ?? '') === 'actor_audit_csv') {
        $viewCount++;
    }
}
actor_assert(bakery_operational_timeline_view_limit() === 150, 'timeline view cap is 150');
actor_assert($viewCount === 150, 'timeline view stops at 150 rows');
$exported = bakery_operational_timeline_export_rows($db, [
    'since' => '2035-06-01 00:00:00',
    'until' => '2035-06-01 23:59:59',
    'actor_user_id' => $adminId,
]);
$exportCount = 0;
foreach ($exported as $row) {
    if (($row['event_type'] ?? '') === 'actor_audit_csv') {
        $exportCount++;
    }
}
actor_assert($exportCount === 160, 'timeline CSV includes every row in the date range for that user');
$other = bakery_operational_timeline_export_rows($db, [
    'since' => '2035-07-01 00:00:00',
    'until' => '2035-07-01 23:59:59',
    'actor_user_id' => $managerId,
]);
$otherCount = 0;
foreach ($other as $row) {
    if (($row['event_type'] ?? '') === 'actor_audit_csv') {
        $otherCount++;
    }
}
actor_assert($otherCount === 10, 'timeline CSV follows the user filter');

$loginInsert = $db->prepare(
    "INSERT INTO login_audit (auth_type, user_id, principal, outcome, login_at, ip_address, user_agent)
     VALUES ('staff', ?, ?, 'success', ?, '203.0.113.50', ?)"
);
for ($i = 0; $i < 120; $i++) {
    $loginInsert->execute([$adminId, 'Local Admin', '2035-08-01 09:00:00', 'HistoryExport/1']);
}
for ($i = 0; $i < 5; $i++) {
    $loginInsert->execute([$managerId, 'Local Manager', '2035-08-02 09:00:00', 'HistoryOther/1']);
}
$ready = bakery_login_history_ready($db);
$historyFilters = bakery_login_history_parse_filters([
    'from' => '2035-08-01',
    'until' => '2035-08-01',
    'user_id' => (string)$adminId,
]);
$historyView = bakery_login_history_load_records($db, $historyFilters, $ready, 1000);
actor_assert(bakery_login_history_view_limit() === 100, 'login history view cap is 100');
actor_assert(count($historyView['rows']) === 100, 'login history view stops at 100 rows');
actor_assert((int)$historyView['total'] === 120, 'login history still counts the rows past the view cap');
$historyExport = bakery_login_history_export_rows($db, $historyFilters, $ready);
actor_assert(count($historyExport) === 120, 'login history CSV includes the full range for that user');
actor_assert(in_array('user_agent', bakery_login_history_csv_headers(), true), 'login history CSV includes user agent');
actor_assert(($historyExport[0]['user_agent'] ?? '') === 'HistoryExport/1', 'exported login row keeps the user agent');
$otherHistory = bakery_login_history_export_rows($db, bakery_login_history_parse_filters([
    'from' => '2035-08-02',
    'until' => '2035-08-02',
    'user_id' => (string)$managerId,
]), $ready);
actor_assert(count($otherHistory) === 5, 'login history CSV follows the user and date filters');

echo "=== Driver shift session ===\n";
actor_assert(BAKERY_DRIVER_SHIFT_SECONDS === 12 * 60 * 60, 'driver shift floor is 12 hours');
actor_assert(BAKERY_DRIVER_SESSION_IDLE_SECONDS >= 60 * 24 * 60 * 60, 'driver idle window stays at least 60 days');
actor_assert(BAKERY_DRIVER_SESSION_ABSOLUTE_SECONDS >= 180 * 24 * 60 * 60, 'driver absolute window stays at least 180 days');
$driverUserId = actor_user_id($db, '9003');
actor_reset_session();
$_SESSION['user_id'] = $driverUserId;
$_SESSION['user_role_slug'] = 'driver';
$_SESSION['user_display_name'] = 'Local Driver';
$_SESSION['user_driver_id'] = 1;
$_SESSION['auth_login_at'] = time();
$_SESSION['auth_last_activity'] = time() - (11 * 60 * 60);
bakery_touch_session();
actor_assert(bakery_current_user() !== null, 'a driver is still signed in after 11 hours');
actor_assert(abs(time() - (int)$_SESSION['auth_last_activity']) < 5, 'driver activity slides forward');
bakery_logout();
actor_assert(bakery_current_user() === null, 'logout still ends the driver session');

echo "=== Page wiring ===\n";
$timelinePage = (string)file_get_contents($root . '/operational_timeline.php');
$usersPage = (string)file_get_contents($root . '/users.php');
$dashboard = (string)file_get_contents($root . '/index.php');
$config = (string)file_get_contents($root . '/includes/config.php');
$loginPage = (string)file_get_contents($root . '/login.php');
actor_assert(strpos($timelinePage, 'ot-badge--bot') !== false, 'timeline shows a bot badge');
actor_assert(strpos($timelinePage, 'name="export" value="csv"') !== false, 'timeline form can export CSV');
actor_assert(strpos($timelinePage, 'name="date_from"') !== false && strpos($timelinePage, 'name="date_until"') !== false, 'timeline export has a date range');
actor_assert(strpos($timelinePage, 'bakery_operational_timeline_view_limit()') !== false, 'timeline view uses the 150 cap');
actor_assert(strpos($usersPage, 'bakery_users_inactive_accounts') !== false, 'users page lists quiet accounts');
actor_assert(strpos($usersPage, 'inactive_60_days') !== false, 'quiet-account deactivate is marked');
actor_assert(strpos($usersPage, 'name="is_bot"') !== false, 'users page can mark a bot account');
actor_assert(strpos($dashboard, 'dismiss_admin_signin_alert') !== false, 'dashboard can dismiss an administrator sign-in flag');
actor_assert(strpos($dashboard, "=== 'administrator'") !== false, 'sign-in flag is limited to administrators');
actor_assert(strpos($loginPage, 'login.lockout_warning') !== false, 'staff login shows the lockout warning');
actor_assert(strpos($config, 'tmp/sessions') !== false && strpos($config, 'session_save_path($sessionDir)') !== false, 'driver sessions use a bakery session directory');
actor_assert(strpos($config, "ini_set('session.gc_maxlifetime', (string)\$sessionLifetime);") !== false, 'session lifetime ini setting stays in place');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
