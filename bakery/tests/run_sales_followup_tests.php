<?php
/**
 * Sales follow-up visibility.
 *
 * The 7-day activity window matches the orders chart: today and the six
 * days before it. An A lead is flagged only when the next action is more
 * than 7 days overdue. Drivers and sales do not get the manager list.
 *
 * bakerysf_test only.
 *
 * Usage: php tests/run_sales_followup_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/sales_leads.php';

function followup_session(string $role): void
{
    $_SESSION = [
        'user_id' => 50,
        'user_email' => 'followup@example.test',
        'user_display_name' => 'Followup',
        'user_role_slug' => $role,
    ];
}

function followup_cleanup(PDO $db): void
{
    $db->exec("DELETE FROM leads WHERE external_id LIKE 'SFL-FU%' OR customer_name LIKE 'Followup Test %'");
    $db->exec("DELETE FROM users WHERE email LIKE 'followup-%@example.test'");
}

function followup_unused_code(PDO $db): string
{
    $taken = $db->prepare('SELECT id FROM users WHERE login_code = ?');
    for ($try = 0; $try < 40; $try++) {
        $code = (string)random_int(1000, 9999);
        $taken->execute([$code]);
        if (!$taken->fetchColumn()) {
            return $code;
        }
    }
    return '';
}

function followup_user(PDO $db, string $slug, string $name, string $email, int $active): int
{
    $roleId = (int)$db->query('SELECT id FROM roles WHERE slug = ' . $db->quote($slug))->fetchColumn();
    assert_true($roleId > 0, 'role ' . $slug . ' exists');
    $code = followup_unused_code($db);
    assert_true($code !== '', 'unused login code for ' . $email);
    $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$email, password_hash('followup', PASSWORD_DEFAULT), $code, $name, $roleId, $active]);
    return (int)$db->lastInsertId();
}

function followup_lead(PDO $db, string $externalId, string $name, string $priority, ?string $nextDate): int
{
    $db->prepare(
        'INSERT INTO leads (customer_name, contact_name, status, external_id, priority, stage, next_action_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$name, 'Casey', 'new', $externalId, $priority, 'New', $nextDate]);
    return (int)$db->lastInsertId();
}

$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
assert_eq('bakerysf_test', $dbName, 'tests use bakerysf_test');

if (!bakery_sales_leads_ready($db)) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/run_migrations.php');
    passthru($cmd, $migrateCode);
    assert_eq(0, $migrateCode, 'sales lead migrations apply on bakerysf_test');
    bakery_forget_table_exists('lead_activity');
}
assert_true(bakery_sales_leads_ready($db), 'sales lead activity table is present');
assert_true(!is_file($root . '/database/schema/120_sales_followup.sql'), 'follow-up visibility needs no migration 120');

$today = '2026-11-24';

echo "\n=== 7-day window ===\n";
$window = bakery_sales_leads_activity_window($today);
assert_eq('2026-11-18', $window['start'], 'window starts six days before today');
assert_eq($today, $window['end'], 'window ends today');
assert_true(bakery_sales_leads_in_activity_window($today, $today), 'activity today is inside the window');
assert_true(bakery_sales_leads_in_activity_window('2026-11-18', $today), 'activity on the first day of the 7-day window is included');
assert_true(!bakery_sales_leads_in_activity_window('2026-11-17', $today), 'activity one day before the 7-day window is excluded');
assert_true(!bakery_sales_leads_in_activity_window('2026-11-25', $today), 'activity after today is excluded');
$march = bakery_sales_leads_activity_window('2026-03-01');
assert_eq('2026-02-23', $march['start'], 'window crosses a month boundary on the calendar');
assert_eq('2026-03-01', $march['end'], 'month-boundary window still ends on the given day');
assert_true(bakery_sales_leads_in_activity_window('2026-02-23', '2026-03-01'), 'Feb 23 is the first day of a March 1 window');
assert_true(!bakery_sales_leads_in_activity_window('2026-02-22', '2026-03-01'), 'Feb 22 is outside a March 1 window');

echo "\n=== A-priority overdue cutoff ===\n";
$exact = ['priority' => 'A', 'next_action_date' => '2026-11-17'];
$eight = ['priority' => 'A', 'next_action_date' => '2026-11-16'];
assert_true(!bakery_sales_leads_a_followup_late($exact, $today), 'an A lead exactly 7 days overdue is not flagged');
assert_true(bakery_sales_leads_a_followup_late($eight, $today), 'an A lead 8 days overdue is flagged');
assert_eq(8, bakery_sales_leads_a_late_days($eight, $today), 'the flag carries the day count');
assert_eq(null, bakery_sales_leads_a_late_days($exact, $today), 'exactly 7 days has no day count');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => 'A', 'next_action_date' => '2026-11-24'], $today), 'an A lead due today is not flagged');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => 'A', 'next_action_date' => '2026-11-18'], $today), 'an A lead 6 days overdue is not flagged');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => 'A', 'next_action_date' => null], $today), 'an A lead with no next action date is not flagged');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => 'B', 'next_action_date' => '2026-11-01'], $today), 'a B lead is never flagged');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => 'C', 'next_action_date' => '2026-11-01'], $today), 'a C lead is never flagged');
assert_true(!bakery_sales_leads_a_followup_late(['priority' => '', 'next_action_date' => '2026-11-01'], $today), 'a lead with no priority is never flagged');
assert_eq('', bakery_sales_leads_a_late_markup(['priority' => 'B', 'next_action_date' => '2026-11-01'], $today), 'a non-A lead has no badge');
assert_eq('', bakery_sales_leads_a_late_markup($exact, $today), 'exactly 7 days has no badge');
$badge = bakery_sales_leads_a_late_markup($eight, $today);
assert_true(strpos($badge, 'data-sales-a-late="1"') !== false, 'an 8-day A lead renders a badge');
assert_true(strpos($badge, '8d') !== false, 'the badge shows the day count');
assert_true(strpos($badge, 'sales-leads-banner') === false, 'the badge is not a banner');

echo "\n=== Activity kinds ===\n";
assert_eq('stage', bakery_sales_leads_activity_kind([
    'stage_from' => 'New',
    'stage_to' => 'Contacted',
    'note' => 'Called anyway',
]), 'a stage change stays a stage change');
assert_eq('call', bakery_sales_leads_activity_kind(['note' => 'Called the buyer']), 'a call note is a call');
assert_eq('visit', bakery_sales_leads_activity_kind(['note' => 'Visited the kitchen']), 'a visit note is a visit');
assert_eq('note', bakery_sales_leads_activity_kind(['note' => 'Left a card']), 'other notes stay notes');

echo "\n=== Who can see the manager list ===\n";
followup_session('driver');
assert_true(!bakery_sales_leads_can_review_reps(), 'a driver cannot review rep activity');
assert_eq(null, bakery_sales_leads_rep_activity($db, $today), 'a driver does not receive the manager list');
assert_eq(403, bakery_sales_leads_role_status(), 'a driver cannot open leads');
followup_session('sales');
assert_true(!bakery_sales_leads_can_review_reps(), 'a sales rep cannot review rep activity');
assert_eq(null, bakery_sales_leads_rep_activity($db, $today), 'a sales rep does not receive the manager list');
followup_session('manager');
assert_true(bakery_sales_leads_can_review_reps(), 'a manager can review rep activity');
followup_session('administrator');
assert_true(bakery_sales_leads_can_review_reps(), 'an administrator can review rep activity');

$salesHome = (string)file_get_contents($root . '/sales_home.php');
$workspace = (string)file_get_contents($root . '/includes/sales_workspace_page.php');
$leadsPage = (string)file_get_contents($root . '/leads.php');
$pipeline = (string)file_get_contents($root . '/includes/sales_pipeline_board.php');
assert_true(strpos($salesHome, 'data-sales-rep-activity') === false, 'Sales Home has no manager activity list');
assert_true(strpos($workspace, 'data-sales-rep-activity') === false, 'the sales lead page has no manager activity list');
assert_true(strpos($leadsPage, 'data-sales-rep-activity') !== false, 'the manager leads page has the activity list');
$workspaceExit = strpos($leadsPage, "includes/sales_workspace_page.php");
$managerMarker = strpos($leadsPage, 'data-sales-rep-activity');
assert_true($workspaceExit !== false && $managerMarker !== false && $workspaceExit < $managerMarker, 'sales leaves the page before the manager list');
assert_true(strpos($pipeline, 'data-sales-a-late') !== false || strpos($pipeline, 'bakery_sales_leads_a_late_markup') !== false, 'the leads list can show the badge');
assert_true(strpos($workspace, 'bakery_sales_leads_a_late_markup') !== false, 'the lead itself can show the badge');
assert_true(strpos($leadsPage, 'bakery_sales_leads_a_late_markup') !== false, 'the manager lead page can show the badge');

echo "\n=== Rep activity rows ===\n";
followup_cleanup($db);
$lateBefore = (int)$db->query(
    "SELECT COUNT(*) FROM leads
     WHERE UPPER(priority) = 'A'
       AND next_action_date IS NOT NULL
       AND next_action_date < '2026-11-17'"
)->fetchColumn();
$ana = followup_user($db, 'sales', 'Ana Active', 'followup-ana@example.test', 1);
$bea = followup_user($db, 'sales', 'Bea Quiet', 'followup-bea@example.test', 1);
$old = followup_user($db, 'sales', 'Old Rep', 'followup-old@example.test', 0);
$dee = followup_user($db, 'driver', 'Dee Driver', 'followup-dee@example.test', 1);
$cafe = followup_lead($db, 'SFL-FU1', 'Followup Test Cafe', 'A', '2026-11-16');
$exactLead = followup_lead($db, 'SFL-FU2', 'Followup Test Exact', 'A', '2026-11-17');
$bee = followup_lead($db, 'SFL-FU3', 'Followup Test Bee', 'B', '2026-11-01');
followup_lead($db, 'SFL-FU4', 'Followup Test Sea', 'C', '2026-11-01');
followup_lead($db, 'SFL-FU5', 'Followup Test Due', 'A', '2026-11-24');
followup_lead($db, 'SFL-FU6', 'Followup Test Blank', 'A', null);
$log = $db->prepare(
    'INSERT INTO lead_activity (lead_id, activity_date, user_id, note, stage_from, stage_to)
     VALUES (?, ?, ?, ?, ?, ?)'
);
$log->execute([$cafe, '2026-11-18', $ana, 'Called the buyer', null, null]);
$log->execute([$cafe, '2026-11-17', $ana, 'Visited the dock', null, null]);
$log->execute([$cafe, '2026-11-25', $ana, 'Tomorrow note', null, null]);
$log->execute([$cafe, '2026-11-24', $ana, 'Called anyway', 'New', 'Contacted']);
$log->execute([$cafe, '2026-11-24', $ana, 'Visited the kitchen', null, null]);
$log->execute([$bee, '2026-11-24', $dee, 'Called from the route', null, null]);
$log->execute([$bee, '2026-11-24', $old, 'Called last year', null, null]);

followup_session('manager');
$report = bakery_sales_leads_rep_activity($db, $today);
assert_true(is_array($report), 'a manager receives the rep report');
if (is_array($report)) {
    assert_eq($lateBefore + 1, (int)$report['late_count'], 'the manager count adds only the 8-day A lead');
    $byName = [];
    $seenActive = false;
    foreach ($report['reps'] as $rep) {
        $byName[(string)$rep['name']] = $rep;
        if ((int)$rep['count'] === 0 && $seenActive) {
            assert_true(false, 'a rep with zero activity is listed before reps who logged something');
        }
        if ((int)$rep['count'] > 0) {
            $seenActive = true;
        }
    }
    assert_true(isset($byName['Ana Active']), 'Ana is on the manager list');
    assert_true(isset($byName['Bea Quiet']), 'a rep with no activity is still listed');
    assert_true(!isset($byName['Old Rep']), 'an inactive sales user is not listed');
    assert_true(!isset($byName['Dee Driver']), 'a driver is not listed as a sales rep');
    if (isset($byName['Bea Quiet'])) {
        assert_eq(0, (int)$byName['Bea Quiet']['count'], 'Bea has a zero count');
        assert_eq([], $byName['Bea Quiet']['items'], 'Bea has no items');
    }
    if (isset($byName['Ana Active'])) {
        assert_eq(3, (int)$byName['Ana Active']['count'], 'Ana has the three in-window items');
        $kinds = array_map(static function (array $item): string {
            return (string)$item['kind'];
        }, $byName['Ana Active']['items']);
        assert_eq(['visit', 'stage', 'call'], $kinds, 'in-window items are visit, stage, then the older call');
        $summaries = array_map(static function (array $item): string {
            return (string)$item['summary'];
        }, $byName['Ana Active']['items']);
        $joined = implode("\n", $summaries);
        assert_true(strpos($joined, 'Visited the dock') === false, 'the visit from the day before the window is omitted');
        assert_true(strpos($joined, 'Tomorrow note') === false, 'a future note is omitted');
        assert_true(strpos($joined, 'Visited the kitchen') !== false, 'the in-window visit is listed');
        assert_true(strpos($joined, 'Called the buyer') !== false, 'the call on the first window day is listed');
    }
    $names = array_map(static function (array $rep): string {
        return (string)$rep['name'];
    }, $report['reps']);
    $beaAt = array_search('Bea Quiet', $names, true);
    $anaAt = array_search('Ana Active', $names, true);
    assert_true($beaAt !== false && $anaAt !== false && $beaAt < $anaAt, 'the zero-activity rep is listed ahead of Ana');
}

followup_session('sales');
assert_eq(null, bakery_sales_leads_rep_activity($db, $today), 'sales still cannot read the report after rows exist');
followup_session('driver');
assert_eq(null, bakery_sales_leads_rep_activity($db, $today), 'a driver still cannot read the report after rows exist');

$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);
assert_true(bakery_t('leads.rep_activity_title') !== 'leads.rep_activity_title', 'English has the manager title');
assert_true(bakery_t('leads.a_late') !== 'leads.a_late', 'English has the badge');
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('es', false);
assert_true(bakery_t('leads.rep_activity_title') !== 'leads.rep_activity_title', 'Spanish has the manager title');
assert_true(bakery_t('leads.rep_activity_title') !== 'Rep activity, last 7 days', 'Spanish manager title is translated');
assert_true(bakery_t('leads.kind.visit') === 'Visita', 'Spanish names a visit');
assert_true(bakery_t('leads.kind.call') === 'Llamada', 'Spanish names a call');
$GLOBALS['bakery_i18n_catalog'] = null;
bakery_set_locale('en', false);

followup_cleanup($db);
unset($exactLead);

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
