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
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/hosted_migration_runtime.php';

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
tc_apply_sql_file($db, $root . '/database/schema/091_time_clock_punches.sql');
tc_apply_sql_file($db, $root . '/database/schema/092_time_clock_overrides.sql');

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
[$overrideSqlSafe] = bakery_hosted_migration_sql_safe((string)file_get_contents($root . '/database/schema/092_time_clock_overrides.sql'));
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

$punchesSql = (string)file_get_contents($root . '/database/schema/091_time_clock_punches.sql');
$overridesSql = (string)file_get_contents($root . '/database/schema/092_time_clock_overrides.sql');
[$punchesSafe] = bakery_hosted_migration_sql_safe($punchesSql);
[$overridesSafe] = bakery_hosted_migration_sql_safe($overridesSql);
tc_assert($punchesSafe, '091 stays on the hosted additive allow-list');
tc_assert($overridesSafe, '092 stays on the hosted additive allow-list');
tc_assert(substr_count($overridesSql, 'ADD COLUMN') === 5, '092 adds each correction column in its own statement');
tc_assert(substr_count($overridesSql, 'ADD KEY') === 1, '092 adds the correction key in its own statement');
tc_assert(strpos($punchesSql, 'CREATE TABLE IF NOT EXISTS time_clock_punches') !== false, '091 is a no-op when the punch table already exists');

