<?php
/**
 * Sales role: Sales Home, limited nav, page gate, duplicate merge.
 * bakerysf_test only. No live data and no outbound messages.
 *
 * Usage: php tests/run_sales_role_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/tests/isolate_test_db.php';
define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/sales_leads.php';
require_once $root . '/includes/users_admin.php';
require_once $root . '/includes/zones_catalog.php';

$db = check_mysql_connection();
$GLOBALS['db'] = $db;
bakery_assert_local_test_target($db);

$passed = 0;
$failed = 0;

function sales_role_assert($ok, $message): void
{
    global $passed, $failed;
    if ($ok) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function sales_role_apply_sql(PDO $db, string $path): void
{
    $sql = (string)file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
    $buf = '';
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
        if ($statement !== '') {
            $db->exec($statement);
        }
    }
}

sales_role_apply_sql($db, $root . '/database/schema/113_sales_role.sql');

$roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'sales'")->fetchColumn();
sales_role_assert($roleId > 0, 'migration 113 inserts the sales role');
sales_role_assert(isset(bakery_assignable_role_labels()['sales']), 'Staff can assign Sales');
sales_role_assert(bakery_role_home('sales') === 'sales_home.php', 'sales login lands on Sales Home');
sales_role_assert(bakery_ops_index_bypass_home('sales') === 'sales_home.php', 'index sends sales to Sales Home');

$salesScripts = bakery_sales_page_scripts();
sales_role_assert(
    $salesScripts === ['customer_record.php', 'customers.php', 'leads.php', 'sales_home.php', 'zones.php'],
    'sales may open only the five lookup pages'
);
foreach (['cashier', 'baker', 'driver', 'driver_assistant'] as $otherRole) {
    $scripts = bakery_navigation_scripts_for_role($otherRole);
    sales_role_assert(!in_array('leads.php', $scripts, true), $otherRole . ' cannot open leads');
    sales_role_assert(!in_array('sales_home.php', $scripts, true), $otherRole . ' cannot open Sales Home');
    sales_role_assert(!in_array('customers.php', $scripts, true), $otherRole . ' cannot open customers');
}
$managerScripts = bakery_navigation_scripts_for_role('manager');
sales_role_assert(in_array('billing_center.php', $managerScripts, true), 'manager still opens Billing Center');
sales_role_assert(in_array('manager.php', $managerScripts, true), 'manager still opens the manager board');
sales_role_assert(bakery_navigation_roles_for_script('users.php') === ['administrator'], 'Staff stays administrator only');

$_SESSION = [
    'user_id' => 50,
    'user_role_slug' => 'sales',
    'user_display_name' => 'Sales Nav',
];
$_SERVER['PHP_SELF'] = '/sales_home.php';
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
ob_start();
include $root . '/includes/nav.php';
$salesNav = (string)ob_get_clean();
$expectedHrefs = [
    'sales_home.php',
    'leads.php?view=leads',
    'leads.php?view=prospects',
    'leads.php?view=followups',
    'leads.php?view=won',
    'zones.php',
    'customers.php',
];
foreach ($expectedHrefs as $href) {
    sales_role_assert(strpos($salesNav, $href) !== false, 'sales nav links ' . $href);
}
sales_role_assert(strpos($salesNav, 'customer_record.php') === false, 'sales nav omits the customer record');
sales_role_assert(strpos($salesNav, 'billing_center.php') === false, 'sales nav omits billing');
sales_role_assert(strpos($salesNav, 'manager.php') === false, 'sales nav omits the manager board');
sales_role_assert(strpos($salesNav, 'users.php') === false, 'sales nav omits Staff');
sales_role_assert(preg_match_all('/class="bakery-nav__direct /', $salesNav) === 7, 'sales nav has seven links');

$_SESSION['user_role_slug'] = 'administrator';
$_SERVER['PHP_SELF'] = '/users.php';
ob_start();
include $root . '/includes/nav.php';
$adminNav = (string)ob_get_clean();
sales_role_assert(strpos($adminNav, 'users.php') !== false && strpos($adminNav, 'Staff') !== false, 'administrator nav shows Staff');
$_SESSION['user_role_slug'] = 'manager';
ob_start();
include $root . '/includes/nav.php';
$managerNav = (string)ob_get_clean();
sales_role_assert(strpos($managerNav, 'users.php') === false, 'manager nav omits Staff');
$_SESSION = [];

$marker = 'SFL-T-DUP';
$db->prepare('DELETE FROM leads WHERE external_id LIKE ? OR customer_name = ?')->execute([$marker . '%', '3rd Cousin']);
$insert = $db->prepare(
    'INSERT INTO leads (customer_name, contact_name, phone, address, notes, status, stage, external_id, contact_channel, next_action, next_action_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insert->execute(['3rd Cousin', 'Ada', '4155550109', '123 Market Street', 'Keep note', 'new', 'New', $marker . '-A', '', 'Call', '2026-10-20']);
$keepId = (int)$db->lastInsertId();
$insert->execute(['3rd Cousin', 'Bea', '', '123  Market Street.', 'Drop note', 'new', 'Researched', $marker . '-B', '', 'Email', '2026-10-30']);
$dropId = (int)$db->lastInsertId();
$insert->execute(['3rd Cousin', 'Cara', '(415) 555-0199', '999 Other Road', 'Phone note', 'new', 'New', $marker . '-C', 'text 415.555.0199', 'Text', date('Y-m-d')]);
$phoneId = (int)$db->lastInsertId();
$insert->execute(['3rd Cousin', 'Dee', '', '', 'No match', 'new', 'New', $marker . '-D', '', '', null]);
$blankId = (int)$db->lastInsertId();
$db->prepare('INSERT INTO lead_activity (lead_id, activity_date, note) VALUES (?, ?, ?)')->execute([$dropId, '2026-10-01', 'Drop activity']);
$db->prepare(
    'INSERT INTO lead_contacts (lead_id, contact_date, contact_mode, comment) VALUES (?, ?, ?, ?)'
)->execute([$dropId, '2026-10-01', 'phone', 'Earlier drop contact']);
$db->prepare('INSERT INTO lead_activity (lead_id, activity_date, note) VALUES (?, ?, ?)')->execute([$keepId, '2026-09-01', 'Keep activity']);

$index = bakery_sales_leads_duplicate_index($db);
$keepOthers = array_column($index[$keepId] ?? [], 'id');
sales_role_assert(in_array($dropId, $keepOthers, true), 'same name and address flags a duplicate');
sales_role_assert(!in_array($blankId, $keepOthers, true), 'empty address and phone do not match');
$phoneOthers = array_column($index[$phoneId] ?? [], 'id');
sales_role_assert($phoneOthers === [], 'same name with a different address and phone is not a duplicate of the address pair');
$insert->execute(['3rd Cousin', 'Eve', '415-555-0199', '400 Far Street', 'Phone twin', 'new', 'Contacted', $marker . '-E', '', 'Visit', '2026-10-31']);
$phoneTwin = (int)$db->lastInsertId();
$index = bakery_sales_leads_duplicate_index($db);
sales_role_assert(in_array($phoneTwin, array_column($index[$phoneId] ?? [], 'id'), true), 'same name and phone flags a duplicate');

$_SESSION = ['user_id' => 50, 'user_role_slug' => 'sales', 'user_display_name' => 'Sales'];
$denied = bakery_sales_leads_merge($db, $keepId, $dropId, 50);
sales_role_assert(($denied['error'] ?? '') === 'forbidden', 'sales cannot merge');
sales_role_assert((int)$db->query('SELECT COUNT(*) FROM leads WHERE id = ' . $dropId)->fetchColumn() === 1, 'refused merge keeps both leads');

$code = '';
for ($try = 0; $try < 30; $try++) {
    $candidate = (string)random_int(1000, 9999);
    $taken = $db->prepare('SELECT id FROM users WHERE login_code = ?');
    $taken->execute([$candidate]);
    if (!$taken->fetchColumn()) {
        $code = $candidate;
        break;
    }
}
sales_role_assert($code !== '', 'found an unused staff code');
$salesUserId = 0;
try {
    $created = bakery_users_save($db, [
        'action' => 'create',
        'display_name' => 'Sales Role Ce23',
        'role_slug' => 'sales',
        'login_code' => $code,
        'email' => 'sales-role-ce23@example.test',
        'is_active' => '1',
    ]);
    $salesUserId = (int)($created['id'] ?? 0);
    sales_role_assert($salesUserId > 0, 'administrator save path accepts the sales role');
} catch (Throwable $e) {
    sales_role_assert(false, 'administrator save path accepts the sales role (' . $e->getMessage() . ')');
}
$_SESSION = ['user_id' => $salesUserId, 'user_role_slug' => 'manager', 'user_display_name' => 'Manager'];
$merged = bakery_sales_leads_merge($db, $keepId, $dropId, $salesUserId);
sales_role_assert(!empty($merged['ok']), 'manager merges a duplicate');
$kept = $db->query('SELECT notes, external_id FROM leads WHERE id = ' . $keepId)->fetch(PDO::FETCH_ASSOC);
sales_role_assert(is_array($kept) && strpos((string)$kept['notes'], 'Keep note') !== false && strpos((string)$kept['notes'], 'Drop note') !== false, 'merge keeps both notes');
sales_role_assert((int)$db->query('SELECT COUNT(*) FROM leads WHERE id = ' . $dropId)->fetchColumn() === 0, 'merge deletes the dropped lead');
sales_role_assert((int)$db->query('SELECT COUNT(*) FROM lead_activity WHERE lead_id = ' . $keepId . " AND note = 'Drop activity'")->fetchColumn() === 1, 'merge keeps the dropped activity');
sales_role_assert((int)$db->query('SELECT COUNT(*) FROM lead_activity WHERE lead_id = ' . $keepId . " AND note LIKE 'Merged lead %'")->fetchColumn() === 1, 'merge writes an activity row');
sales_role_assert((int)$db->query('SELECT COUNT(*) FROM lead_contacts WHERE lead_id = ' . $keepId . " AND comment = 'Earlier drop contact'")->fetchColumn() === 1, 'merge keeps the earlier contact');

$today = date('Y-m-d');
$overdue = date('Y-m-d', strtotime($today . ' -1 day'));
$upcoming = date('Y-m-d', strtotime($today . ' +4 days'));
sales_role_assert(bakery_sales_leads_followup_bucket(['next_action_date' => $overdue], $today) === 'overdue', 'yesterday is overdue');
sales_role_assert(bakery_sales_leads_followup_bucket(['next_action_date' => $today], $today) === 'today', 'today is today');
sales_role_assert(bakery_sales_leads_followup_bucket(['next_action_date' => $upcoming], $today) === 'upcoming', 'a later date is upcoming');
sales_role_assert(bakery_sales_leads_followup_bucket(['next_action_date' => '2026-10-30'], '2026-10-26') === 'upcoming', 'Oct 30 is upcoming on Oct 26');

$counts = bakery_sales_home_counts($db);
sales_role_assert(($counts['New'] ?? 0) >= 1, 'Sales Home counts New leads');
$homeFollow = bakery_sales_home_followups($db, $today);
sales_role_assert($homeFollow['overdue'] !== [] || $homeFollow['today'] !== [], 'Sales Home lists due follow-ups');

$port = 8098;
$cmd = 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root);
$spec = [
    0 => ['pipe', 'r'],
    1 => ['file', '/tmp/sales-role-server.log', 'w'],
    2 => ['file', '/tmp/sales-role-server.log', 'a'],
];
$env = $_ENV;
$env['DB_NAME'] = 'bakerysf_test';
$env['USE_PROD_DB'] = 'false';
$env['APP_ENV'] = 'local';
$env['MAIL_DRIVER'] = 'log';
$proc = proc_open($cmd, $spec, $pipes, $root, $env);
sales_role_assert(is_resource($proc), 'sales page server started');
$cookie = '/tmp/sales-role-cookies.txt';
@unlink($cookie);
$base = 'http://127.0.0.1:' . $port;

$http = static function (string $url, ?array $post = null) use ($cookie): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $raw = is_string($raw) ? $raw : '';
    $split = strpos($raw, "\r\n\r\n");
    $headers = $split === false ? $raw : substr($raw, 0, $split);
    $body = $split === false ? '' : substr($raw, $split + 4);
    $location = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $headers, $match) === 1) {
        $location = $match[1];
    }
    return ['status' => $status, 'location' => $location, 'body' => $body];
};

if (is_resource($proc)) {
    $up = false;
    for ($i = 0; $i < 30; $i++) {
        $ping = $http($base . '/ping.php');
        if ($ping['status'] === 200) {
            $up = true;
            break;
        }
        usleep(100000);
    }
    sales_role_assert($up, 'page server answers ping');
    $login = $http($base . '/login.php');
    $token = '';
    if (preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $match) === 1) {
        $token = $match[1];
    }
    $signed = $http($base . '/login.php', ['csrf_token' => $token, 'code' => $code, 'next' => '/sales_home.php']);
    sales_role_assert($signed['status'] === 302 && strpos($signed['location'], 'sales_home.php') !== false, 'sales login redirects to Sales Home');
    $home = $http($base . '/sales_home.php');
    sales_role_assert($home['status'] === 200 && strpos($home['body'], 'data-sales-home="1"') !== false, 'Sales Home renders');
    sales_role_assert(strpos($home['body'], 'leads.php?view=prospects') !== false, 'Sales Home nav includes Prospects');
    $index = $http($base . '/index.php');
    sales_role_assert($index['status'] === 302 && strpos($index['location'], 'sales_home.php') !== false, 'index redirects sales to Sales Home');
    $leads = $http($base . '/leads.php');
    sales_role_assert($leads['status'] === 302 && strpos($leads['location'], 'view=leads') !== false, 'bare leads list opens the Leads view');
    $leadsView = $http($base . '/leads.php?view=leads');
    sales_role_assert($leadsView['status'] === 200 && strpos($leadsView['body'], 'Possible duplicate') !== false, 'Leads shows the duplicate flag');
    $prospects = $http($base . '/leads.php?view=prospects');
    sales_role_assert($prospects['status'] === 200 && strpos($prospects['body'], 'Prospects') !== false, 'Prospects view renders');
    $follow = $http($base . '/leads.php?view=followups');
    sales_role_assert($follow['status'] === 200 && strpos($follow['body'], 'Overdue') !== false && strpos($follow['body'], 'Upcoming') !== false, 'Follow-ups shows the three buckets');
    $won = $http($base . '/leads.php?view=won');
    sales_role_assert($won['status'] === 200 && strpos($won['body'], 'value="save"') === false, 'Won view has no save form for sales');
    $customers = $http($base . '/customers.php');
    sales_role_assert($customers['status'] === 200 && strpos($customers['body'], 'customers.sales_readonly') === false, 'customers lookup renders the read only notice');
    sales_role_assert(strpos($customers['body'], 'name="default_pan_dulce_price"') === false, 'customers lookup has no price field');
    $sampleCustomerId = (int)$db->query('SELECT id FROM customers ORDER BY id ASC LIMIT 1')->fetchColumn();
    $record = $http($base . '/customer_record.php' . ($sampleCustomerId > 0 ? '?customer_id=' . $sampleCustomerId : ''));
    sales_role_assert($record['status'] === 200 && strpos($record['body'], 'data-sales-readonly="1"') !== false, 'customer record is read only');
    sales_role_assert(strpos($record['body'], 'Pricing tier') === false && strpos($record['body'], 'billing_center.php') === false, 'customer record hides prices and billing');
    $zones = $http($base . '/zones.php');
    sales_role_assert($zones['status'] === 200 && strpos($zones['body'], 'zones.sales_readonly') === false, 'zones page is readable');
    $blocked = $http($base . '/billing_center.php');
    sales_role_assert($blocked['status'] === 302 && strpos($blocked['location'], 'sales_home.php') !== false, 'billing redirects sales home');
    $users = $http($base . '/users.php');
    sales_role_assert($users['status'] === 302 && strpos($users['location'], 'sales_home.php') !== false, 'sales cannot open Staff');

    $allowed = [
        'sales_home.php' => true,
        'leads.php' => true,
        'zones.php' => true,
        'customers.php' => true,
        'customer_record.php' => true,
    ];
    $publicOk = array_fill_keys(bakery_public_scripts(), true);
    $walkFailed = [];
    foreach (glob($root . '/*.php') ?: [] as $path) {
        $script = basename($path);
        if ($script === 'logout.php' || $script === 'ping.php') {
            continue;
        }
        $src = (string)file_get_contents($path);
        $loadsDb = strpos($src, 'database.php') !== false;
        $skipsGate = strpos($src, 'BAKERY_SKIP_REQUEST_SECURITY') !== false;
        if (!$loadsDb || isset($publicOk[$script]) || $skipsGate) {
            continue;
        }
        $response = $http($base . '/' . $script);
        $location = $response['location'];
        $allowedHit = isset($allowed[$script]) && (
            $response['status'] === 200
            || ($response['status'] >= 300 && $response['status'] < 400 && preg_match('/sales_home\.php|leads\.php|zones\.php|customers\.php|customer_record\.php/', $location) === 1)
        );
        $deniedHit = !isset($allowed[$script]) && (
            $response['status'] === 403
            || ($response['status'] >= 300 && $response['status'] < 400 && strpos($location, 'sales_home.php') !== false)
        );
        if (!$allowedHit && !$deniedHit) {
            $walkFailed[] = $script . ':' . $response['status'] . ':' . $location;
        }
    }
    sales_role_assert($walkFailed === [], 'every other staff page is denied' . ($walkFailed ? ' (' . implode(', ', array_slice($walkFailed, 0, 12)) . ')' : ''));
    $probe = $http($base . '/ping.php?probe=1');
    sales_role_assert(
        $probe['status'] === 403 || ($probe['status'] >= 300 && $probe['status'] < 400 && strpos($probe['location'], 'sales_home.php') !== false),
        'ping probe is denied for sales'
    );
    $logout = $http($base . '/logout.php');
    sales_role_assert($logout['status'] === 302 || $logout['status'] === 200, 'logout still responds');

    proc_terminate($proc);
    proc_close($proc);
}

$db->prepare('DELETE FROM lead_contacts WHERE lead_id IN (SELECT id FROM leads WHERE external_id LIKE ?)')->execute([$marker . '%']);
$db->prepare('DELETE FROM lead_activity WHERE lead_id IN (SELECT id FROM leads WHERE external_id LIKE ?)')->execute([$marker . '%']);
$db->prepare('DELETE FROM leads WHERE external_id LIKE ? OR customer_name = ?')->execute([$marker . '%', '3rd Cousin']);
if ($salesUserId > 0) {
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$salesUserId]);
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
