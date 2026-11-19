<?php
/**
 * Bake-day crew: suggestions from punches, per-day pins, hours from the clock.
 * Usage: php tests/run_production_day_crew_tests.php
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
require_once $root . '/includes/schema_sql.php';
require_once $root . '/includes/production_day_crew.php';

$db = check_mysql_connection();
bakery_assert_local_test_target($db);

$passed = 0;
$failed = 0;

function crew_assert($ok, $message) {
    global $passed, $failed;
    if ($ok) {
        echo "PASS  $message\n";
        $passed++;
        return;
    }
    fwrite(STDERR, "FAIL  $message\n");
    $failed++;
}

function crew_apply_sql(PDO $db, string $path): void {
    foreach (bakery_parse_sql_file($path) as $statement) {
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

crew_apply_sql($db, $root . '/database/schema/002_auth.sql');
crew_apply_sql($db, $root . '/database/schema/007_baker_role.sql');
crew_apply_sql($db, $root . '/database/schema/091_time_clock_punches.sql');
crew_apply_sql($db, $root . '/database/schema/116_production_day_crew.sql');

function crew_user(PDO $db, string $email, string $name, string $roleSlug, string $code): int {
    $roleId = (int)$db->query('SELECT id FROM roles WHERE slug = ' . $db->quote($roleSlug) . ' LIMIT 1')->fetchColumn();
    crew_assert($roleId > 0, "role {$roleSlug} exists");
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

$friday = new DateTimeImmutable('today');
while ($friday->format('N') !== '5') {
    $friday = $friday->modify('-1 day');
}
$bakeDate = $friday->format('Y-m-d');
$bakerId = crew_user($db, 'pmd-crew-baker@sourflour.local', 'Crew Baker', 'baker', '7711');
$rareId = crew_user($db, 'pmd-crew-rare@sourflour.local', 'Rare Baker', 'baker', '7712');
$driverId = crew_user($db, 'pmd-crew-driver@sourflour.local', 'Crew Driver', 'driver', '7713');
$packerId = crew_user($db, 'pmd-crew-packer@sourflour.local', 'Crew Packer', 'cashier', '7714');
$ids = [$bakerId, $rareId, $driverId, $packerId];
$ph = implode(',', array_fill(0, count($ids), '?'));
$db->prepare("DELETE FROM time_clock_punches WHERE user_id IN ($ph)")->execute($ids);
if (table_exists($db, 'production_day_crew')) {
    $db->prepare("DELETE FROM production_day_crew WHERE user_id IN ($ph)")->execute($ids);
}
if (table_exists($db, 'production_work_leads')) {
    $db->prepare("DELETE FROM production_work_leads WHERE user_id IN ($ph)")->execute($ids);
}

$insertPunch = $db->prepare(
    'INSERT INTO time_clock_punches (user_id, clock_in_at, clock_out_at) VALUES (?, ?, ?)'
);
for ($i = 1; $i <= 8; $i++) {
    $day = $friday->modify('-' . (7 * $i) . ' days')->format('Y-m-d');
    if ($i <= 3) {
        $insertPunch->execute([$bakerId, $day . ' 08:00:00', $day . ' 14:00:00']);
    }
    if ($i <= 2) {
        $insertPunch->execute([$rareId, $day . ' 08:00:00', $day . ' 12:00:00']);
    }
    $insertPunch->execute([$driverId, $day . ' 06:00:00', $day . ' 15:00:00']);
}
$insertPunch->execute([$bakerId, $bakeDate . ' 07:30:00', $bakeDate . ' 13:30:00']);
$insertPunch->execute([$rareId, $bakeDate . ' 00:30:00', null]);
$insertPunch->execute([$driverId, $bakeDate . ' 06:00:00', $bakeDate . ' 10:00:00']);

$suggested = bakery_production_crew_suggest($db, $bakeDate);
crew_assert(isset($suggested[$bakerId]), 'three of eight same weekdays suggests the baker');
crew_assert(!isset($suggested[$rareId]), 'two punches stay off the usual crew');
crew_assert(!isset($suggested[$driverId]), 'a punched driver stays off the strip');

$day = bakery_production_crew_for_day($db, $bakeDate);
$byId = [];
foreach ($day['people'] as $person) {
    $byId[(int)$person['user_id']] = $person;
}
crew_assert(isset($byId[$bakerId]) && !empty($byId[$bakerId]['expected']) && !empty($byId[$bakerId]['punched']), 'suggested baker is expected and in');
crew_assert((int)($byId[$bakerId]['minutes'] ?? 0) === 360, 'closed punch hours come from the clock');
crew_assert(isset($byId[$rareId]) && !empty($byId[$rareId]['extra']) && empty($byId[$rareId]['clock_out_at']), 'open punch shows as in, not on the usual crew');
crew_assert((int)($byId[$rareId]['minutes'] ?? 0) > 0, 'open punch counts hours so far');
crew_assert(!isset($byId[$driverId]), 'today\'s driver punch stays off the crew');

crew_assert(bakery_production_crew_pin($db, $bakeDate, $packerId, 'add', $bakerId), 'manager can add someone for the day');
$pinned = bakery_production_crew_for_day($db, $bakeDate);
$packer = null;
foreach ($pinned['people'] as $person) {
    if ((int)$person['user_id'] === $packerId) {
        $packer = $person;
    }
}
crew_assert(is_array($packer) && !empty($packer['expected']) && empty($packer['punched']), 'pinned person shows as not in until they punch');

crew_assert(bakery_production_crew_pin($db, $bakeDate, $bakerId, 'remove', $bakerId), 'manager can suppress the suggestion');
$removed = bakery_production_crew_for_day($db, $bakeDate);
$bakerRow = null;
foreach ($removed['people'] as $person) {
    if ((int)$person['user_id'] === $bakerId) {
        $bakerRow = $person;
    }
}
crew_assert(is_array($bakerRow) && empty($bakerRow['expected']) && !empty($bakerRow['extra']), 'removed baker who punched still shows the check-in');

$lineId = (int)$db->query("SELECT id FROM product_lines WHERE name = 'Sour Flour' LIMIT 1")->fetchColumn();
if ($lineId <= 0) {
    $db->prepare('INSERT INTO product_lines (name) VALUES (?)')->execute(['Sour Flour']);
    $lineId = (int)$db->lastInsertId();
}
$customerId = (int)$db->query("SELECT id FROM customers WHERE name = 'PMD Lead Victoria 2099' LIMIT 1")->fetchColumn();
if ($customerId <= 0) {
    $db->prepare('INSERT INTO customers (name, is_active) VALUES (?, 1)')->execute(['PMD Lead Victoria 2099']);
    $customerId = (int)$db->lastInsertId();
}
crew_assert(bakery_production_lead_save($db, $bakerId, $lineId, null, null, $bakerId), 'line lead saves');
crew_assert(bakery_production_lead_save($db, $packerId, $lineId, null, $customerId, $bakerId), 'customer lead saves');
crew_assert(bakery_production_lead_save($db, $rareId, null, null, null, $bakerId) === false, 'a lead needs a line, dough, or customer');
$leads = bakery_production_leads($db);
$doughNames = bakery_production_lead_names_for_dough($leads, 0, $lineId);
crew_assert(in_array('Crew Baker', $doughNames, true), 'line lead shows on that line');
crew_assert(!in_array('Crew Packer', $doughNames, true), 'customer lead stays off the dough header');
$destNames = bakery_production_lead_names_for_destination($leads, $customerId, $lineId, 0);
crew_assert(in_array('Crew Packer', $destNames, true), 'customer lead shows on that customer');

$db->prepare("DELETE FROM time_clock_punches WHERE user_id IN ($ph)")->execute($ids);
$db->prepare("DELETE FROM production_day_crew WHERE user_id IN ($ph)")->execute($ids);
$db->prepare("DELETE FROM production_work_leads WHERE user_id IN ($ph)")->execute($ids);
$db->prepare('DELETE FROM customers WHERE name = ?')->execute(['PMD Lead Victoria 2099']);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