try {
    foreach (bakery_parse_sql_file($root . '/database/schema/091_time_clock_punches.sql') as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
    tc_assert(true, '091 applies again without error when time_clock_punches exists');
} catch (Throwable $e) {
    tc_assert(false, '091 applies again without error when time_clock_punches exists');
}

$db->exec('ALTER TABLE time_clock_punches DROP INDEX idx_time_clock_overridden_by');
foreach ([
    'overridden_at',
    'overridden_by_user_id',
    'override_note',
    'original_clock_out_at',
    'original_clock_in_at',
] as $column) {
    $db->exec('ALTER TABLE time_clock_punches DROP COLUMN ' . $column);
}
$db->exec('ALTER TABLE time_clock_punches ADD COLUMN original_clock_in_at DATETIME NULL DEFAULT NULL');
try {
    foreach (bakery_parse_sql_file($root . '/database/schema/092_time_clock_overrides.sql') as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
    tc_assert(true, '092 resumes after the first correction column already exists');
} catch (Throwable $e) {
    tc_assert(false, '092 resumes after the first correction column already exists (' . $e->getMessage() . ')');
}
$overrideColumns = $db->query("SHOW COLUMNS FROM time_clock_punches")->fetchAll(PDO::FETCH_COLUMN);
foreach (['original_clock_in_at', 'original_clock_out_at', 'override_note', 'overridden_by_user_id', 'overridden_at'] as $column) {
    tc_assert(in_array($column, $overrideColumns, true), "092 leaves {$column} in place");
}
$index = $db->query("SHOW INDEX FROM time_clock_punches WHERE Key_name = 'idx_time_clock_overridden_by'")->fetch(PDO::FETCH_ASSOC);
tc_assert(is_array($index), '092 leaves the correction key in place');
try {
    foreach (bakery_parse_sql_file($root . '/database/schema/092_time_clock_overrides.sql') as $statement) {
        bakery_hosted_migration_exec_statement($db, $statement);
    }
    tc_assert(true, '092 applies again when every correction column already exists');
} catch (Throwable $e) {
    tc_assert(false, '092 applies again when every correction column already exists (' . $e->getMessage() . ')');
}

// Open punches from an earlier day are split out of "Clocked in now".
$splitNow = strtotime('2098-03-18 09:30:00'); // a Tuesday; 03-17 is its Monday, 03-16 the Sunday before
$splitRows = [
    ['id' => 1, 'user_id' => 11, 'clock_in_at' => '2098-03-18 05:00:00', 'display_name' => 'Early', 'role_slug' => 'cashier'],
    ['id' => 2, 'user_id' => 12, 'clock_in_at' => '2098-03-17 14:10:00', 'display_name' => 'Forgot', 'role_slug' => 'driver'],
    ['id' => 3, 'user_id' => 13, 'clock_in_at' => '2098-03-16 22:00:00', 'display_name' => 'Monday night', 'role_slug' => 'baker'],
    ['id' => 4, 'user_id' => 14, 'clock_in_at' => '2098-03-18 00:00:00', 'display_name' => 'Midnight', 'role_slug' => 'baker'],
];
$split = bakery_time_clock_split_open($splitRows, $splitNow);
tc_assert(
    array_column($split['now'], 'user_id') === [11, 14],
    'split: punches that started today stay in Clocked in now (midnight counts as today)'
);
tc_assert(
    array_column($split['earlier'], 'user_id') === [12, 13],
    'split: punches from an earlier day move to Open from an earlier day'
);
tc_assert(
    ($split['earlier'][0]['week_monday'] ?? '') === '2098-03-17' && ($split['earlier'][1]['week_monday'] ?? '') === '2098-03-10',
    'split: earlier punches carry the Monday of their own week for the correction link'
);
tc_assert(
    bakery_time_clock_split_open([], $splitNow) === ['now' => [], 'earlier' => []],
    'split: no open punches gives two empty groups'
);
$flagsForEarlier = bakery_time_clock_flags($splitRows[1] + ['clock_out_at' => null], $splitNow);
tc_assert(in_array('no_out', $flagsForEarlier, true), 'split uses the same earlier-day rule as the weekly no clock out flag');

$tcPage = (string)file_get_contents($root . '/time_clock.php');
tc_assert(
    strpos($tcPage, 'bakery_time_clock_split_open(') !== false
        && strpos($tcPage, 'time_clock.earlier_open_title') !== false
        && strpos($tcPage, "'time_clock.php?week='") !== false,
    'time clock page shows the earlier-day group and links to that week correction form'
);
$tcEn = require $root . '/lang/en.php';
$tcEs = require $root . '/lang/es.php';
foreach (['time_clock.earlier_open_title', 'time_clock.earlier_open_help', 'time_clock.earlier_open_fix'] as $tcKey) {
    tc_assert(isset($tcEn[$tcKey], $tcEs[$tcKey]) && $tcEn[$tcKey] !== $tcEs[$tcKey], "i18n {$tcKey} exists in en and es");
}

echo "\n=== Auto-close stale punches ===\n";

$systemNote = 'system: Auto-closed: no clock out';
$autoUser = tc_user($db, 'clock-auto@sourflour.test', 'Clock Auto', 'driver', '6164');
$autoOther = tc_user($db, 'clock-auto-other@sourflour.test', 'Clock Auto Other', 'cashier', '6165');
$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?)')->execute([$autoUser, $autoOther]);

$nullEditor = $db->query("SHOW COLUMNS FROM time_clock_punches LIKE 'overridden_by_user_id'")->fetch(PDO::FETCH_ASSOC);
tc_assert(is_array($nullEditor) && strtoupper((string)$nullEditor['Null']) === 'YES', 'a system close can leave the editor empty');

$insertOpen = static function (int $userId, string $inAt) use ($db): int {
    $db->prepare('INSERT INTO time_clock_punches (user_id, clock_in_at) VALUES (?, ?)')->execute([$userId, $inAt]);
    return (int)$db->lastInsertId();
};
$loadPunch = static function (int $id) use ($db): array {
    $stmt = $db->prepare(
        'SELECT clock_in_at, clock_out_at, original_clock_in_at, original_clock_out_at, override_note,
                overridden_by_user_id IS NULL AS system_editor, overridden_at
         FROM time_clock_punches WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
};

$yesterdayNow = strtotime('2026-11-17 10:00:00');
$yesterdayId = $insertOpen($autoUser, '2026-11-16 09:00:00');
$firstClose = bakery_time_clock_autoclose_stale($db, $yesterdayNow, false);
$yesterdayRow = $loadPunch($yesterdayId);
tc_assert(
    ($yesterdayRow['clock_out_at'] ?? null) !== null && ($yesterdayRow['clock_out_at'] ?? '') !== '',
    'an open punch from yesterday gets a clock out'
);
tc_assert(($yesterdayRow['original_clock_in_at'] ?? '') === '2026-11-16 09:00:00', 'the original clock in is preserved');
tc_assert(($yesterdayRow['original_clock_out_at'] ?? null) === null, 'the original missing clock out stays empty');
tc_assert(($yesterdayRow['override_note'] ?? '') === $systemNote, 'the note reads Auto-closed: no clock out and carries system');
tc_assert((int)($yesterdayRow['system_editor'] ?? 0) === 1, 'the system close has no editor user');
$closedIds = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $firstClose['closed'] ?? []);
tc_assert(in_array($yesterdayId, $closedIds, true), 'the close is reported in the result');
$againClose = bakery_time_clock_autoclose_stale($db, $yesterdayNow, false);
$againIds = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $againClose['closed'] ?? []);
$yesterdayAgain = $loadPunch($yesterdayId);
tc_assert(!in_array($yesterdayId, $againIds, true), 'a second run does not close the punch again');
tc_assert($yesterdayAgain === $yesterdayRow, 'a second run changes nothing');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?)')->execute([$autoUser, $autoOther]);
$sameDayNow = strtotime('2026-11-17 16:00:00');
$thirteenId = $insertOpen($autoUser, '2026-11-17 03:00:00');
$fifteenId = $insertOpen($autoOther, '2026-11-17 01:00:00');
$sameDay = bakery_time_clock_autoclose_stale($db, $sameDayNow, false);
$thirteen = $loadPunch($thirteenId);
$fifteen = $loadPunch($fifteenId);
tc_assert(($thirteen['clock_out_at'] ?? null) === null, 'an open punch of 13 hours today is untouched');
tc_assert(($fifteen['clock_out_at'] ?? '') === '2026-11-17 13:00:00', 'an open punch of 15 hours is closed at the cap of 12 hours');
$sameDayClosed = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $sameDay['closed'] ?? []);
tc_assert(!in_array($thirteenId, $sameDayClosed, true) && in_array($fifteenId, $sameDayClosed, true), 'only the 15 hour punch is closed');
$capWeek = bakery_time_clock_week($db, '2026-11-16', $sameDayNow);
$capPerson = null;
foreach ($capWeek['people'] as $person) {
    if ((int)$person['user_id'] === $autoOther) {
        $capPerson = $person;
    }
}
tc_assert(is_array($capPerson) && (int)$capPerson['minutes'] === 720, 'week totals use the closed hours');
tc_assert(($capPerson['days']['2026-11-17'] ?? 0) === 720, 'the closed hours land on the clock-in day');
$capFlags = $capPerson['flagged'][0]['flags'] ?? [];
tc_assert($capFlags === ['auto_closed'], 'the correction card says review instead of missing');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?)')->execute([$autoUser, $autoOther]);
$driverRow = $db->prepare('INSERT INTO drivers (name) VALUES (?)');
$driverRow->execute(['Clock Auto Driver']);
$linkedDriverId = (int)$db->lastInsertId();
$db->prepare('UPDATE users SET driver_id = ? WHERE id = ?')->execute([$linkedDriverId, $autoUser]);
$activityNow = strtotime('2026-11-17 18:00:00');
$activityId = $insertOpen($autoUser, '2026-11-16 08:00:00');
$db->prepare(
    'INSERT INTO driver_photos (driver_id, delivery_date, filename, original_filename, file_path, file_size, mime_type, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$linkedDriverId, '2026-11-16', 'auto-close.jpg', 'auto-close.jpg', 'uploads/auto-close.jpg', 12, 'image/jpeg', '2026-11-16 16:47:00']);
bakery_time_clock_autoclose_stale($db, $activityNow, false);
$activity = $loadPunch($activityId);
tc_assert(($activity['clock_out_at'] ?? '') === '2026-11-16 17:02:00', 'driver activity at 4:47 PM closes the punch at 5:02 PM');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$autoOther]);
$quietNow = strtotime('2026-11-17 08:00:00');
$quietId = $insertOpen($autoOther, '2026-11-16 22:00:00');
bakery_time_clock_autoclose_stale($db, $quietNow, false);
$quiet = $loadPunch($quietId);
tc_assert(($quiet['clock_out_at'] ?? '') === '2026-11-17 06:00:00', 'with no activity the punch closes at clock-in plus 8 hours');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$autoUser]);
$blockedIn = '2026-11-16 09:30:00';
$blockedId = $insertOpen($autoUser, $blockedIn);
$stillOpen = bakery_time_clock_who_is_in($db);
$stillOpenIds = array_map(static function ($row) {
    return (int)$row['user_id'];
}, $stillOpen);
tc_assert(in_array($autoUser, $stillOpenIds, true), 'reading who is in does not close a stale punch');
$clockIn = bakery_time_clock_in($db, $autoUser);
tc_assert(!empty($clockIn['ok']) && ($clockIn['error'] ?? '') !== 'already_in', 'clock in closes the stale punch and creates the new punch');
$blocked = $loadPunch($blockedId);
tc_assert(($blocked['override_note'] ?? '') === $systemNote && ($blocked['clock_out_at'] ?? '') !== '', 'the punch closed by clock in carries the system note');
tc_assert(bakery_time_clock_open_punch($db, $autoUser) !== null, 'the new punch is open');

