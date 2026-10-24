<?php
/**
 * Sales Leads phase 1.
 *
 * Role gate, upsert, dry run, stage log, token read, and the 182-row
 * Restaurant Week file. bakerysf_test only. The fixture is not seeded
 * into any other database.
 *
 * Usage: php tests/run_sales_leads_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
$db = require $root . '/tests/harness.php';
require_once $root . '/includes/sales_leads.php';
require_once $root . '/includes/zones_catalog.php';
require_once $root . '/includes/navigation_catalog.php';
require_once $root . '/includes/hosted_migration_runtime.php';
require_once $root . '/includes/schema_migration_numbers.php';

function sales_leads_clear_session(): void
{
    $_SESSION = [];
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}

function sales_leads_session(string $role): void
{
    sales_leads_clear_session();
    $_SESSION['user_id'] = 50;
    $_SESSION['user_email'] = 'sales-leads@example.test';
    $_SESSION['user_display_name'] = 'Sales Leads';
    $_SESSION['user_role_slug'] = $role;
}

function sales_leads_cleanup(PDO $db): void
{
    $customerIds = $db->query("SELECT id FROM customers WHERE name LIKE 'SalesLead Test %'")->fetchAll(PDO::FETCH_COLUMN);
    if ($customerIds !== []) {
        $in = implode(',', array_map('intval', $customerIds));
        $orderIds = $db->query("SELECT id FROM daily_orders WHERE customer_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        if ($orderIds !== []) {
            $orders = implode(',', array_map('intval', $orderIds));
            $db->exec("UPDATE leads SET sample_daily_order_id = NULL WHERE sample_daily_order_id IN ({$orders})");
            $db->exec("DELETE FROM daily_orders WHERE id IN ({$orders})");
        }
        $db->exec("DELETE FROM standing_orders WHERE customer_id IN ({$in})");
    }
    $db->exec("DELETE FROM leads WHERE external_id LIKE 'SFL-%' OR customer_name LIKE 'SalesLead Test %'");
    $db->exec("DELETE FROM customers WHERE name LIKE 'SalesLead Test %'");
    $db->exec("DELETE FROM zones WHERE name = 'SalesLead Mission Zone'");
    $db->exec("DELETE FROM users WHERE email LIKE 'sales-leads-test-%@example.test'");
}

function sales_leads_count(PDO $db, string $sql): int
{
    return (int)$db->query($sql)->fetchColumn();
}

$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
assert_eq('bakerysf_test', $dbName, 'tests use bakerysf_test');

if (!bakery_sales_leads_ready($db) || !column_exists($db, 'customers', 'neighborhood')) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/scripts/run_migrations.php');
    passthru($cmd, $migrateCode);
    assert_eq(0, $migrateCode, 'sales lead migrations apply on bakerysf_test');
    foreach (['external_id', 'stage', 'sample_daily_order_id', 'zone_id', 'last_updated'] as $column) {
        bakery_forget_column_exists('leads', $column);
    }
    bakery_forget_column_exists('customers', 'neighborhood');
    bakery_forget_table_exists('lead_activity');
}

assert_true(bakery_sales_leads_ready($db), 'sales lead columns and activity table exist');
assert_true(table_exists($db, 'lead_contacts'), 'existing lead_contacts table stays');

$demo = $db->query("SELECT customer_name, status, stage, contact_name, phone FROM leads WHERE customer_name = 'Demo Prospect Shop'")->fetch();
assert_true(is_array($demo), 'existing demo lead is still present');
if (is_array($demo)) {
    assert_eq('Demo Prospect Shop', (string)$demo['customer_name'], 'demo lead name is unchanged');
    assert_eq('new', (string)$demo['status'], 'demo lead status column is kept');
    assert_eq('New', (string)$demo['stage'], 'demo lead stage is backfilled from status');
    assert_eq('Casey Contact', (string)$demo['contact_name'], 'demo lead contact name is kept');
    assert_eq('555-0199', (string)$demo['phone'], 'demo lead phone is kept');
}

$migrationSql = (string)file_get_contents($root . '/database/schema/110_sales_leads.sql');
[$migrationSafe, $migrationMessage] = bakery_hosted_migration_sql_safe($migrationSql);
assert_true($migrationSafe, 'migration 110 is additive (' . $migrationMessage . ')');
$migration111 = (string)file_get_contents($root . '/database/schema/111_sales_lead_customer_neighborhood.sql');
[$migration111Safe, $migration111Message] = bakery_hosted_migration_sql_safe($migration111);
assert_true($migration111Safe, 'migration 111 is additive (' . $migration111Message . ')');
assert_true(column_exists($db, 'customers', 'neighborhood'), 'customer neighborhood column exists');
assert_true(bakery_schema_unexpected_duplicate_prefixes() === [], '110 does not reuse a schema number');

$leadsPage = (string)file_get_contents($root . '/leads.php');
$apiPage = (string)file_get_contents($root . '/sales_leads_api.php');
assert_true(strpos($leadsPage, 'bakery_sales_leads_require_access()') !== false, 'leads page checks the role gate');
assert_true(strpos($apiPage, 'BAKERY_SKIP_REQUEST_SECURITY') !== false, 'token endpoint does its own auth');
assert_true(strpos($apiPage, 'SALES_LEADS_TOKEN') !== false, 'token endpoint documents SALES_LEADS_TOKEN');
assert_true(stripos($apiPage, 'INSERT INTO') === false, 'token endpoint has no write SQL');
$customersPage = (string)file_get_contents($root . '/customers.php');
$hubCreatePage = (string)file_get_contents($root . '/includes/customer_hub_create.php');
assert_true(strpos($leadsPage, 'convert_lead') === false, 'legacy AJAX convert is not a second path');
assert_true(strpos($leadsPage, 'value="convert_create"') !== false, 'confirm create is the convert path');
assert_true(strpos($leadsPage, 'value="convert_link"') !== false, 'same-name customer can be linked');
assert_true(strpos($leadsPage, 'leads.convert_handoff') !== false, 'confirm screen names the Accounts and Bakery handoff');
assert_true(strpos($leadsPage, 'value="log_sample"') !== false, 'sample drop uses the lead page');
assert_true(strpos($leadsPage, 'leads.sample_handoff') !== false, 'sample screen names the one-time order');
$zonesPage = (string)file_get_contents($root . '/zones.php');
$zonesCatalog = (string)file_get_contents($root . '/includes/zones_catalog.php');
assert_true(strpos($zonesPage, 'bakery_zones_export_csv(') !== false, 'zones page downloads the CSV');
assert_true(strpos($zonesPage, 'SALES_LEADS_TOKEN') === false, 'zones page has no bearer token');
assert_true(strpos($zonesCatalog, 'SALES_LEADS_TOKEN') === false, 'zones export has no bearer token');
assert_true(!is_file($root . '/database/schema/112_sales_lead_sample.sql'), 'sample drop needs no migration 112');
assert_true(strpos($customersPage, 'bakery_customer_hub_create(') !== false, 'Customer Hub page uses the shared create');
assert_eq(1, substr_count($customersPage, 'INSERT INTO customers'), 'Customer Hub page no longer has its own create insert');
assert_true(strpos($hubCreatePage, 'INSERT INTO customers') !== false, 'shared create function owns the insert');

$leadRoles = bakery_navigation_roles_for_script('leads.php');
sort($leadRoles);
assert_eq(['administrator', 'manager'], $leadRoles, 'Sales Leads menu is managers and administrators only');
$zoneRoles = bakery_navigation_roles_for_script('zones.php');
sort($zoneRoles);
assert_eq(['administrator', 'manager'], $zoneRoles, 'Delivery Zones menu is managers and administrators only');

echo "\n=== Role gate ===\n";
sales_leads_clear_session();
assert_eq(401, bakery_sales_leads_role_status(), 'guest with no session is denied');
sales_leads_session('guest');
assert_eq(403, bakery_sales_leads_role_status(), 'guest role is denied');
sales_leads_session('driver');
assert_eq(403, bakery_sales_leads_role_status(), 'driver is denied');
sales_leads_session('driver_assistant');
assert_eq(403, bakery_sales_leads_role_status(), 'driver assistant is denied');
sales_leads_session('baker');
assert_eq(403, bakery_sales_leads_role_status(), 'baker is denied');
sales_leads_session('cashier');
assert_eq(403, bakery_sales_leads_role_status(), 'cashier is denied');
sales_leads_session('manager');
assert_eq(200, bakery_sales_leads_role_status(), 'manager is allowed');
sales_leads_session('administrator');
assert_eq(200, bakery_sales_leads_role_status(), 'administrator is allowed');
sales_leads_clear_session();

sales_leads_cleanup($db);

echo "\n=== Dry run and rejects ===\n";
$beforeLeads = sales_leads_count($db, 'SELECT COUNT(*) FROM leads');
$beforeActivity = sales_leads_count($db, 'SELECT COUNT(*) FROM lead_activity');
$previewCsv = "id,name,fit_score,priority,stage,sfrw_meals\n"
    . "SFL-T1,SalesLead Test Preview,3,A,New,Lunch\n"
    . "SFL-T2,SalesLead Test Bad Score,9,A,New,\n";
$dry = bakery_sales_leads_import($db, $previewCsv, ['dry_run' => true]);
assert_true(!empty($dry['ok']) && !empty($dry['dry_run']), 'dry run returns a preview');
assert_eq(1, (int)$dry['new'], 'dry run counts the valid row as new');
assert_eq(1, (int)$dry['rejected'], 'dry run counts the bad score as rejected');
$rejectedReason = '';
foreach ($dry['rows'] as $previewRow) {
    if (($previewRow['outcome'] ?? '') === 'rejected') {
        $rejectedReason = (string)$previewRow['reason'];
    }
}
assert_eq('bad_fit_score', $rejectedReason, 'rejected row explains the fit score');
assert_eq($beforeLeads, sales_leads_count($db, 'SELECT COUNT(*) FROM leads'), 'dry run writes no leads');
assert_eq($beforeActivity, sales_leads_count($db, 'SELECT COUNT(*) FROM lead_activity'), 'dry run writes no activity');

$committed = bakery_sales_leads_import($db, $previewCsv, ['dry_run' => false]);
assert_true(!empty($committed['ok']), 'commit of the preview file succeeds');
assert_eq(1, (int)$committed['new'], 'commit inserts only the valid row');
assert_eq(1, (int)$committed['rejected'], 'commit still rejects the bad score');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE customer_name = 'SalesLead Test Bad Score'"), 'rejected name is not stored');
$previewLead = $db->query("SELECT notes FROM leads WHERE external_id = 'SFL-T1'")->fetch();
assert_true(is_array($previewLead) && strpos((string)$previewLead['notes'], 'sfrw_meals: Lunch') !== false, 'unknown column is kept in notes');

echo "\n=== Upsert by id and by name ===\n";
$byId = bakery_sales_leads_import($db, "id,name,stage\nSFL-T1,SalesLead Test Renamed,Researched\n");
assert_eq(0, (int)$byId['new'], 'second import of the same id is not new');
assert_eq(1, (int)$byId['updated'], 'second import of the same id updates');
assert_eq('SalesLead Test Renamed', (string)$db->query("SELECT customer_name FROM leads WHERE external_id = 'SFL-T1'")->fetchColumn(), 'id upsert changes the name');
assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE external_id = 'SFL-T1'"), 'id upsert does not duplicate');

$db->prepare(
    "INSERT INTO leads (customer_name, contact_name, phone, email, address, notes, status)
     VALUES ('SalesLead Test Named', 'Casey', '555-0100', NULL, NULL, 'keep me', 'new')"
)->execute();
$byName = bakery_sales_leads_import($db, "id,name,stage,priority\nSFL-TNAME,saleslead test named,Contacted,B\n");
assert_eq(0, (int)$byName['new'], 'name match is not a new lead');
assert_eq(1, (int)$byName['updated'], 'name match updates the existing lead');
$named = $db->query("SELECT id, external_id, phone, contact_name FROM leads WHERE customer_name = 'saleslead test named'")->fetch();
assert_true(is_array($named), 'name match finds the lead case-insensitively');
if (is_array($named)) {
    assert_eq('SFL-TNAME', (string)$named['external_id'], 'name match attaches the external id');
    assert_eq('555-0100', (string)$named['phone'], 'name match keeps the existing phone');
    assert_eq('Casey', (string)$named['contact_name'], 'name match keeps the existing contact name');
    assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE LOWER(customer_name) = 'saleslead test named'"), 'name match does not duplicate');
}

echo "\n=== Zone link and stage log ===\n";
$db->prepare("INSERT INTO zones (name, description, color) VALUES ('SalesLead Mission Zone', 'test', '#336699')")->execute();
$zoneId = (int)$db->lastInsertId();
$zoned = bakery_sales_leads_import(
    $db,
    "id,name,route_fit,stage\nSFL-TZ,SalesLead Test Zoned,Tuesday on SalesLead Mission Zone,New\n"
);
assert_eq(1, (int)$zoned['new'], 'zoned lead inserts');
assert_eq(
    $zoneId,
    (int)$db->query("SELECT zone_id FROM leads WHERE external_id = 'SFL-TZ'")->fetchColumn(),
    'route fit text links the delivery zone'
);
$zoneFiltered = array_column(bakery_sales_leads_list($db, ['zone' => (string)$zoneId]), 'external_id');
assert_true(in_array('SFL-TZ', $zoneFiltered, true), 'zone filter returns the linked lead');
$unlinked = array_column(bakery_sales_leads_list($db, ['zone' => 'none']), 'external_id');
assert_true(!in_array('SFL-TZ', $unlinked, true), 'unlinked zone filter leaves out the linked lead');

$roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'manager' LIMIT 1")->fetchColumn();
assert_true($roleId > 0, 'manager role exists for the activity user');
$testerEmail = 'sales-leads-test-' . bin2hex(random_bytes(3)) . '@example.test';
$db->prepare(
    'INSERT INTO users (email, password_hash, display_name, role_id, is_active) VALUES (?, ?, ?, ?, 1)'
)->execute([$testerEmail, 'test', 'Sales Lead Tester', $roleId]);
$testerId = (int)$db->lastInsertId();

$created = bakery_sales_leads_save($db, [
    'name' => 'SalesLead Test Stage',
    'stage' => 'New',
    'external_id' => 'SFL-TSTG',
], $testerId);
assert_true(!empty($created['ok']), 'create lead for the stage log (' . (string)($created['error'] ?? '') . ')');
$stageLeadId = (int)($created['id'] ?? 0);
$db->prepare('UPDATE leads SET contact_name = ?, phone = ?, email = ? WHERE id = ?')
    ->execute(['Casey Contact', '555-0199', 'casey@example.test', $stageLeadId]);
$changed = bakery_sales_leads_save($db, [
    'internal_id' => $stageLeadId,
    'external_id' => 'SFL-TSTG',
    'name' => 'SalesLead Test Stage',
    'stage' => 'Contacted',
    'change_note' => 'Called the front desk',
], $testerId);
assert_true(!empty($changed['ok']), 'stage change saves');
$log = $db->prepare(
    "SELECT user_id, note, stage_from, stage_to
     FROM lead_activity
     WHERE lead_id = ? AND stage_to = 'Contacted'
     ORDER BY id DESC LIMIT 1"
);
$log->execute([$stageLeadId]);
$logRow = $log->fetch();
assert_true(is_array($logRow), 'stage change writes an activity row');
if (is_array($logRow)) {
    assert_eq('New', (string)$logRow['stage_from'], 'activity records the previous stage');
    assert_eq('Contacted', (string)$logRow['stage_to'], 'activity records the new stage');
    assert_eq($testerId, (int)$logRow['user_id'], 'activity records the user');
    assert_eq('Called the front desk', (string)$logRow['note'], 'activity keeps the note');
}
$keptContact = $db->prepare('SELECT contact_name, phone, email FROM leads WHERE id = ?');
$keptContact->execute([$stageLeadId]);
$keptRow = $keptContact->fetch();
assert_true(is_array($keptRow), 'stage save still finds the lead');
if (is_array($keptRow)) {
    assert_eq('Casey Contact', (string)$keptRow['contact_name'], 'manual save keeps the existing contact name');
    assert_eq('555-0199', (string)$keptRow['phone'], 'manual save keeps a phone the channel did not replace');
    assert_eq('casey@example.test', (string)$keptRow['email'], 'manual save keeps an email the channel did not replace');
}

$noted = bakery_sales_leads_add_note($db, $stageLeadId, $testerId, 'Left a sample card', '2026-10-24');
assert_true(!empty($noted['ok']), 'a dated note can be added without a stage change');

$dated = bakery_sales_leads_import(
    $db,
    "id,name,priority,stage,next_action_date\nSFL-TE,SalesLead Test Early,A,New,2026-01-02\nSFL-TL,SalesLead Test Late,C,New,2026-12-02\n"
);
assert_eq(2, (int)$dated['new'], 'dated leads insert for the list sort');
$ordered = bakery_sales_leads_list($db, []);
$positions = [];
foreach ($ordered as $index => $row) {
    $positions[(string)$row['customer_name']] = $index;
}
assert_true(
    isset($positions['SalesLead Test Early'], $positions['SalesLead Test Late'])
        && $positions['SalesLead Test Early'] < $positions['SalesLead Test Late'],
    'list is sorted by next action date'
);
$priorityA = array_column(bakery_sales_leads_list($db, ['priority' => 'A', 'stage' => 'New']), 'customer_name');
assert_true(in_array('SalesLead Test Early', $priorityA, true), 'priority and stage filters include the A lead');
assert_true(!in_array('SalesLead Test Late', $priorityA, true), 'priority filter leaves out the C lead');

$lost = bakery_sales_leads_import(
    $db,
    "id,name,stage,priority\nSFL-TLOST,SalesLead Test Lost,Lost,C\n"
);
assert_eq(1, (int)$lost['new'], 'lost lead inserts');
$defaultNames = array_column(bakery_sales_leads_list($db, []), 'customer_name');
assert_true(!in_array('SalesLead Test Lost', $defaultNames, true), 'default list hides Lost leads');
assert_true(in_array('SalesLead Test Early', $defaultNames, true), 'default list still shows open leads');
$lostOnly = array_column(bakery_sales_leads_list($db, ['stage' => 'Lost']), 'customer_name');
assert_true(in_array('SalesLead Test Lost', $lostOnly, true), 'stage filter shows Lost leads');
assert_true(!in_array('SalesLead Test Early', $lostOnly, true), 'Lost stage filter leaves out other stages');
$withLost = array_column(bakery_sales_leads_list($db, ['show_lost' => '1']), 'customer_name');
assert_true(in_array('SalesLead Test Lost', $withLost, true), 'show Lost toggle includes Lost leads');
assert_true(in_array('SalesLead Test Early', $withLost, true), 'show Lost toggle keeps open leads');

echo "\n=== Convert to customer ===\n";
$standingBefore = sales_leads_count($db, 'SELECT COUNT(*) FROM standing_orders');
$routesBefore = sales_leads_count($db, 'SELECT COUNT(*) FROM standing_routes');
$open = bakery_sales_leads_save($db, [
    'name' => 'SalesLead Test Open',
    'stage' => 'Contacted',
    'external_id' => 'SFL-TOPEN',
], $testerId);
assert_true(!empty($open['ok']), 'open lead saves for the Won gate');
$openId = (int)($open['id'] ?? 0);
$won = bakery_sales_leads_save($db, [
    'name' => 'SalesLead Test Won',
    'stage' => 'Won',
    'external_id' => 'SFL-TWON',
    'address' => '100 Test St',
    'neighborhood' => 'Mission',
    'contact_channel' => '415-555-0199 buyer@example.test',
    'notes' => 'Dock on the left',
    'zone_id' => (string)$zoneId,
], $testerId);
assert_true(!empty($won['ok']), 'Won lead saves (' . (string)($won['error'] ?? '') . ')');
$wonId = (int)($won['id'] ?? 0);

sales_leads_session('driver');
$driverConvert = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'zone_id' => (string)$zoneId,
    'address' => '100 Test St',
    'neighborhood' => 'Mission',
    'contact_channel' => '415-555-0199 buyer@example.test',
    'notes' => 'Dock on the left',
]);
assert_eq('forbidden', (string)($driverConvert['error'] ?? ''), 'driver cannot convert');
sales_leads_clear_session();
$guestConvert = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'zone_id' => (string)$zoneId,
]);
assert_eq('forbidden', (string)($guestConvert['error'] ?? ''), 'guest cannot convert');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM customers WHERE name = 'SalesLead Test Won'"), 'denied convert creates no customer');
assert_eq(0, (int)$db->query("SELECT COUNT(*) FROM leads WHERE id = {$wonId} AND customer_id IS NOT NULL")->fetchColumn(), 'denied convert leaves customer_id empty');

sales_leads_session('manager');
$_SESSION['locale'] = 'en';
bakery_locale(true);
$notWon = bakery_sales_leads_convert($db, $openId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Open',
    'zone_id' => (string)$zoneId,
]);
assert_eq('not_won', (string)($notWon['error'] ?? ''), 'only Won can convert');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM customers WHERE name = 'SalesLead Test Open'"), 'a Contacted lead creates no customer');

$missingZone = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'zone_id' => '',
]);
assert_eq('zone_required', (string)($missingZone['error'] ?? ''), 'zone is required');
$badZone = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'zone_id' => '999999999',
]);
assert_eq('zone_required', (string)($badZone['error'] ?? ''), 'unknown zone is refused');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM customers WHERE name = 'SalesLead Test Won'"), 'missing zone creates no customer');

$db->prepare('INSERT INTO customers (name, address) VALUES (?, ?)')->execute(['SalesLead Test Existing', '9 Old St']);
$existingId = (int)$db->lastInsertId();
$dupLead = bakery_sales_leads_save($db, [
    'name' => 'saleslead test existing',
    'stage' => 'Won',
    'external_id' => 'SFL-TDUP',
    'zone_id' => (string)$zoneId,
], $testerId);
assert_true(!empty($dupLead['ok']), 'duplicate-name lead saves');
$dupId = (int)($dupLead['id'] ?? 0);
$duplicate = bakery_sales_leads_convert($db, $dupId, $testerId, [
    'mode' => 'create',
    'name' => 'saleslead test existing',
    'zone_id' => (string)$zoneId,
]);
assert_eq('duplicate_name', (string)($duplicate['error'] ?? ''), 'same name warns instead of inserting');
assert_eq($existingId, (int)($duplicate['customer_id'] ?? 0), 'duplicate warning points at the existing customer');
assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM customers WHERE name = 'SalesLead Test Existing'"), 'duplicate convert does not insert a second customer');
assert_eq(0, (int)$db->query("SELECT COUNT(*) FROM leads WHERE id = {$dupId} AND customer_id IS NOT NULL")->fetchColumn(), 'refused duplicate leaves the lead unlinked');

$linked = bakery_sales_leads_convert($db, $dupId, $testerId, [
    'mode' => 'link',
    'name' => 'saleslead test existing',
    'link_customer_id' => $existingId,
]);
assert_true(!empty($linked['ok']), 'link to the existing customer succeeds (' . (string)($linked['error'] ?? '') . ')');
assert_eq($existingId, (int)$db->query("SELECT customer_id FROM leads WHERE id = {$dupId}")->fetchColumn(), 'link sets customer_id');
assert_eq('Won', (string)$db->query("SELECT stage FROM leads WHERE id = {$dupId}")->fetchColumn(), 'linked lead stays Won');
assert_eq('converted', (string)$db->query("SELECT status FROM leads WHERE id = {$dupId}")->fetchColumn(), 'linked lead keeps the converted status');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM standing_orders WHERE customer_id = {$existingId}"), 'link creates no standing order');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM standing_routes WHERE customer_id = {$existingId}"), 'link creates no route assignment');
$linkLog = $db->query("SELECT note, stage_from, stage_to FROM lead_activity WHERE lead_id = {$dupId} ORDER BY id DESC LIMIT 1")->fetch();
assert_true(is_array($linkLog), 'link writes an activity row');
if (is_array($linkLog)) {
    assert_eq('Converted to customer #' . $existingId, (string)$linkLog['note'], 'link activity names the customer');
    assert_eq(null, $linkLog['stage_from'], 'link activity is not a stage change');
    assert_eq(null, $linkLog['stage_to'], 'link activity leaves the stage columns empty');
}
$linkAgain = bakery_sales_leads_convert($db, $dupId, $testerId, [
    'mode' => 'create',
    'name' => 'saleslead test existing',
    'zone_id' => (string)$zoneId,
]);
assert_eq('already_converted', (string)($linkAgain['error'] ?? ''), 'a linked lead cannot convert again');
assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM lead_activity WHERE lead_id = {$dupId} AND note LIKE 'Converted to customer #%'"), 'a second convert writes no activity row');

$created = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'address' => '100 Test St',
    'neighborhood' => 'Mission',
    'contact_channel' => '415-555-0199 buyer@example.test',
    'notes' => 'Dock on the left',
    'zone_id' => (string)$zoneId,
]);
assert_true(!empty($created['ok']), 'Won lead creates a customer (' . (string)($created['error'] ?? '') . ')');
$createdId = (int)($created['customer_id'] ?? 0);
assert_true($createdId > 0, 'create returns the customer id');
$customer = $db->prepare('SELECT name, address, neighborhood, phone, email, zone_id, delivery_instructions FROM customers WHERE id = ?');
$customer->execute([$createdId]);
$customerRow = $customer->fetch();
assert_true(is_array($customerRow), 'created customer is stored');
if (is_array($customerRow)) {
    assert_eq('SalesLead Test Won', (string)$customerRow['name'], 'customer name comes from the lead');
    assert_eq('100 Test St', (string)$customerRow['address'], 'customer address comes from the lead');
    assert_eq('Mission', (string)$customerRow['neighborhood'], 'customer neighborhood comes from the lead');
    assert_eq('415-555-0199', (string)$customerRow['phone'], 'contact channel phone is copied');
    assert_eq('buyer@example.test', (string)$customerRow['email'], 'contact channel email is copied');
    assert_eq($zoneId, (int)$customerRow['zone_id'], 'customer zone is the chosen zone');
    assert_eq('Dock on the left', (string)$customerRow['delivery_instructions'], 'lead notes are saved as delivery instructions');
}
assert_eq($createdId, (int)$db->query("SELECT customer_id FROM leads WHERE id = {$wonId}")->fetchColumn(), 'create sets leads.customer_id');
assert_eq('Won', (string)$db->query("SELECT stage FROM leads WHERE id = {$wonId}")->fetchColumn(), 'converted lead stays Won');
assert_eq('converted', (string)$db->query("SELECT status FROM leads WHERE id = {$wonId}")->fetchColumn(), 'converted lead keeps the converted marker status');
$createLog = $db->query("SELECT note FROM lead_activity WHERE lead_id = {$wonId} AND note LIKE 'Converted to customer #%'")->fetch();
assert_true(is_array($createLog), 'create writes an activity row');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM standing_orders WHERE customer_id = {$createdId}"), 'create does not insert a standing order');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM standing_routes WHERE customer_id = {$createdId}"), 'create does not insert a route assignment');
assert_eq($standingBefore, sales_leads_count($db, 'SELECT COUNT(*) FROM standing_orders'), 'convert does not add standing orders');
assert_eq($routesBefore, sales_leads_count($db, 'SELECT COUNT(*) FROM standing_routes'), 'convert does not add route assignments');
$again = bakery_sales_leads_convert($db, $wonId, $testerId, [
    'mode' => 'create',
    'name' => 'SalesLead Test Won',
    'zone_id' => (string)$zoneId,
]);
assert_eq('already_converted', (string)($again['error'] ?? ''), 'a converted lead cannot convert again');
assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM customers WHERE name = 'SalesLead Test Won'"), 'idempotent convert does not insert another customer');
$history = bakery_sales_leads_history_for_customer($db, $createdId);
assert_eq(1, count($history), 'customer page can read the linked lead');
assert_true(count($history[0]['activity'] ?? []) > 0, 'customer page can read the lead activity');

echo "\n=== Sample drop ===\n";
sales_leads_session('driver');
$driverSample = bakery_sales_leads_log_sample($db, $wonId, $testerId, date('Y-m-d', strtotime('+7 days')));
assert_eq('forbidden', (string)($driverSample['error'] ?? ''), 'driver cannot log a sample');
sales_leads_clear_session();
$guestSample = bakery_sales_leads_log_sample($db, $wonId, $testerId, date('Y-m-d', strtotime('+7 days')));
assert_eq('forbidden', (string)($guestSample['error'] ?? ''), 'guest cannot log a sample');
sales_leads_session('manager');
$_SESSION['user_id'] = $testerId;
$_SESSION['locale'] = 'en';
bakery_locale(true);
$needsCustomer = bakery_sales_leads_log_sample($db, $openId, $testerId, date('Y-m-d', strtotime('+7 days')));
assert_eq('customer_required', (string)($needsCustomer['error'] ?? ''), 'a lead without a customer cannot log a sample');
$past = bakery_sales_leads_log_sample($db, $wonId, $testerId, '2000-01-01');
assert_eq('past_date', (string)($past['error'] ?? ''), 'a past sample date is refused');
$badDay = bakery_sales_leads_log_sample($db, $wonId, $testerId, 'tomorrow');
assert_eq('bad_date', (string)($badDay['error'] ?? ''), 'a sample date must be YYYY-MM-DD');

$sampleDate = new DateTime('tomorrow');
while ((int)$sampleDate->format('N') !== 1) {
    $sampleDate->modify('+1 day');
}
$sampleDay = $sampleDate->format('Y-m-d');
$takenDay = (clone $sampleDate)->modify('+1 day')->format('Y-m-d');
$db->prepare('INSERT INTO daily_orders (customer_id, order_date, status, total_amount) VALUES (?, ?, ?, 0)')
    ->execute([$createdId, $takenDay, 'pending']);
$takenOrderId = (int)$db->lastInsertId();
$taken = bakery_sales_leads_log_sample($db, $wonId, $testerId, $takenDay);
assert_eq('date_taken', (string)($taken['error'] ?? ''), 'an existing dated order is left alone');
assert_eq(0, (int)$db->query("SELECT COUNT(*) FROM leads WHERE id = {$wonId} AND sample_daily_order_id IS NOT NULL")->fetchColumn(), 'a taken date does not link a sample');

$db->prepare('UPDATE customers SET is_active = 0 WHERE id = ?')->execute([$existingId]);
$inactive = bakery_sales_leads_log_sample($db, $dupId, $testerId, $sampleDay);
assert_eq('customer_inactive', (string)($inactive['error'] ?? ''), 'an inactive customer cannot take a sample');
$db->prepare('UPDATE customers SET is_active = 1 WHERE id = ?')->execute([$existingId]);

$productId = (int)$db->query('SELECT id FROM products ORDER BY id ASC LIMIT 1')->fetchColumn();
assert_true($productId > 0, 'a catalog product exists for the copied sample line');
$standingDay = (int)bakery_standing_day_from_date($sampleDay);
$db->prepare('INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity) VALUES (?, ?, ?, 2)')
    ->execute([$createdId, $productId, $standingDay]);
$standingAtSample = sales_leads_count($db, 'SELECT COUNT(*) FROM standing_orders');
$routesAtSample = sales_leads_count($db, 'SELECT COUNT(*) FROM standing_routes');
$ordersBefore = sales_leads_count($db, "SELECT COUNT(*) FROM daily_orders WHERE customer_id = {$createdId} AND order_date = " . $db->quote($sampleDay));
$logged = bakery_sales_leads_log_sample($db, $wonId, $testerId, $sampleDay);
assert_true(!empty($logged['ok']), 'manager logs a sample (' . (string)($logged['error'] ?? '') . ')');
$sampleOrderId = (int)($logged['daily_order_id'] ?? 0);
assert_true($sampleOrderId > 0, 'sample returns the dated order id');
assert_eq($sampleOrderId, (int)$db->query("SELECT sample_daily_order_id FROM leads WHERE id = {$wonId}")->fetchColumn(), 'lead stores the sample order');
assert_eq($ordersBefore + 1, sales_leads_count($db, "SELECT COUNT(*) FROM daily_orders WHERE customer_id = {$createdId} AND order_date = " . $db->quote($sampleDay)), 'sample creates one dated order');
assert_eq($standingAtSample, sales_leads_count($db, 'SELECT COUNT(*) FROM standing_orders'), 'sample does not insert a standing order');
assert_eq($routesAtSample, sales_leads_count($db, 'SELECT COUNT(*) FROM standing_routes'), 'sample does not insert a route assignment');
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM daily_order_assignments WHERE daily_order_id = {$sampleOrderId}"), 'sample does not add a route assignment on the order');
$sampleLine = $db->query(
    "SELECT quantity, unit_price, line_total, is_no_charge, no_charge_reason
     FROM daily_order_items WHERE daily_order_id = {$sampleOrderId}"
)->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($sampleLine), 'the one-time order copied the standing line');
if (is_array($sampleLine)) {
    assert_eq(2, (int)$sampleLine['quantity'], 'copied quantity stays on the dated order');
    assert_eq('0.00', number_format((float)$sampleLine['unit_price'], 2, '.', ''), 'sample unit price is 0');
    assert_eq('0.00', number_format((float)$sampleLine['line_total'], 2, '.', ''), 'sample line total is 0');
    assert_eq(1, (int)$sampleLine['is_no_charge'], 'sample line is no-charge');
    assert_eq('sample', (string)$sampleLine['no_charge_reason'], 'no-charge reason is sample');
}
assert_eq('0.00', number_format((float)$db->query("SELECT total_amount FROM daily_orders WHERE id = {$sampleOrderId}")->fetchColumn(), 2, '.', ''), 'sample order total is 0');
$sampleLog = $db->query("SELECT note, stage_from, stage_to FROM lead_activity WHERE lead_id = {$wonId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
assert_true(is_array($sampleLog), 'sample writes an activity row');
if (is_array($sampleLog)) {
    assert_eq('Sample drop order #' . $sampleOrderId . ' on ' . $sampleDay, (string)$sampleLog['note'], 'activity row links the order');
    assert_eq(null, $sampleLog['stage_from'], 'sample activity is not a stage change');
    assert_eq(null, $sampleLog['stage_to'], 'sample activity leaves the stage columns empty');
}
$ordersAfter = sales_leads_count($db, 'SELECT COUNT(*) FROM daily_orders');
$againSample = bakery_sales_leads_log_sample($db, $wonId, $testerId, $sampleDay);
assert_eq('already_sampled', (string)($againSample['error'] ?? ''), 'a lead logs only one sample');
assert_eq($ordersAfter, sales_leads_count($db, 'SELECT COUNT(*) FROM daily_orders'), 'a second sample creates no order');
assert_eq(1, sales_leads_count($db, "SELECT COUNT(*) FROM lead_activity WHERE lead_id = {$wonId} AND note LIKE 'Sample drop order #%'"), 'a second sample writes no activity row');

echo "\n=== Delivery zones export ===\n";
sales_leads_clear_session();
putenv('SALES_LEADS_TOKEN=should-not-open-zones');
$_ENV['SALES_LEADS_TOKEN'] = 'should-not-open-zones';
$guestZones = bakery_zones_export_csv($db);
assert_eq(401, $guestZones['status'], 'zones CSV without a session is 401');
assert_eq('', $guestZones['body'], 'denied zones CSV has an empty body');
sales_leads_session('driver');
$driverZones = bakery_zones_export_csv($db);
assert_eq(403, $driverZones['status'], 'driver cannot download zones CSV');
sales_leads_session('manager');
$zoneCsv = bakery_zones_export_csv($db);
assert_eq(200, $zoneCsv['status'], 'manager downloads zones CSV');
assert_true(strpos((string)$zoneCsv['body'], "id,name,customer_count,lead_count\n") === 0, 'zones CSV starts with the count columns');
$expectedCustomers = sales_leads_count($db, "SELECT COUNT(*) FROM customers c WHERE c.zone_id = {$zoneId} OR (c.zone_id IS NULL AND c.zone = 'SalesLead Mission Zone')");
$expectedLeads = sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE zone_id = {$zoneId}");
assert_true($expectedCustomers >= 1, 'the test zone has a customer');
assert_true($expectedLeads >= 1, 'the test zone has a lead');
$foundZone = false;
foreach (preg_split("/\r\n|\n|\r/", (string)$zoneCsv['body']) as $csvLine) {
    if ($csvLine === '') {
        continue;
    }
    $cells = str_getcsv($csvLine, ',', '"', '');
    if (($cells[1] ?? '') === 'SalesLead Mission Zone') {
        $foundZone = true;
        assert_eq((string)$zoneId, (string)$cells[0], 'zones CSV includes the zone id');
        assert_eq((string)$expectedCustomers, (string)$cells[2], 'zones CSV customer count matches the table');
        assert_eq((string)$expectedLeads, (string)$cells[3], 'zones CSV lead count matches the table');
    }
}
assert_true($foundZone, 'zones CSV lists the test zone');
unset($_ENV['SALES_LEADS_TOKEN'], $_SERVER['SALES_LEADS_TOKEN']);
putenv('SALES_LEADS_TOKEN');
sales_leads_clear_session();

echo "\n=== Token endpoint ===\n";
sales_leads_clear_session();
putenv('SALES_LEADS_TOKEN');
unset($_ENV['SALES_LEADS_TOKEN'], $_SERVER['SALES_LEADS_TOKEN']);
$anonymous = bakery_sales_leads_api_dispatch($db, 'GET', [], $_SERVER);
assert_eq(401, $anonymous['status'], 'GET without a token is 401');
assert_eq(false, $anonymous['body']['ok'] ?? null, 'anonymous body is not ok');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-token';
putenv('SALES_LEADS_TOKEN=correct-token');
$_ENV['SALES_LEADS_TOKEN'] = 'correct-token';
$wrong = bakery_sales_leads_api_dispatch($db, 'GET', [], $_SERVER);
assert_eq(401, $wrong['status'], 'wrong bearer token is 401');

$leadCountBeforeWriteAttempt = sales_leads_count($db, 'SELECT COUNT(*) FROM leads');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer correct-token';
$allowed = bakery_sales_leads_api_dispatch($db, 'GET', [], $_SERVER);
assert_eq(200, $allowed['status'], 'matching bearer token returns JSON');
assert_eq(true, $allowed['body']['ok'] ?? null, 'token body is ok');
$ids = array_column($allowed['body']['leads'] ?? [], 'id');
assert_true(in_array('SFL-TSTG', $ids, true), 'JSON includes a lead id');
$posted = bakery_sales_leads_api_dispatch($db, 'POST', [], $_SERVER);
assert_eq(405, $posted['status'], 'POST is rejected');
assert_eq($leadCountBeforeWriteAttempt, sales_leads_count($db, 'SELECT COUNT(*) FROM leads'), 'rejected POST writes nothing');

$csvResponse = bakery_sales_leads_api_dispatch($db, 'GET', ['format' => 'csv'], $_SERVER);
assert_eq(200, $csvResponse['status'], 'CSV export is allowed with the token');
assert_true(strpos((string)$csvResponse['body'], 'id,name,type,') === 0, 'CSV export starts with the lead columns');
assert_true(strpos((string)$csvResponse['body'], 'SFL-TSTG') !== false, 'CSV export contains the lead');

sales_leads_clear_session();
unset($_SERVER['HTTP_AUTHORIZATION']);
sales_leads_session('driver');
$driverApi = bakery_sales_leads_api_dispatch($db, 'GET', [], $_SERVER);
assert_eq(403, $driverApi['status'], 'driver session cannot read the token endpoint');
sales_leads_clear_session();
unset($_SERVER['HTTP_AUTHORIZATION'], $_ENV['SALES_LEADS_TOKEN'], $_SERVER['SALES_LEADS_TOKEN']);
putenv('SALES_LEADS_TOKEN');

echo "\n=== Restaurant Week file ===\n";
$fixture = $root . '/tests/fixtures/sales_leads_sfrw_2026.csv';
$fixtureCsv = (string)file_get_contents($fixture);
$parsedFixture = bakery_sales_leads_parse_csv($fixtureCsv);
assert_eq(182, count($parsedFixture['rows']), 'fixture has 182 lead rows');
$demoBefore = $db->query("SELECT updated_at FROM leads WHERE customer_name = 'Demo Prospect Shop'")->fetchColumn();
$leadsBeforeFile = sales_leads_count($db, 'SELECT COUNT(*) FROM leads');
$fileDry = bakery_sales_leads_import($db, $fixtureCsv, ['dry_run' => true]);
assert_true(!empty($fileDry['ok']), 'fixture dry run succeeds (' . (string)($fileDry['error'] ?? '') . ')');
assert_eq(182, (int)$fileDry['new'], 'fixture dry run would add 182');
assert_eq(0, (int)$fileDry['rejected'], 'fixture dry run rejects nothing');
assert_eq($leadsBeforeFile, sales_leads_count($db, 'SELECT COUNT(*) FROM leads'), 'fixture dry run writes nothing');
assert_eq((string)$demoBefore, (string)$db->query("SELECT updated_at FROM leads WHERE customer_name = 'Demo Prospect Shop'")->fetchColumn(), 'fixture dry run leaves the demo lead alone');

$fileCommit = bakery_sales_leads_import($db, $fixtureCsv, ['dry_run' => false]);
assert_true(!empty($fileCommit['ok']), 'fixture import succeeds (' . (string)($fileCommit['error'] ?? '') . ')');
assert_eq(182, (int)$fileCommit['new'], 'fixture import adds 182 leads');
assert_eq(0, (int)$fileCommit['updated'], 'fixture import does not update other leads');
assert_eq(0, (int)$fileCommit['rejected'], 'fixture import rejects nothing');
assert_eq(182, sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE external_id LIKE 'SFL-%' AND external_id NOT LIKE 'SFL-T%'"), '182 SFL rows are stored');
$first = $db->query("SELECT notes, stage, customer_name FROM leads WHERE external_id = 'SFL-001'")->fetch();
assert_true(is_array($first), 'SFL-001 is stored');
if (is_array($first)) {
    assert_true(strpos((string)$first['notes'], 'sfrw_meals:') !== false, 'SFL-001 keeps the unknown sfrw_meals column in notes');
    assert_eq('Researched', (string)$first['stage'], 'SFL-001 stage is Researched');
}
assert_eq('Demo Prospect Shop', (string)$db->query("SELECT customer_name FROM leads WHERE customer_name = 'Demo Prospect Shop'")->fetchColumn(), 'demo lead survives the real import');

$fileAgain = bakery_sales_leads_import($db, $fixtureCsv, ['dry_run' => false]);
assert_true(!empty($fileAgain['ok']), 'fixture re-import succeeds');
assert_eq(0, (int)$fileAgain['new'], 're-import adds 0 leads');
assert_eq(0, (int)$fileAgain['updated'], 're-import updates 0 leads');
assert_eq(182, (int)$fileAgain['unchanged'], 're-import leaves 182 unchanged');

$sflRows = $db->query(
    "SELECT l.*, z.name AS zone_name
     FROM leads l
     LEFT JOIN zones z ON z.id = l.zone_id
     WHERE l.external_id LIKE 'SFL-%' AND l.external_id NOT LIKE 'SFL-T%'
     ORDER BY l.external_id ASC"
)->fetchAll();
$round = bakery_sales_leads_import($db, bakery_sales_leads_export_csv($sflRows), ['dry_run' => false]);
assert_eq(0, (int)$round['new'], 'export then import adds 0');
assert_eq(0, (int)$round['updated'], 'export then import updates 0');
assert_eq(182, (int)$round['unchanged'], 'export uses the same columns the importer reads');

sales_leads_cleanup($db);
assert_eq(0, sales_leads_count($db, "SELECT COUNT(*) FROM leads WHERE external_id LIKE 'SFL-%'"), 'test leads are removed from bakerysf_test');
assert_eq('Demo Prospect Shop', (string)$db->query("SELECT customer_name FROM leads WHERE customer_name = 'Demo Prospect Shop'")->fetchColumn(), 'cleanup keeps the demo lead');

echo "\n{$GLOBALS['TEST_PASS']} passed, {$GLOBALS['TEST_FAIL']} failed\n";
exit($GLOBALS['TEST_FAIL'] > 0 ? 1 : 0);
