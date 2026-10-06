<?php
/**
 * Staff time clock: one open punch, today's board, cashier home, driver return.
 * Usage: php tests/run_time_clock_tests.php
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
putenv('USE_PROD_DB=false');
$_ENV['USE_PROD_DB'] = 'false';
$_SERVER['USE_PROD_DB'] = 'false';
putenv('DB_NAME=bakerysf_test');
$_ENV['DB_NAME'] = 'bakerysf_test';
$_SERVER['DB_NAME'] = 'bakerysf_test';
putenv('APP_ENV=local');
$_ENV['APP_ENV'] = 'local';
$_SERVER['APP_ENV'] = 'local';

define('ACCESS_ALLOWED', true);
require_once $root . '/includes/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/test_target_guard.php';
require_once $root . '/includes/auth.php';
require_once $root . '/includes/time_clock.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$passed = 0;
$failed = 0;

function tc_assert($ok, $message) {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function tc_apply_sql_file(PDO $db, $path) {
    $sql = (string)file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        if (strpos(ltrim($line), '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    foreach (array_filter(array_map('trim', explode(';', $buf))) as $statement) {
        if ($statement === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate') === false
                && stripos($e->getMessage(), 'already exists') === false) {
                throw $e;
            }
        }
    }
}

$db->exec(
    "CREATE TABLE IF NOT EXISTS drivers (
        id INT NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        PRIMARY KEY (id)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
tc_apply_sql_file($db, $root . '/database/schema/002_auth.sql');
tc_apply_sql_file($db, $root . '/database/schema/007_baker_role.sql');
tc_apply_sql_file($db, $root . '/database/schema/074_cashier_shop_photos.sql');
tc_apply_sql_file($db, $root . '/database/schema/083_time_clock_punches.sql');
tc_apply_sql_file($db, $root . '/database/schema/086_time_clock_overrides.sql');

function tc_user(PDO $db, string $email, string $name, string $roleSlug, string $code): int {
    $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = " . $db->quote($roleSlug) . " LIMIT 1")->fetchColumn();
    tc_assert($roleId > 0, "role {$roleSlug} exists");
    $existing = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([$email]);
    $id = (int)$existing->fetchColumn();
    if ($id > 0) {
        $db->prepare('UPDATE users SET display_name = ?, role_id = ?, login_code = ?, is_active = 1 WHERE id = ?')
            ->execute([$name, $roleId, $code, $id]);
        return $id;
    }
    $db->prepare(
        'INSERT INTO users (email, password_hash, login_code, display_name, role_id, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    )->execute([$email, password_hash('unused', PASSWORD_DEFAULT), $code, $name, $roleId]);
    return (int)$db->lastInsertId();
}

$cashierId = tc_user($db, 'clock-cashier@sourflour.local', 'Clock Cashier', 'cashier', '6161');
$managerId = tc_user($db, 'clock-manager@sourflour.local', 'Clock Manager', 'manager', '6162');
$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?)')->execute([$cashierId, $managerId]);

tc_assert(bakery_time_clock_ready($db), 'time clock table is ready');
tc_assert(bakery_time_clock_open_punch($db, $cashierId) === null, 'cashier starts with no open punch');

$in = bakery_time_clock_in($db, $cashierId);
tc_assert(!empty($in['ok']), 'cashier clocks in');
$open = bakery_time_clock_open_punch($db, $cashierId);
tc_assert(is_array($open) && (int)$open['user_id'] === $cashierId, 'open punch belongs to the cashier');
tc_assert(!empty($open['clock_in_at']) && empty($open['clock_out_at']), 'open punch has an in time and no out time');

$again = bakery_time_clock_in($db, $cashierId);
tc_assert(empty($again['ok']) && ($again['error'] ?? '') === 'already_in', 'second clock in is refused');
$count = $db->prepare('SELECT COUNT(*) FROM time_clock_punches WHERE user_id = ? AND clock_out_at IS NULL');
$count->execute([$cashierId]);
tc_assert((int)$count->fetchColumn() === 1, 'only one open punch per person');

$out = bakery_time_clock_out($db, $cashierId);
tc_assert(!empty($out['ok']), 'cashier clocks out');
tc_assert(bakery_time_clock_open_punch($db, $cashierId) === null, 'clock out closes the punch');
$closed = $db->prepare('SELECT clock_out_at FROM time_clock_punches WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$closed->execute([$cashierId]);
tc_assert((string)$closed->fetchColumn() !== '', 'closed punch stores clock out');

$missing = bakery_time_clock_out($db, $cashierId);
tc_assert(empty($missing['ok']) && ($missing['error'] ?? '') === 'not_in', 'clock out with no open punch is refused');

$other = bakery_time_clock_in($db, $managerId);
tc_assert(!empty($other['ok']), 'manager clocks in independently');
tc_assert(bakery_time_clock_open_punch($db, $cashierId) === null, 'manager punch does not open the cashier');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$cashierId]);
$yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));
$db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at) VALUES (?, ?)')->execute([$cashierId, $yesterday]);
$who = bakery_time_clock_who_is_in($db);
$whoById = [];
foreach ($who as $row) {
    $whoById[(int)$row['user_id']] = $row;
}
tc_assert(($whoById[$cashierId]['role_slug'] ?? '') === 'cashier', 'who is in includes the cashier role');
$whoIds = array_map(static function ($row) {
    return (int)$row['user_id'];
}, $who);
tc_assert(in_array($cashierId, $whoIds, true) && in_array($managerId, $whoIds, true), 'who is in lists every open punch');
$names = [];
foreach ($who as $row) {
    $names[(int)$row['user_id']] = (string)$row['display_name'];
}
tc_assert(($names[$cashierId] ?? '') === 'Clock Cashier', 'who is in includes the display name');

$todayRows = bakery_time_clock_punches_for_day($db, date('Y-m-d'));
$todayUsers = array_map(static function ($row) {
    return (int)$row['user_id'];
}, $todayRows);
tc_assert(in_array($managerId, $todayUsers, true), 'today includes punches that started today');
tc_assert(!in_array($cashierId, $todayUsers, true), 'today omits a punch that started yesterday');

bakery_time_clock_out($db, $cashierId);
$still = bakery_time_clock_who_is_in($db);
$stillIds = array_map(static function ($row) {
    return (int)$row['user_id'];
}, $still);
tc_assert(!in_array($cashierId, $stillIds, true) && in_array($managerId, $stillIds, true), 'clock out drops only that person from who is in');

$badDay = bakery_time_clock_punches_for_day($db, 'not-a-date');
tc_assert($badDay === [], 'invalid day returns no punches');

tc_assert(bakery_role_home('cashier') === 'time_clock.php', 'cashier home is the time clock');
tc_assert(bakery_role_home('driver') === 'driver.php', 'driver home stays the route');
tc_assert(bakery_role_home('driver_assistant') === 'driver.php', 'driver assistant home stays the route');
tc_assert(bakery_ops_index_bypass_home('cashier') === 'time_clock.php', 'cashiers hitting the ops dashboard land on the time clock');
tc_assert(in_array('time_clock.php', bakery_cashier_scripts(), true), 'time clock is a cashier script');
tc_assert(in_array('time_clock.php', bakery_driver_scripts(), true), 'time clock is a driver script');
tc_assert(in_array('time_clock.php', bakery_navigation_scripts_for_role('driver_assistant'), true), 'driver assistant may open the time clock');
tc_assert(in_array('cashier', bakery_navigation_roles_for_script('time_clock.php'), true), 'cashier role may open the time clock');
tc_assert(in_array('manager', bakery_navigation_roles_for_script('time_clock.php'), true), 'manager role may open the time clock');
tc_assert(in_array('driver', bakery_navigation_roles_for_script('time_clock.php'), true), 'driver role may open the time clock');
tc_assert(in_array('driver_assistant', bakery_navigation_roles_for_script('time_clock.php'), true), 'driver assistant role may open the time clock');

$driverId = tc_user($db, 'clock-driver@sourflour.test', 'Clock Driver', 'driver', '6163');
$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$driverId]);
$driverIn = bakery_time_clock_in($db, $driverId);
tc_assert(!empty($driverIn['ok']), 'driver can clock in');
$whoRoles = [];
foreach (bakery_time_clock_who_is_in($db) as $row) {
    $whoRoles[(int)$row['user_id']] = (string)($row['role_slug'] ?? '');
}
tc_assert(($whoRoles[$driverId] ?? '') === 'driver', 'who is in includes the driver role');
bakery_time_clock_out($db, $driverId);

$_SESSION['user_id'] = $driverId;
$_SESSION['user_role_slug'] = 'driver';
tc_assert(bakery_time_clock_safe_return('/driver.php?date=2026-09-21') === '/driver.php?date=2026-09-21', 'driver may return to the dated route');
tc_assert(bakery_time_clock_safe_return('/bake/driver.php?date=2026-09-21&stop=4') === '/bake/driver.php?date=2026-09-21&stop=4', 'driver may return under the app prefix');
tc_assert(bakery_time_clock_safe_return('https://evil.example/driver.php') === '', 'external return is rejected');
tc_assert(bakery_time_clock_safe_return('//evil.example/driver.php') === '', 'protocol-relative return is rejected');
tc_assert(bakery_time_clock_safe_return('/users.php') === '', 'driver cannot return to user management');
tc_assert(bakery_time_clock_safe_return("/driver.php\nLocation: https://evil.example") === '', 'return header injection is rejected');
$_SESSION['user_role_slug'] = 'cashier';
tc_assert(bakery_time_clock_safe_return('/driver.php') === '', 'cashier cannot return to the driver route');
$_SESSION = [];

tc_assert(bakery_time_clock_week_monday('2026-10-07') === '2026-10-05', 'a Wednesday snaps to that Monday');
tc_assert(bakery_time_clock_overrides_ready($db), 'override columns are ready');
require_once $root . '/includes/hosted_migration_runtime.php';
[$overrideSqlSafe] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/086_time_clock_overrides.sql'));
tc_assert($overrideSqlSafe, 'override migration is hosted-gate safe');

$monday = bakery_time_clock_week_monday('2026-10-07');
$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$cashierId]);
$db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at, clock_out_at) VALUES (?, ?, ?)')
    ->execute([$cashierId, $monday . ' 08:00:00', $monday . ' 16:00:00']);
$longIn = $monday . ' 06:00:00';
$longOut = date('Y-m-d H:i:s', strtotime($longIn . ' +13 hours'));
$db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at, clock_out_at) VALUES (?, ?, ?)')
    ->execute([$managerId, $longIn, $longOut]);
$earlier = date('Y-m-d H:i:s', strtotime($monday . ' -2 days 09:00:00'));
$db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at) VALUES (?, ?)')
    ->execute([$driverId, $earlier]);
$week = bakery_time_clock_week($db, $monday, strtotime($monday . ' 18:00:00'));
$byUser = [];
foreach ($week['people'] as $person) {
    $byUser[(int)$person['user_id']] = $person;
}
tc_assert(($byUser[$cashierId]['days'][$monday] ?? 0) === 480, 'an 8 hour day counts on the clock-in day');
tc_assert(($byUser[$cashierId]['minutes'] ?? 0) === 480, 'weekly total adds that day');
tc_assert(($byUser[$cashierId]['other'] ?? []) !== [], 'a normal day stays out of the flag list');
tc_assert(($byUser[$managerId]['flagged'][0]['flags'] ?? []) === ['long'], 'a 13 hour punch is flagged as long');
tc_assert(($byUser[$driverId]['minutes'] ?? 0) === 0, 'an open punch from before the week is not added to this week');
tc_assert(($byUser[$driverId]['earlier'][0]['flags'][0] ?? '') === 'no_out', 'an old open punch is flagged as missing a clock out');
$overnightIn = date('Y-m-d H:i:s', strtotime($monday . ' +1 day 20:00:00'));
$overnightOut = date('Y-m-d H:i:s', strtotime($monday . ' +2 days 04:00:00'));
tc_assert(bakery_time_clock_flags(['clock_in_at' => $overnightIn, 'clock_out_at' => $overnightOut]) === ['next_day'], 'a next-day clock out is flagged');

$openId = (int)$byUser[$driverId]['earlier'][0]['id'];
$refused = bakery_time_clock_override($db, $cashierId, 'cashier', $openId, $monday . 'T09:00', $monday . 'T17:00', 'forgot');
tc_assert(empty($refused['ok']) && ($refused['error'] ?? '') === 'forbidden', 'a cashier cannot override a punch');
$blank = bakery_time_clock_override($db, $managerId, 'manager', $openId, $monday . 'T09:00', $monday . 'T17:00', '  ');
tc_assert(($blank['error'] ?? '') === 'note_required', 'a correction needs a note');
$backwards = bakery_time_clock_override($db, $managerId, 'manager', $openId, $monday . 'T17:00', $monday . 'T09:00', 'swapped');
tc_assert(($backwards['error'] ?? '') === 'out_before_in', 'clock out before clock in is refused');
$fixed = bakery_time_clock_override($db, $managerId, 'manager', $openId, date('Y-m-d\TH:i', strtotime($earlier)), $monday . 'T17:00', 'Forgot to clock out');
tc_assert(!empty($fixed['ok']), 'a manager can set the missing clock out');
$saved = $db->prepare('SELECT clock_out_at, original_clock_in_at, original_clock_out_at, override_note FROM time_clock_punches WHERE id = ?');
$saved->execute([$openId]);
$savedRow = $saved->fetch(PDO::FETCH_ASSOC);
tc_assert(is_array($savedRow) && $savedRow['clock_out_at'] !== null && $savedRow['original_clock_out_at'] === null, 'the original missing clock out is kept');
tc_assert(($savedRow['original_clock_in_at'] ?? '') === $earlier, 'the original clock in is kept');
tc_assert(bakery_time_clock_open_punch($db, $driverId) === null, 'the override closes the open punch');
$again = bakery_time_clock_in($db, $driverId);
tc_assert(!empty($again['ok']), 'the driver can clock in after the correction');
$second = bakery_time_clock_override($db, $managerId, 'manager', $openId, date('Y-m-d\TH:i', strtotime($earlier)), $monday . 'T16:30', 'Adjusted the out');
tc_assert(!empty($second['ok']), 'a second correction saves');
$saved->execute([$openId]);
$savedAgain = $saved->fetch(PDO::FETCH_ASSOC);
tc_assert(($savedAgain['original_clock_in_at'] ?? '') === $earlier, 'a second correction does not replace the original time');

$page = (string)file_get_contents($root . '/time_clock.php');
tc_assert(strpos($page, 'bakery_require_role') !== false, 'time clock page enforces role');
tc_assert(strpos($page, "'driver'") !== false && strpos($page, "'driver_assistant'") !== false, 'time clock page allows drivers');
tc_assert(strpos($page, 'bakery_require_csrf') !== false, 'time clock page checks CSRF');
tc_assert(strpos($page, 'bakery_time_clock_in') !== false && strpos($page, 'bakery_time_clock_out') !== false, 'page punches through the shared helpers');
tc_assert(strpos($page, 'bakery_time_clock_safe_return') !== false, 'successful punches can return to the working page');
tc_assert(strpos($page, 'bakery_navigation_role_label') !== false, 'board shows the role label');
tc_assert(strpos($page, 'time-clock-week') !== false, 'managers get a weekly view');
tc_assert(strpos($page, "value=\"override\"") !== false, 'managers can post a punch correction');
tc_assert(strpos($page, '!$canSeeBoard') !== false, 'a non-manager override is refused on the page');
tc_assert(strpos($page, "['administrator', 'manager']") !== false || strpos($page, "['manager', 'administrator']") !== false, 'manager board is role gated');
tc_assert(strpos($page, '$_POST[\'user_id\']') === false && strpos($page, '$_POST["user_id"]') === false, 'page does not accept a posted user id');

$nav = (string)file_get_contents($root . '/includes/nav.php');
tc_assert(strpos($nav, 'time_clock.php') !== false, 'navigation links the time clock');
tc_assert(strpos($nav, 'bakery-nav__clock') !== false, 'driver bar includes the clock control');
tc_assert(strpos($nav, 'name="return"') !== false, 'driver punch posts a return path');
tc_assert(strpos($nav, 'isset($db) && $db instanceof PDO') !== false, 'driver clock query is skipped when navigation renders without a database');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?, ?)')->execute([$cashierId, $managerId, $driverId]);

echo $failed === 0
    ? "Time clock tests passed ({$passed})\n"
    : "Time clock tests finished with {$failed} failure(s), {$passed} pass(es)\n";
exit($failed === 0 ? 0 : 1);