$managerFix = bakery_time_clock_override(
    $db,
    $managerId,
    'manager',
    $blockedId,
    '2026-11-16T09:30',
    '2026-11-16T15:00',
    'Real out was 3'
);
tc_assert(!empty($managerFix['ok']), 'a manager override after an auto-close still works');
$afterManager = $loadPunch($blockedId);
tc_assert(($afterManager['original_clock_in_at'] ?? '') === $blockedIn, 'the manager override keeps the first original clock in');
tc_assert(($afterManager['original_clock_out_at'] ?? null) === null, 'the manager override keeps the first original missing clock out');
tc_assert(($afterManager['clock_out_at'] ?? '') === '2026-11-16 15:00:00', 'the manager out replaces the estimated out');
tc_assert(($afterManager['override_note'] ?? '') === 'Real out was 3', 'the manager note replaces the system note');

$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$autoOther]);
$ancientNow = strtotime('2026-11-17 12:00:00');
$ancientId = $insertOpen($autoOther, date('Y-m-d H:i:s', $ancientNow - 8 * 86400));
$held = bakery_time_clock_autoclose_stale($db, $ancientNow, false);
$heldIds = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $held['reported'] ?? []);
$ancient = $loadPunch($ancientId);
tc_assert(($ancient['clock_out_at'] ?? null) === null, 'without backfill a punch older than 7 days is not closed');
tc_assert(in_array($ancientId, $heldIds, true), 'without backfill a punch older than 7 days is reported');
$filled = bakery_time_clock_autoclose_stale($db, $ancientNow, true);
$filledIds = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $filled['closed'] ?? []);
$ancientClosed = $loadPunch($ancientId);
tc_assert(in_array($ancientId, $filledIds, true) && ($ancientClosed['override_note'] ?? '') === $systemNote, 'backfill closes the old punch with the system note');

$deliveryCustomer = $db->prepare('INSERT INTO customers (name) VALUES (?)');
$deliveryCustomer->execute(['Clock Auto Store']);
$deliveryCustomerId = (int)$db->lastInsertId();
$deliveryOrder = $db->prepare('INSERT INTO daily_orders (customer_id, order_date, status) VALUES (?, ?, ?)');
$deliveryOrder->execute([$deliveryCustomerId, '2026-11-15', 'pending']);
$deliveryOrderId = (int)$db->lastInsertId();
$db->prepare(
    'INSERT INTO daily_order_assignments (daily_order_id, driver_id, delivery_date, route_order, actual_delivery_time, delivery_status)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$deliveryOrderId, $linkedDriverId, '2026-11-15', 77, '15:10:00', 'delivered']);
$db->prepare('DELETE FROM time_clock_punches WHERE user_id = ?')->execute([$autoUser]);
$deliveryPunch = $insertOpen($autoUser, '2026-11-15 08:00:00');
bakery_time_clock_autoclose_stale($db, strtotime('2026-11-16 12:00:00'), false);
$deliveryClosed = $loadPunch($deliveryPunch);
tc_assert(($deliveryClosed['clock_out_at'] ?? '') === '2026-11-15 15:25:00', 'a delivery time closes the punch 15 minutes later');
$db->prepare('DELETE FROM daily_order_assignments WHERE daily_order_id = ?')->execute([$deliveryOrderId]);
$db->prepare('DELETE FROM daily_orders WHERE id = ?')->execute([$deliveryOrderId]);
$db->prepare('DELETE FROM customers WHERE id = ?')->execute([$deliveryCustomerId]);
$db->prepare('DELETE FROM driver_photos WHERE driver_id = ? AND filename = ?')->execute([$linkedDriverId, 'auto-close.jpg']);
$db->prepare('DELETE FROM time_clock_punches WHERE user_id IN (?, ?)')->execute([$autoUser, $autoOther]);
$db->prepare('UPDATE users SET driver_id = NULL WHERE id = ?')->execute([$autoUser]);
$db->prepare('DELETE FROM drivers WHERE id = ?')->execute([$linkedDriverId]);

$scheduler = (string)file_get_contents($root . '/scripts/demand_scheduler.php');
tc_assert(strpos($scheduler, "in_array('--backfill', \$argv, true)") !== false, 'the nightly script keeps backfill off unless asked');
tc_assert(strpos($scheduler, 'bakery_time_clock_autoclose_stale($db, null, $backfill)') !== false, 'the nightly script closes stale punches');
$generation = (string)file_get_contents($root . '/includes/daily_order_generation.php');
$stalePos = strpos($generation, 'function bakery_demand_self_heal_if_stale');
$dispatchPos = strpos($generation, 'function bakery_demand_self_heal_dispatch');
$beaconPos = strpos($generation, 'function bakery_demand_self_heal_render_beacon');
$staleBody = substr($generation, $stalePos, $dispatchPos - $stalePos);
$beaconBody = substr($generation, $beaconPos);
tc_assert(strpos($staleBody, 'bakery_time_clock_autoclose_stale(') !== false, 'the demand self-heal closes stale punches');
tc_assert(strpos($beaconBody, 'bakery_time_clock_autoclose_stale') === false, 'the page beacon does not close punches');
tc_assert(strpos($tcPage, 'bakery_time_clock_autoclose_stale') === false, 'the time clock page does not close punches on render');
tc_assert(strpos($tcPage, "case 'auto_closed':") !== false, 'the weekly card has a review flag');
tc_assert(strpos($tcPage, 'time_clock.system_note') !== false, 'the weekly card shows the system note');
tc_assert(($tcEn['time_clock.flag_auto_closed'] ?? '') === 'review', 'English review label');
tc_assert(($tcEs['time_clock.flag_auto_closed'] ?? '') === 'revisar', 'Spanish review label');
tc_assert(($tcEn['time_clock.system_note'] ?? '') === 'system', 'English system note');
tc_assert(($tcEs['time_clock.system_note'] ?? '') === 'sistema', 'Spanish system note');
tc_assert(strpos((string)file_get_contents($root . '/lang/en.php'), "\u{2013}") === false, 'English copy has no en dash');
tc_assert(strpos((string)file_get_contents($root . '/lang/es.php'), "\u{2014}") === false, 'Spanish copy has no em dash');

echo $failed === 0
    ? "Time clock tests passed ({$passed})\n"
    : "Time clock tests finished with {$failed} failure(s), {$passed} pass(es)\n";
exit($failed === 0 ? 0 : 1);
